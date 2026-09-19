//! Conservative MySQL AST compiler. Unsupported syntax never passes through.
use sqlparser::{
    ast::*,
    dialect::MySqlDialect,
    parser::Parser,
    tokenizer::{Token, Tokenizer, Whitespace},
};
use std::collections::BTreeMap;

pub type Result<T> = std::result::Result<T, String>;
#[derive(Clone, Debug)]
pub struct Shape {
    pub key: String,
    pub values: Vec<String>,
    pub numeric: Vec<bool>,
    pub tokens: Vec<Token>,
    pub statement: Statement,
    pub mode: String,
    pub codec: bool,
    pub metadata: Option<crate::metadata_syntax::Command>,
}
#[derive(Clone, Debug)]
pub struct Column {
    pub name: String,
    pub ty: String,
    pub identity: bool,
    pub nullable: bool,
    pub default: Option<String>,
    pub ordinal: i16,
}
#[derive(Clone, Debug, Default)]
pub struct Index {
    pub columns: Vec<String>,
    pub primary: bool,
    pub unique: bool,
}
#[derive(Clone, Debug, Default)]
pub struct Table {
    pub columns: Vec<Column>,
    pub keys: Vec<Index>,
    pub oid: u32,
}
impl std::ops::Deref for Table {
    type Target = Vec<Column>;
    fn deref(&self) -> &Self::Target {
        &self.columns
    }
}
impl<'a> IntoIterator for &'a Table {
    type Item = &'a Column;
    type IntoIter = std::slice::Iter<'a, Column>;
    fn into_iter(self) -> Self::IntoIter {
        self.columns.iter()
    }
}
impl From<Vec<Column>> for Table {
    fn from(columns: Vec<Column>) -> Self {
        Self {
            columns,
            keys: vec![],
            oid: 0,
        }
    }
}
pub type Schema = BTreeMap<String, Table>;
#[derive(Clone, Debug)]
pub struct Binding {
    pub slot: usize,
    pub temporal: bool,
    pub codec: bool,
    pub allow_nul: bool,
    pub format: bool,
    pub numeric_prefix: bool,
}
#[derive(Clone, Debug)]
pub struct Plan {
    pub sql: String,
    pub bindings: Vec<Binding>,
    pub operation: &'static str,
    pub identity: bool,
    pub cacheable: bool,
    pub leading: Vec<Command>,
    pub count: Option<Command>,
}
#[derive(Clone, Debug)]
pub struct Command {
    pub sql: String,
    pub bindings: Vec<Binding>,
}

pub fn shape(sql: &str) -> Result<Shape> {
    shape_mode(sql, "", false)
}
pub fn shape_mode(sql: &str, mode: &str, codec: bool) -> Result<Shape> {
    use std::ops::ControlFlow;
    if sql.len() > 16_777_216 {
        return Err("SQL exceeds native input limit".into());
    }
    let dialect = crate::dialect::MysqlMode::new(mode)?;
    let mut tokens = Tokenizer::new(&dialect, sql)
        .tokenize()
        .map_err(|_| "Invalid MySQL tokens")?;
    for token in &mut tokens {
        match token {
            Token::Whitespace(Whitespace::MultiLineComment(s))
                if s.starts_with('!') || s.starts_with('+') =>
            {
                return Err("Executable comments and optimizer hints are unsupported".into());
            }
            Token::Whitespace(Whitespace::MultiLineComment(_))
            | Token::Whitespace(Whitespace::SingleLineComment { .. }) => {
                *token = Token::Whitespace(Whitespace::Space)
            }
            Token::Placeholder(_) => return Err("Pass complete MySQL SQL, not placeholders".into()),
            Token::StringConcat if !dialect.has("PIPES_AS_CONCAT") => {
                *token = Token::make_keyword("OR")
            }
            _ => {}
        }
    }
    if let Some(command) = crate::metadata_syntax::parse(&tokens, &dialect)? {
        return Ok(Shape {
            key: format!("metadata:{}", command.kind),
            values: vec![],
            numeric: vec![],
            tokens: vec![],
            statement: Statement::ShowVariables {
                filter: None,
                global: false,
                session: false,
            },
            mode: dialect.modes.join(","),
            codec,
            metadata: Some(command),
        });
    }
    let mut statements = Parser::new(&dialect)
        .with_tokens(tokens)
        .parse_statements()
        .map_err(|_| "Unsupported or invalid MySQL syntax")?;
    if statements.len() != 1 {
        return Err("Exactly one SQL statement is required".into());
    }
    let mut statement = statements.remove(0);
    struct Binder {
        values: Vec<String>,
        numeric: Vec<bool>,
    }
    impl VisitorMut for Binder {
        type Break = String;
        fn pre_visit_ident(&mut self, id: &mut Ident) -> ControlFlow<String> {
            if id.value.contains('\u{1f}') {
                ControlFlow::Break("Reserved control byte in SQL identifier".into())
            } else {
                ControlFlow::Continue(())
            }
        }
        fn pre_visit_value(&mut self, v: &mut ValueWithSpan) -> ControlFlow<String> {
            let literal = match &v.value {
                Value::SingleQuotedString(s)
                | Value::DoubleQuotedString(s)
                | Value::NationalStringLiteral(s) => Some((s.clone(), false)),
                Value::Number(s, _) => Some((s.clone(), true)),
                Value::Null | Value::Boolean(_) => None,
                _ => return ControlFlow::Break("Unsupported literal form".into()),
            };
            if let Some((value, number)) = literal {
                self.values.push(value);
                self.numeric.push(number);
                v.value = Value::Placeholder(format!("${}", self.values.len()));
            }
            ControlFlow::Continue(())
        }
    }
    let mut binder = Binder {
        values: vec![],
        numeric: vec![],
    };
    if let ControlFlow::Break(e) = VisitMut::visit(&mut statement, &mut binder) {
        return Err(e);
    }
    let values = binder.values;
    let numeric = binder.numeric;
    let key = statement.to_string();
    let tokens = Tokenizer::new(&MySqlDialect {}, &key)
        .tokenize()
        .map_err(|_| "Invalid normalized AST")?;
    Ok(Shape {
        key,
        values,
        numeric,
        tokens,
        statement,
        mode: dialect.modes.join(","),
        codec,
        metadata: None,
    })
}
pub fn parse(s: &Shape) -> Result<Statement> {
    Ok(s.statement.clone())
}
pub fn tables(s: &Statement) -> Result<Vec<String>> {
    let mut out = vec![];
    let result = visit_relations(s, |n| match name(n) {
        Ok(n) => {
            if n.eq_ignore_ascii_case("dual") {
                return std::ops::ControlFlow::Continue(());
            }
            if !out.contains(&n) {
                out.push(n);
            }
            std::ops::ControlFlow::Continue(())
        }
        Err(e) => std::ops::ControlFlow::Break(e),
    });
    if let std::ops::ControlFlow::Break(e) = result {
        return Err(e);
    }
    Ok(out)
}
pub fn qi(s: &str) -> String {
    format!("\"{}\"", s.replace('"', "\"\""))
}
pub(crate) fn name(n: &ObjectName) -> Result<String> {
    if n.0.len() != 1 {
        return Err("Qualified application table names are unsupported".into());
    }
    n.0[0]
        .as_ident()
        .map(|x| x.value.clone())
        .ok_or("Unsupported object name".into())
}
pub(crate) fn check(ok: bool) -> Result<()> {
    if ok {
        Ok(())
    } else {
        Err("Unsupported SQL construct".into())
    }
}
pub fn compile(stmt: &Statement, shape: &Shape, schema: &Schema) -> Result<Plan> {
    if shape.metadata.is_some() {
        return Err("Metadata requires native catalog dispatch".into());
    }
    let mut c = Compiler {
        shape,
        schema,
        aliases: BTreeMap::new(),
        bindings: vec![],
        cacheable: true,
        write_column: None,
        leading: vec![],
        upsert: false,
        local_tables: Default::default(),
    };
    let (sql, operation, identity) = match stmt {
        Statement::Query(q) => {
            if let SetExpr::Select(s) = &*q.body
                && s.projection.len() == 1
                && s.from.is_empty()
            {
                let e = match &s.projection[0] {
                    SelectItem::UnnamedExpr(e) | SelectItem::ExprWithAlias { expr: e, .. } => {
                        Some(e)
                    }
                    _ => None,
                };
                if matches!(e,Some(Expr::Function(f)) if f.name.to_string().eq_ignore_ascii_case("FOUND_ROWS"))
                {
                    return Ok(Plan {
                        sql: String::new(),
                        bindings: vec![],
                        operation: "FOUND_ROWS",
                        identity: false,
                        cacheable: true,
                        leading: vec![],
                        count: None,
                    });
                }
            }
            (c.query(q)?, "SELECT", false)
        }
        Statement::Insert(i) => {
            let (sql, id) = c.insert(i)?;
            (sql, if i.replace_into { "REPLACE" } else { "INSERT" }, id)
        }
        Statement::Update(u) => (c.update(u)?, "UPDATE", false),
        Statement::Delete(d) => (c.delete(d)?, "DELETE", false),
        _ => return Err("Only SELECT, INSERT VALUES, UPDATE and DELETE are compiled".into()),
    };
    let mut count = None;
    if let Statement::Query(q) = stmt
        && let SetExpr::Select(s) = &*q.body
        && s.select_modifiers
            .as_ref()
            .is_some_and(|m| m.sql_calc_found_rows)
    {
        let regular = std::mem::take(&mut c.bindings);
        let mut unlimited = q.clone();
        unlimited.limit_clause = None;
        let count_sql = c.query(&unlimited)?;
        count = Some(Command {
            sql: format!("SELECT COUNT(*) FROM ({count_sql}) AS dsql_found_rows"),
            bindings: std::mem::replace(&mut c.bindings, regular),
        });
    }
    Ok(Plan {
        sql,
        bindings: c.bindings,
        operation,
        identity,
        cacheable: c.cacheable,
        leading: c.leading,
        count,
    })
}
pub(crate) struct Compiler<'a> {
    pub(crate) shape: &'a Shape,
    pub(crate) schema: &'a Schema,
    pub(crate) aliases: BTreeMap<String, String>,
    pub(crate) bindings: Vec<Binding>,
    pub(crate) cacheable: bool,
    pub(crate) write_column: Option<(String, String)>,
    pub(crate) leading: Vec<Command>,
    pub(crate) upsert: bool,
    pub(crate) local_tables: std::collections::BTreeSet<String>,
}
impl Compiler<'_> {
    pub(crate) fn table(&mut self, t: &TableFactor) -> Result<String> {
        match t {
            TableFactor::Table {
                name: n,
                alias,
                args,
                with_hints,
                version,
                with_ordinality,
                partitions,
                json_path,
                sample,
                index_hints,
            } => {
                check(
                    args.is_none()
                        && with_hints.is_empty()
                        && version.is_none()
                        && !*with_ordinality
                        && partitions.is_empty()
                        && json_path.is_none()
                        && sample.is_none()
                        && index_hints.is_empty(),
                )?;
                let n = name(n)?;
                check(self.schema.contains_key(&n))?;
                self.aliases.insert(n.clone(), n.clone());
                if let Some(a) = alias {
                    check(a.columns.is_empty())?;
                    self.aliases.insert(a.name.value.clone(), n.clone());
                    Ok(format!("{} AS {}", qi(&n), qi(&a.name.value)))
                } else {
                    Ok(qi(&n))
                }
            }
            TableFactor::Derived {
                lateral,
                subquery,
                alias,
                sample,
            } => {
                check(!*lateral && sample.is_none())?;
                let alias = alias.as_ref().ok_or("Derived table requires alias")?;
                check(alias.columns.is_empty())?;
                Ok(format!(
                    "({}) AS {}",
                    self.query(subquery)?,
                    qi(&alias.name.value)
                ))
            }
            TableFactor::NestedJoin {
                table_with_joins,
                alias,
            } => {
                check(alias.is_none())?;
                Ok(format!("({})", self.from(table_with_joins)?))
            }
            _ => Err("Unsupported table expression".into()),
        }
    }
    pub(crate) fn from(&mut self, t: &TableWithJoins) -> Result<String> {
        let mut s = self.table(&t.relation)?;
        for j in &t.joins {
            check(!j.global)?;
            let (op, condition) = match &j.join_operator {
                JoinOperator::Join(c) | JoinOperator::Inner(c) => ("INNER JOIN", Some(c)),
                JoinOperator::Left(c) | JoinOperator::LeftOuter(c) => ("LEFT JOIN", Some(c)),
                JoinOperator::Right(c) | JoinOperator::RightOuter(c) => ("RIGHT JOIN", Some(c)),
                JoinOperator::CrossJoin(c) => ("CROSS JOIN", Some(c)),
                _ => return Err("Unsupported join".into()),
            };
            let rhs = self.table(&j.relation)?;
            let constraint = match condition {
                Some(JoinConstraint::On(e)) => format!(" ON {}", self.truth(e)?),
                Some(JoinConstraint::Using(cols)) => format!(
                    " USING ({})",
                    cols.iter()
                        .map(|n| name(n).map(|n| qi(&n)))
                        .collect::<Result<Vec<_>>>()?
                        .join(",")
                ),
                Some(JoinConstraint::None) | None => String::new(),
                _ => return Err("Unsupported join constraint".into()),
            };
            s.push_str(&format!(" {op} {rhs}{constraint}"));
        }
        Ok(s)
    }
    pub(crate) fn column(&self, ids: &[Ident]) -> Result<(String, String)> {
        check(!ids.is_empty() && ids.len() <= 2)?;
        let field = &ids.last().unwrap().value;
        let mut matches = vec![];
        for (alias, table) in &self.aliases {
            if ids.len() == 1 && !self.local_tables.is_empty() && !self.local_tables.contains(table)
            {
                continue;
            }
            if ids.len() == 2 && alias != &ids[0].value {
                continue;
            }
            if let Some(cols) = self.schema.get(table) {
                for col in cols {
                    if col.name.eq_ignore_ascii_case(field) {
                        let sql = if ids.len() == 2 {
                            format!("{}.{}", qi(alias), qi(&col.name))
                        } else if self.upsert {
                            format!("{}.{}", qi(table), qi(&col.name))
                        } else {
                            qi(&col.name)
                        };
                        if !matches.contains(&(sql.clone(), col.ty.clone())) {
                            matches.push((sql, col.ty.clone()));
                        }
                    }
                }
            }
        }
        if matches.len() == 1 {
            Ok(matches.remove(0))
        } else {
            Ok((
                ids.iter()
                    .map(|i| qi(&i.value))
                    .collect::<Vec<_>>()
                    .join("."),
                String::new(),
            ))
        }
    }
    pub(crate) fn ty(&self, e: &Expr) -> Option<String> {
        match e {
            Expr::Identifier(i) => self
                .column(std::slice::from_ref(i))
                .ok()
                .map(|x| x.1)
                .filter(|t| !t.is_empty()),
            Expr::CompoundIdentifier(i) => {
                self.column(i).ok().map(|x| x.1).filter(|t| !t.is_empty())
            }
            _ => None,
        }
    }
    pub(crate) fn expr(&mut self, e: &Expr, expected: Option<&str>) -> Result<String> {
        Ok(match e {
            Expr::Identifier(i)
                if i.quote_style.is_none()
                    && [
                        "CURRENT_USER",
                        "SESSION_USER",
                        "CURRENT_DATE",
                        "CURRENT_TIME",
                        "CURRENT_TIMESTAMP",
                        "LOCALTIME",
                        "LOCALTIMESTAMP",
                    ]
                    .contains(&i.value.to_ascii_uppercase().as_str()) =>
            {
                i.value.to_ascii_uppercase()
            }
            Expr::Identifier(i) => self.column(std::slice::from_ref(i))?.0,
            Expr::CompoundIdentifier(i) => self.column(i)?.0,
            Expr::Value(v) => match &v.value {
                Value::Placeholder(p) => {
                    let slot = p
                        .strip_prefix('$')
                        .and_then(|x| x.parse::<usize>().ok())
                        .and_then(|x| x.checked_sub(1))
                        .ok_or("Invalid parameter")?;
                    let num = *self.shape.numeric.get(slot).ok_or("Invalid parameter")?;
                    if num && expected.is_none() {
                        return Ok(format!("\u{1f}N{slot}\u{1f}"));
                    }
                    let ty = expected.unwrap_or(if num { "numeric" } else { "text" });
                    let ty = match ty {
                        "bigint"
                        | "integer"
                        | "smallint"
                        | "numeric"
                        | "real"
                        | "double precision"
                        | "boolean"
                        | "date"
                        | "timestamp without time zone"
                        | "timestamp with time zone" => ty,
                        "text" | "character varying" | "character" => "text",
                        _ => return Err("Unsupported parameter type".into()),
                    };
                    self.bindings.push(Binding {
                        slot,
                        temporal: ty == "date" || ty.starts_with("timestamp"),
                        codec: self.shape.codec && !(ty == "date" || ty.starts_with("timestamp")),
                        allow_nul: self.write_column.as_ref().is_some_and(|(_, t)| t == "text"),
                        format: false,
                        numeric_prefix: false,
                    });
                    format!("CAST(${} AS {})", self.bindings.len(), ty)
                }
                Value::Null => "NULL".into(),
                Value::Boolean(b) => if *b { "TRUE" } else { "FALSE" }.into(),
                _ => return Err("Unbound literal".into()),
            },
            Expr::Nested(x) => format!("({})", self.expr(x, expected)?),
            Expr::BinaryOp { left, op, right } => self.binary(left, op, right)?,
            Expr::UnaryOp { op, expr } => match op {
                UnaryOperator::Not => format!("(NOT {})", self.truth(expr)?),
                UnaryOperator::Plus | UnaryOperator::Minus | UnaryOperator::BitwiseNot => {
                    format!("({op}{})", self.numeric(expr)?)
                }
                _ => return Err("Unsupported unary operation".into()),
            },
            Expr::IsNull(x) => format!("({} IS NULL)", self.expr(x, None)?),
            Expr::IsNotNull(x) => format!("({} IS NOT NULL)", self.expr(x, None)?),
            Expr::InList {
                expr,
                list,
                negated,
            } => {
                check(!list.is_empty())?;
                if Self::is_binary(expr) {
                    let left = self.bytes(expr)?;
                    let rhs = list
                        .iter()
                        .map(|e| self.bytes(e))
                        .collect::<Result<Vec<_>>>()?
                        .join(",");
                    format!("({left} {}IN ({rhs}))", if *negated { "NOT " } else { "" })
                } else {
                    let ty = self.ty(expr);
                    format!(
                        "({} {}IN ({}))",
                        self.expr(expr, None)?,
                        if *negated { "NOT " } else { "" },
                        self.list(list, ty.as_deref())?
                    )
                }
            }
            Expr::Between {
                expr,
                low,
                high,
                negated,
            } => {
                if Self::is_binary(expr) {
                    format!(
                        "({} {}BETWEEN {} AND {})",
                        self.bytes(expr)?,
                        if *negated { "NOT " } else { "" },
                        self.bytes(low)?,
                        self.bytes(high)?
                    )
                } else {
                    let ty = self.ty(expr);
                    format!(
                        "({} {}BETWEEN {} AND {})",
                        self.expr(expr, None)?,
                        if *negated { "NOT " } else { "" },
                        self.expr(low, ty.as_deref())?,
                        self.expr(high, ty.as_deref())?
                    )
                }
            }
            Expr::Like {
                expr,
                pattern,
                negated,
                any,
                escape_char,
            } => {
                check(!*any)?;
                let bytes = Self::is_binary(expr) || Self::is_binary(pattern);
                if bytes {
                    check(escape_char.is_none())?;
                    format!(
                        "({} {}LIKE {})",
                        self.bytes(expr)?,
                        if *negated { "NOT " } else { "" },
                        self.bytes(pattern)?
                    )
                } else {
                    format!(
                        "({} {}ILIKE {}{})",
                        self.expr(expr, None)?,
                        if *negated { "NOT " } else { "" },
                        self.expr(pattern, Some("text"))?,
                        if let Some(e) = escape_char {
                            format!(" ESCAPE {}", self.expr(e, Some("text"))?)
                        } else {
                            String::new()
                        }
                    )
                }
            }
            Expr::RLike {
                expr,
                pattern,
                negated,
                ..
            } => format!(
                "({} {} {})",
                self.expr(Self::unbinary(expr), None)?,
                if *negated { "!~" } else { "~" },
                self.expr(Self::unbinary(pattern), Some("text"))?
            ),
            Expr::Cast {
                expr,
                data_type,
                format,
                ..
            } => {
                check(format.is_none())?;
                self.cast(expr, data_type)?
            }
            Expr::Exists { subquery, negated } => format!(
                "({}EXISTS ({}))",
                if *negated { "NOT " } else { "" },
                self.query(subquery)?
            ),
            Expr::Subquery(q) => format!("({})", self.query(q)?),
            Expr::InSubquery {
                expr,
                subquery,
                negated,
            } => format!(
                "({} {}IN ({}))",
                self.expr(expr, None)?,
                if *negated { "NOT " } else { "" },
                self.query(subquery)?
            ),
            Expr::IsTrue(e) => format!("({} IS TRUE)", self.truth(e)?),
            Expr::IsFalse(e) => format!("({} IS FALSE)", self.truth(e)?),
            Expr::IsNotTrue(e) => format!("({} IS NOT TRUE)", self.truth(e)?),
            Expr::IsNotFalse(e) => format!("({} IS NOT FALSE)", self.truth(e)?),
            Expr::Case {
                operand,
                conditions,
                else_result,
                ..
            } => {
                let mut out = String::from("CASE");
                if let Some(e) = operand {
                    out.push_str(&format!(" {}", self.expr(e, None)?));
                }
                for c in conditions {
                    out.push_str(&format!(
                        " WHEN {} THEN {}",
                        if operand.is_some() {
                            self.expr(&c.condition, None)?
                        } else {
                            self.truth(&c.condition)?
                        },
                        self.expr(&c.result, None)?
                    ));
                }
                if let Some(e) = else_result {
                    out.push_str(&format!(" ELSE {}", self.expr(e, None)?));
                }
                out.push_str(" END");
                out
            }
            Expr::Substring {
                expr,
                substring_from,
                substring_for,
                ..
            } => {
                let mut args = vec![self.expr(expr, None)?];
                if let Some(e) = substring_from {
                    args.push(self.expr(e, Some("integer"))?);
                }
                if let Some(e) = substring_for {
                    args.push(self.expr(e, Some("integer"))?);
                }
                format!("SUBSTRING({})", args.join(","))
            }
            Expr::Trim {
                expr,
                trim_where,
                trim_what,
                trim_characters,
            } => {
                check(trim_where.is_none() && trim_what.is_none() && trim_characters.is_none())?;
                format!("TRIM({})", self.expr(expr, None)?)
            }
            Expr::Function(f) => self.function(f)?,
            _ => return Err("Unsupported expression".into()),
        })
    }
    pub(crate) fn list(&mut self, es: &[Expr], ty: Option<&str>) -> Result<String> {
        es.iter()
            .map(|e| self.expr(e, ty))
            .collect::<Result<Vec<_>>>()
            .map(|s| s.join(", "))
    }
    pub(crate) fn target(&mut self, n: &ObjectName) -> Result<String> {
        let n = name(n)?;
        check(self.schema.contains_key(&n))?;
        self.aliases.insert(n.clone(), n.clone());
        Ok(n)
    }
}
