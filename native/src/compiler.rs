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
}
#[derive(Clone, Debug)]
pub struct Column {
    pub name: String,
    pub ty: String,
    pub identity: bool,
}
pub type Schema = BTreeMap<String, Vec<Column>>;
#[derive(Clone, Debug)]
pub struct Binding {
    pub slot: usize,
    pub temporal: bool,
}
#[derive(Clone, Debug)]
pub struct Plan {
    pub sql: String,
    pub bindings: Vec<Binding>,
    pub operation: &'static str,
    pub identity: bool,
}

pub fn shape(sql: &str) -> Result<Shape> {
    if sql.len() > 1_048_576 {
        return Err("SQL exceeds prototype limit".into());
    }
    let tokens = Tokenizer::new(&MySqlDialect {}, sql)
        .tokenize()
        .map_err(|_| "Invalid MySQL tokens")?;
    let mut out = Shape {
        key: String::new(),
        values: vec![],
        numeric: vec![],
        tokens: vec![],
    };
    for mut t in tokens {
        // Comments are not retained in cross-request cache keys.
        match &t {
            Token::Whitespace(Whitespace::MultiLineComment(s))
                if s.starts_with('!') || s.starts_with('+') =>
            {
                return Err("Executable comments and optimizer hints are unsupported".into());
            }
            Token::Whitespace(Whitespace::MultiLineComment(_))
            | Token::Whitespace(Whitespace::SingleLineComment { .. }) => {
                t = Token::Whitespace(Whitespace::Space)
            }
            _ => {}
        }
        let literal = match &t {
            Token::SingleQuotedString(s) | Token::DoubleQuotedString(s) => Some((s.clone(), false)),
            Token::Number(s, _) => Some((s.clone(), true)),
            Token::Placeholder(_) => return Err("Pass complete MySQL SQL, not placeholders".into()),
            Token::Whitespace(Whitespace::MultiLineComment(s))
                if s.starts_with('!') || s.starts_with('+') =>
            {
                return Err("Executable comments and optimizer hints are unsupported".into());
            }
            _ => None,
        };
        if let Some((v, n)) = literal {
            if v.contains('\0') {
                return Err("NUL codec is outside this prototype".into());
            }
            out.values.push(v);
            out.numeric.push(n);
            let token = Token::Placeholder(format!("${}", out.values.len()));
            out.key.push_str(&token.to_string());
            out.tokens.push(token);
        } else {
            out.key.push_str(&t.to_string());
            out.tokens.push(t);
        }
    }
    // Kind is part of the key: '1' and 1 must never share incompatible casts.
    Ok(out)
}
pub fn parse(s: &Shape) -> Result<Statement> {
    let mut statements = Parser::new(&MySqlDialect {})
        .with_tokens(s.tokens.clone())
        .parse_statements()
        .map_err(|_| "Unsupported or invalid MySQL syntax")?;
    if statements.len() != 1 {
        return Err("Exactly one SQL statement is required".into());
    }
    Ok(statements.remove(0))
}
pub fn tables(s: &Statement) -> Result<Vec<String>> {
    let mut out = vec![];
    let result = visit_relations(s, |n| match name(n) {
        Ok(n) => {
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
fn name(n: &ObjectName) -> Result<String> {
    if n.0.len() != 1 {
        return Err("Qualified names are outside the prototype".into());
    }
    n.0[0]
        .as_ident()
        .map(|x| x.value.clone())
        .ok_or("Unsupported object name".into())
}
fn check(ok: bool) -> Result<()> {
    if ok {
        Ok(())
    } else {
        Err("SQL construct is outside the native prototype".into())
    }
}
pub fn compile(stmt: &Statement, shape: &Shape, schema: &Schema) -> Result<Plan> {
    let mut c = Compiler {
        shape,
        schema,
        aliases: BTreeMap::new(),
        bindings: vec![],
    };
    let (sql, operation, identity) = match stmt {
        Statement::Query(q) => (c.query(q)?, "SELECT", false),
        Statement::Insert(i) => {
            let (sql, id) = c.insert(i)?;
            (sql, "INSERT", id)
        }
        Statement::Update(u) => (c.update(u)?, "UPDATE", false),
        Statement::Delete(d) => (c.delete(d)?, "DELETE", false),
        _ => return Err("Only SELECT, INSERT VALUES, UPDATE and DELETE are compiled".into()),
    };
    Ok(Plan {
        sql,
        bindings: c.bindings,
        operation,
        identity,
    })
}
struct Compiler<'a> {
    shape: &'a Shape,
    schema: &'a Schema,
    aliases: BTreeMap<String, String>,
    bindings: Vec<Binding>,
}
impl Compiler<'_> {
    fn table(&mut self, t: &TableFactor) -> Result<String> {
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
            _ => Err("Unsupported table expression".into()),
        }
    }
    fn from(&mut self, t: &TableWithJoins) -> Result<String> {
        let mut s = self.table(&t.relation)?;
        for j in &t.joins {
            check(!j.global)?;
            let (op, condition) = match &j.join_operator {
                JoinOperator::Inner(c) => ("INNER JOIN", c),
                JoinOperator::LeftOuter(c) => ("LEFT JOIN", c),
                _ => return Err("Unsupported join".into()),
            };
            let rhs = self.table(&j.relation)?;
            let on = match condition {
                JoinConstraint::On(e) => self.expr(e, None)?,
                _ => return Err("JOIN requires ON".into()),
            };
            s.push_str(&format!(" {op} {rhs} ON {on}"));
        }
        Ok(s)
    }
    fn column(&self, ids: &[Ident]) -> Result<(String, String)> {
        check(!ids.is_empty() && ids.len() <= 2)?;
        let field = &ids.last().unwrap().value;
        let mut matches = vec![];
        for (alias, table) in &self.aliases {
            if ids.len() == 2 && alias != &ids[0].value {
                continue;
            }
            if let Some(cols) = self.schema.get(table) {
                for col in cols {
                    if col.name.eq_ignore_ascii_case(field) {
                        let sql = if ids.len() == 2 {
                            format!("{}.{}", qi(alias), qi(&col.name))
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
            Err("Unknown or ambiguous column".into())
        }
    }
    fn ty(&self, e: &Expr) -> Option<String> {
        match e {
            Expr::Identifier(i) => self.column(std::slice::from_ref(i)).ok().map(|x| x.1),
            Expr::CompoundIdentifier(i) => self.column(i).ok().map(|x| x.1),
            _ => None,
        }
    }
    fn expr(&mut self, e: &Expr, expected: Option<&str>) -> Result<String> {
        Ok(match e {
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
                    let ty = expected.unwrap_or(if num { "numeric" } else { "text" });
                    if let Some(t) = expected {
                        let numeric_type = matches!(
                            t,
                            "bigint"
                                | "integer"
                                | "smallint"
                                | "numeric"
                                | "real"
                                | "double precision"
                        );
                        let text_type = matches!(t, "text" | "character varying" | "character");
                        check(!(numeric_type && !num || text_type && num))?;
                    }
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
                    });
                    format!("CAST(${} AS {})", self.bindings.len(), ty)
                }
                Value::Null => "NULL".into(),
                Value::Boolean(b) => if *b { "TRUE" } else { "FALSE" }.into(),
                _ => return Err("Unbound literal".into()),
            },
            Expr::Nested(x) => format!("({})", self.expr(x, expected)?),
            Expr::BinaryOp { left, op, right } => {
                let op = match op {
                    BinaryOperator::Eq => "=",
                    BinaryOperator::NotEq => "<>",
                    BinaryOperator::Gt => ">",
                    BinaryOperator::Lt => "<",
                    BinaryOperator::GtEq => ">=",
                    BinaryOperator::LtEq => "<=",
                    BinaryOperator::Spaceship => "IS NOT DISTINCT FROM",
                    BinaryOperator::And => "AND",
                    BinaryOperator::Or => "OR",
                    BinaryOperator::Plus => "+",
                    BinaryOperator::Minus => "-",
                    BinaryOperator::Multiply => "*",
                    _ => return Err("Unsupported binary operator".into()),
                };
                let lt = self.ty(left);
                let rt = self.ty(right);
                if let (Some(l), Some(r)) = (&lt, &rt) {
                    let numeric = |t: &str| {
                        matches!(
                            t,
                            "bigint"
                                | "integer"
                                | "smallint"
                                | "numeric"
                                | "real"
                                | "double precision"
                        )
                    };
                    check(numeric(l) == numeric(r))?;
                }
                format!(
                    "({} {op} {})",
                    self.expr(left, rt.as_deref())?,
                    self.expr(right, lt.as_deref())?
                )
            }
            Expr::UnaryOp { op, expr } => {
                check(matches!(
                    op,
                    UnaryOperator::Not | UnaryOperator::Minus | UnaryOperator::Plus
                ))?;
                format!("({op} {})", self.expr(expr, expected)?)
            }
            Expr::IsNull(x) => format!("({} IS NULL)", self.expr(x, None)?),
            Expr::IsNotNull(x) => format!("({} IS NOT NULL)", self.expr(x, None)?),
            Expr::InList {
                expr,
                list,
                negated,
            } => {
                check(!list.is_empty())?;
                let ty = self.ty(expr);
                format!(
                    "({} {}IN ({}))",
                    self.expr(expr, None)?,
                    if *negated { "NOT " } else { "" },
                    self.list(list, ty.as_deref())?
                )
            }
            Expr::Between {
                expr,
                low,
                high,
                negated,
            } => {
                let ty = self.ty(expr);
                format!(
                    "({} {}BETWEEN {} AND {})",
                    self.expr(expr, None)?,
                    if *negated { "NOT " } else { "" },
                    self.expr(low, ty.as_deref())?,
                    self.expr(high, ty.as_deref())?
                )
            }
            Expr::Like {
                expr,
                pattern,
                negated,
                any,
                escape_char,
            } => {
                check(!*any && escape_char.is_none())?;
                format!(
                    "({} {}LIKE {})",
                    self.expr(expr, None)?,
                    if *negated { "NOT " } else { "" },
                    self.expr(pattern, Some("text"))?
                )
            }
            Expr::Function(f) => self.function(f)?,
            _ => return Err("Unsupported expression".into()),
        })
    }
    fn list(&mut self, es: &[Expr], ty: Option<&str>) -> Result<String> {
        es.iter()
            .map(|e| self.expr(e, ty))
            .collect::<Result<Vec<_>>>()
            .map(|s| s.join(", "))
    }
    fn function(&mut self, f: &Function) -> Result<String> {
        check(
            !f.uses_odbc_syntax
                && f.filter.is_none()
                && f.over.is_none()
                && f.within_group.is_empty()
                && f.null_treatment.is_none()
                && matches!(f.parameters, FunctionArguments::None),
        )?;
        let n = name(&f.name)?.to_ascii_uppercase();
        let a = match &f.args {
            FunctionArguments::List(a) => a,
            _ => return Err("Unsupported function arguments".into()),
        };
        check(a.clauses.is_empty() && a.duplicate_treatment.is_none())?;
        let mut args = vec![];
        for arg in &a.args {
            args.push(match arg {
                FunctionArg::Unnamed(FunctionArgExpr::Expr(e)) => self.expr(e, None)?,
                FunctionArg::Unnamed(FunctionArgExpr::Wildcard) if n == "COUNT" => "*".into(),
                _ => return Err("Unsupported function argument".into()),
            });
        }
        let mapped = match (n.as_str(), args.len()) {
            ("IFNULL", 2) => "COALESCE",
            ("LENGTH", 1) => "OCTET_LENGTH",
            ("COALESCE", n) if n > 0 => "COALESCE",
            ("COUNT" | "SUM" | "MIN" | "MAX" | "AVG" | "LOWER" | "UPPER" | "CHAR_LENGTH", 1) => {
                n.as_str()
            }
            ("CONCAT", n) if n > 0 => return Ok(format!("({})", args.join(" || "))),
            _ => return Err("Unsupported function".into()),
        };
        Ok(format!("{mapped}({})", args.join(", ")))
    }
    fn query(&mut self, q: &Query) -> Result<String> {
        check(
            q.with.is_none()
                && q.fetch.is_none()
                && q.locks.is_empty()
                && q.for_clause.is_none()
                && q.settings.is_none()
                && q.format_clause.is_none()
                && q.pipe_operators.is_empty(),
        )?;
        let s = match &*q.body {
            SetExpr::Select(s) => s,
            _ => return Err("Only SELECT query bodies are supported".into()),
        };
        check(
            s.optimizer_hints.is_empty()
                && s.select_modifiers.is_none()
                && s.top.is_none()
                && s.exclude.is_none()
                && s.into.is_none()
                && s.lateral_views.is_empty()
                && s.prewhere.is_none()
                && s.connect_by.is_empty()
                && s.cluster_by.is_empty()
                && s.distribute_by.is_empty()
                && s.sort_by.is_empty()
                && s.named_window.is_empty()
                && s.qualify.is_none()
                && s.value_table_mode.is_none()
                && matches!(s.flavor, SelectFlavor::Standard),
        )?;
        let from = s
            .from
            .iter()
            .map(|t| self.from(t))
            .collect::<Result<Vec<_>>>()?
            .join(", ");
        let mut projection = vec![];
        for p in &s.projection {
            projection.push(match p {
                SelectItem::UnnamedExpr(e) => self.expr(e, None)?,
                SelectItem::ExprWithAlias { expr, alias } => {
                    format!("{} AS {}", self.expr(expr, None)?, qi(&alias.value))
                }
                SelectItem::Wildcard(o) if o.to_string().is_empty() => "*".into(),
                _ => return Err("Unsupported projection".into()),
            });
        }
        let distinct = match &s.distinct {
            None => "",
            Some(Distinct::Distinct) => "DISTINCT ",
            _ => return Err("Unsupported DISTINCT".into()),
        };
        let mut sql = format!("SELECT {distinct}{}", projection.join(", "));
        if !from.is_empty() {
            sql.push_str(&format!(" FROM {from}"));
        }
        if let Some(e) = &s.selection {
            sql.push_str(&format!(" WHERE {}", self.expr(e, None)?));
        }
        match &s.group_by {
            GroupByExpr::Expressions(es, mods) if mods.is_empty() => {
                check(!es.iter().any(|e| matches!(e, Expr::Value(_))))?;
                if !es.is_empty() {
                    sql.push_str(&format!(" GROUP BY {}", self.list(es, None)?));
                }
            }
            _ => return Err("Unsupported GROUP BY".into()),
        }
        if let Some(e) = &s.having {
            sql.push_str(&format!(" HAVING {}", self.expr(e, None)?));
        }
        if let Some(o) = &q.order_by {
            check(o.interpolate.is_none())?;
            let es = match &o.kind {
                OrderByKind::Expressions(es) => es,
                _ => return Err("Unsupported ORDER BY".into()),
            };
            let mut rendered = vec![];
            for e in es {
                check(e.with_fill.is_none())?;
                // Reject ordinal ordering until plans model structural literal slots.
                check(!matches!(e.expr, Expr::Value(_)))?;
                let mut item = self.expr(&e.expr, None)?;
                match e.options.sort {
                    Some(OrderBySort::Asc) => item.push_str(" ASC NULLS FIRST"),
                    Some(OrderBySort::Desc) => item.push_str(" DESC NULLS LAST"),
                    None => item.push_str(" ASC NULLS FIRST"),
                    _ => return Err("Unsupported ordering".into()),
                };
                check(e.options.nulls_first.is_none())?;
                rendered.push(item);
            }
            sql.push_str(&format!(" ORDER BY {}", rendered.join(", ")));
        }
        if let Some(l) = &q.limit_clause {
            match l {
                LimitClause::OffsetCommaLimit { offset, limit } => sql.push_str(&format!(
                    " LIMIT {} OFFSET {}",
                    self.expr(limit, Some("bigint"))?,
                    self.expr(offset, Some("bigint"))?
                )),
                LimitClause::LimitOffset {
                    limit,
                    offset,
                    limit_by,
                } => {
                    check(limit_by.is_empty())?;
                    if let Some(l) = limit {
                        sql.push_str(&format!(" LIMIT {}", self.expr(l, Some("bigint"))?));
                    }
                    if let Some(o) = offset {
                        sql.push_str(&format!(" OFFSET {}", self.expr(&o.value, Some("bigint"))?));
                    }
                }
            }
        }
        Ok(sql)
    }
    fn target(&mut self, n: &ObjectName) -> Result<String> {
        let n = name(n)?;
        check(self.schema.contains_key(&n))?;
        self.aliases.insert(n.clone(), n.clone());
        Ok(n)
    }
    fn insert(&mut self, i: &Insert) -> Result<(String, bool)> {
        check(
            i.optimizer_hints.is_empty()
                && i.or.is_none()
                && !i.ignore
                && i.table_alias.is_none()
                && !i.overwrite
                && i.assignments.is_empty()
                && i.partitioned.is_none()
                && i.after_columns.is_empty()
                && i.on.is_none()
                && i.returning.is_none()
                && i.output.is_none()
                && !i.replace_into
                && i.priority.is_none()
                && i.insert_alias.is_none()
                && i.settings.is_none()
                && i.format_clause.is_none()
                && i.multi_table_insert_type.is_none()
                && i.multi_table_into_clauses.is_empty()
                && i.multi_table_when_clauses.is_empty()
                && i.multi_table_else_clause.is_none(),
        )?;
        let n = match &i.table {
            TableObject::TableName(n) => self.target(n)?,
            _ => return Err("Unsupported INSERT target".into()),
        };
        check(!i.columns.is_empty())?;
        let cols = i.columns.iter().map(name).collect::<Result<Vec<_>>>()?;
        let meta = self.schema.get(&n).unwrap().clone();
        let columns = cols
            .iter()
            .map(|n| {
                meta.iter()
                    .find(|c| c.name.eq_ignore_ascii_case(n))
                    .cloned()
                    .ok_or("Unknown INSERT column".into())
            })
            .collect::<Result<Vec<_>>>()?;
        check(columns.iter().all(|c| !c.identity))?; // Explicit identities require a separate reseeding contract.
        let q = i.source.as_ref().ok_or("INSERT VALUES required")?;
        check(
            q.with.is_none()
                && q.order_by.is_none()
                && q.limit_clause.is_none()
                && q.locks.is_empty()
                && q.fetch.is_none()
                && q.for_clause.is_none()
                && q.settings.is_none()
                && q.format_clause.is_none()
                && q.pipe_operators.is_empty(),
        )?;
        let rows = match &*q.body {
            SetExpr::Values(v) => &v.rows,
            _ => return Err("INSERT SELECT is outside this prototype".into()),
        };
        let mut values = vec![];
        for row in rows {
            check(row.len() == columns.len())?;
            let mut r = vec![];
            for (v, c) in row.iter().zip(&columns) {
                r.push(self.expr(v, Some(&c.ty))?);
            }
            values.push(format!("({})", r.join(", ")));
        }
        let mut sql = format!(
            "INSERT INTO {} ({}) VALUES {}",
            qi(&n),
            columns
                .iter()
                .map(|c| qi(&c.name))
                .collect::<Vec<_>>()
                .join(", "),
            values.join(", ")
        );
        let identity = meta.iter().find(|c| c.identity);
        if let Some(c) = identity {
            sql.push_str(&format!(" RETURNING {}", qi(&c.name)));
        }
        Ok((sql, identity.is_some()))
    }
    fn update(&mut self, u: &Update) -> Result<String> {
        check(
            u.optimizer_hints.is_empty()
                && u.table.joins.is_empty()
                && u.from.is_none()
                && u.returning.is_none()
                && u.output.is_none()
                && u.or.is_none()
                && u.order_by.is_empty()
                && u.limit.is_none()
                && u.assignments.len() == 1,
        )?;
        let table = self.table(&u.table.relation)?;
        let a = &u.assignments[0];
        let n = match &a.target {
            AssignmentTarget::ColumnName(n) => name(n)?,
            _ => return Err("Unsupported assignment".into()),
        };
        let (col, ty) = self.column(&[Ident::new(n)])?;
        let value = self.expr(&a.value, Some(&ty))?;
        let mut sql = format!("UPDATE {table} SET {col} = {value}");
        if let Some(e) = &u.selection {
            sql.push_str(&format!(" WHERE {}", self.expr(e, None)?));
        }
        Ok(sql)
    }
    fn delete(&mut self, d: &Delete) -> Result<String> {
        check(
            d.optimizer_hints.is_empty()
                && d.tables.is_empty()
                && d.using.is_none()
                && d.returning.is_none()
                && d.output.is_none()
                && d.order_by.is_empty()
                && d.limit.is_none(),
        )?;
        let from = match &d.from {
            FromTable::WithFromKeyword(f) | FromTable::WithoutKeyword(f) => f,
        };
        check(from.len() == 1 && from[0].joins.is_empty())?;
        let mut sql = format!("DELETE FROM {}", self.table(&from[0].relation)?);
        if let Some(e) = &d.selection {
            sql.push_str(&format!(" WHERE {}", self.expr(e, None)?));
        }
        Ok(sql)
    }
}
