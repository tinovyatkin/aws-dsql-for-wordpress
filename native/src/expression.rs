//! MySQL expression semantics mirrored from the PHP adapter's renderer.
use crate::compiler::{Compiler, Result, check, name};
use regex::Regex;
use sqlparser::ast::*;
use std::sync::OnceLock;
const NUMBER_PATTERN: &str = "^[[:space:]]*([+-]?([0-9]+([.][0-9]*)?|[.][0-9]+)([eE][+-]?[0-9]+)?)";
pub fn numeric_prefix(value: &str) -> String {
    static PATTERN: OnceLock<Regex> = OnceLock::new();
    PATTERN
        .get_or_init(|| {
            Regex::new(
                r"^[\t\n\r\x0b\x0c ]*[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?",
            )
            .unwrap()
        })
        .find(value)
        .map(|m| m.as_str().trim().to_string())
        .unwrap_or_else(|| "0".into())
}
pub fn date_format(value: &str) -> Result<String> {
    let mut out = String::new();
    let mut chars = value.chars();
    while let Some(c) = chars.next() {
        if c == '%' {
            out.push_str(match chars.next() {
                Some('Y') => "YYYY",
                Some('y') => "YY",
                Some('m') => "MM",
                Some('d') => "DD",
                Some('H') => "HH24",
                Some('i') => "MI",
                Some('s') => "SS",
                Some('M') => "Month",
                Some('b') => "Mon",
                Some('%') => "%",
                _ => return Err("Unsupported DATE_FORMAT directive".into()),
            });
        } else {
            out.push('"');
            if c == '"' {
                out.push('\\');
            }
            out.push(c);
            out.push('"');
        }
    }
    Ok(out)
}
pub fn calendar(name: &str, date: &str, mode: u8) -> String {
    let mut source = format!("(SELECT CAST({date} AS date) AS d) AS _wpd_date");
    let zero = "d = DATE '0001-01-01'";
    let unit = match name {
        "DAYOFYEAR" => Some("EXTRACT(DOY FROM d)"),
        "DAYOFWEEK" => Some("EXTRACT(DOW FROM d)+1"),
        "WEEKDAY" => Some("EXTRACT(ISODOW FROM d)-1"),
        _ => None,
    };
    if let Some(unit) = unit {
        return format!(
            "(SELECT CASE WHEN {zero} THEN NULL ELSE CAST({unit} AS integer) END FROM {source})"
        );
    }
    let monday = mode & 1 != 0;
    let four = [1, 3, 4, 6].contains(&mode);
    let first = |year: &str| {
        let day = if four {
            format!("({year}+3)")
        } else {
            year.into()
        };
        let weekday = format!(
            "CAST(EXTRACT({} FROM {day}) AS integer){}",
            if monday { "ISODOW" } else { "DOW" },
            if monday { "-1" } else { "" }
        );
        if four {
            format!("({day}-({weekday}))")
        } else {
            format!("({day}+MOD(7-({weekday}),7))")
        }
    };
    source =
        format!("(SELECT d,CAST(date_trunc('year',d) AS date) AS y FROM {source}) AS _wpd_year");
    source = format!(
        "(SELECT d,{} AS first,{} AS previous,{} AS next FROM {source}) AS _wpd_weeks",
        first("y"),
        first("CAST(y-INTERVAL '1 year' AS date)"),
        first("CAST(y+INTERVAL '1 year' AS date)")
    );
    let number = "CAST(FLOOR((d-first)/7.0)+1 AS integer)";
    let result = if mode & 2 != 0 {
        format!(
            "CASE WHEN d<first THEN CAST(FLOOR((d-previous)/7.0)+1 AS integer) WHEN d>=next THEN 1 ELSE {number} END"
        )
    } else {
        format!("CASE WHEN d<first THEN 0 ELSE {number} END")
    };
    format!("(SELECT CASE WHEN {zero} THEN NULL ELSE {result} END FROM {source})")
}
impl Compiler<'_> {
    pub(crate) fn ordinal(&mut self, e: &Expr) -> Result<String> {
        if let Some(i) = Self::slot(e)
            && self.shape.numeric[i]
        {
            let raw = &self.shape.values[i];
            check(!raw.is_empty() && raw.bytes().all(|c| c.is_ascii_digit()))?;
            return Ok(format!("\u{1f}O{i}\u{1f}"));
        }
        self.expr(e, None)
    }
    pub(crate) fn slot(e: &Expr) -> Option<usize> {
        if let Expr::Value(v) = e
            && let Value::Placeholder(p) = &v.value
        {
            return p.strip_prefix('$')?.parse::<usize>().ok()?.checked_sub(1);
        }
        None
    }
    pub(crate) fn number_type(t: &str) -> bool {
        matches!(
            t,
            "bigint" | "integer" | "smallint" | "numeric" | "real" | "double precision"
        )
    }
    pub(crate) fn is_binary(e: &Expr) -> bool {
        matches!(
            e,
            Expr::Cast {
                data_type: DataType::Binary(None),
                ..
            }
        )
    }
    pub(crate) fn unbinary(e: &Expr) -> &Expr {
        if let Expr::Cast {
            expr,
            data_type: DataType::Binary(None),
            ..
        } = e
        {
            expr
        } else {
            e
        }
    }
    pub(crate) fn bytes(&mut self, e: &Expr) -> Result<String> {
        Ok(format!(
            "CONVERT_TO(CAST({} AS text),'UTF8')",
            self.expr(Self::unbinary(e), None)?
        ))
    }
    fn boolean(e: &Expr) -> bool {
        match e {
            Expr::BinaryOp { op, .. } => matches!(
                op,
                BinaryOperator::Eq
                    | BinaryOperator::NotEq
                    | BinaryOperator::Gt
                    | BinaryOperator::Lt
                    | BinaryOperator::GtEq
                    | BinaryOperator::LtEq
                    | BinaryOperator::Spaceship
                    | BinaryOperator::And
                    | BinaryOperator::Or
                    | BinaryOperator::Xor
            ),
            Expr::IsNull(_)
            | Expr::IsNotNull(_)
            | Expr::IsTrue(_)
            | Expr::IsFalse(_)
            | Expr::IsNotTrue(_)
            | Expr::IsNotFalse(_)
            | Expr::Like { .. }
            | Expr::RLike { .. }
            | Expr::InList { .. }
            | Expr::InSubquery { .. }
            | Expr::Between { .. }
            | Expr::Exists { .. }
            | Expr::UnaryOp {
                op: UnaryOperator::Not,
                ..
            } => true,
            Expr::Nested(e) => Self::boolean(e),
            Expr::Value(v) => matches!(v.value, Value::Boolean(_) | Value::Null),
            _ => false,
        }
    }
    pub(crate) fn numeric(&mut self, e: &Expr) -> Result<String> {
        if matches!(e,Expr::Value(v) if matches!(v.value,Value::Null)) {
            return Ok("NULL".into());
        }
        if let Expr::Nested(e) = e {
            return Ok(format!("({})", self.numeric(e)?));
        }
        if Self::boolean(e) {
            return Ok(format!(
                "(CASE WHEN {} THEN 1 ELSE 0 END)",
                self.expr(e, None)?
            ));
        }
        if let Some(slot) = Self::slot(e)
            && !self.shape.numeric[slot]
        {
            let s = self.expr(e, Some("numeric"))?;
            self.bindings.last_mut().unwrap().numeric_prefix = true;
            return Ok(s);
        }
        if let Some(t) = self.ty(e)
            && !Self::number_type(&t)
        {
            let s = self.expr(e, None)?;
            return Ok(format!(
                "(CASE WHEN {s} IS NULL THEN NULL WHEN {s} ~ '{NUMBER_PATTERN}' THEN CAST(substring({s} FROM '{NUMBER_PATTERN}') AS numeric) ELSE 0 END)"
            ));
        }
        if matches!(e,Expr::Function(f) if f.name.to_string().eq_ignore_ascii_case("DATE_FORMAT")) {
            let s = self.expr(e, None)?;
            return Ok(format!(
                "(SELECT CASE WHEN v IS NULL THEN NULL WHEN v ~ '{NUMBER_PATTERN}' THEN CAST(substring(v FROM '{NUMBER_PATTERN}') AS numeric) ELSE 0 END FROM (SELECT {s} AS v) AS _wpd_numeric)"
            ));
        }
        self.expr(e, None)
    }
    pub(crate) fn truth(&mut self, e: &Expr) -> Result<String> {
        if Self::boolean(e) {
            self.expr(e, None)
        } else {
            Ok(format!("({} <> 0)", self.numeric(e)?))
        }
    }
    fn is_json_value(&self, expr: &Expr) -> bool {
        match expr {
            Expr::Nested(e) => self.is_json_value(e),
            Expr::Cast {
                data_type: DataType::JSON,
                ..
            } => true,
            _ => self.ty(expr).as_deref() == Some("json"),
        }
    }
    pub(crate) fn require_comparable(&self, expressions: &[&Expr]) -> Result<()> {
        if expressions.iter().any(|e| self.is_json_value(e)) {
            return Err("JSON equality, range and membership comparisons are unsupported".into());
        }
        Ok(())
    }
    pub(crate) fn binary(
        &mut self,
        left: &Expr,
        op: &BinaryOperator,
        right: &Expr,
    ) -> Result<String> {
        use BinaryOperator::*;
        if matches!(op, Eq | NotEq | Gt | Lt | GtEq | LtEq | Spaceship) {
            self.require_comparable(&[left, right])?;
        }
        if matches!(op, Plus | Minus)
            && let Expr::Interval(i) = right
        {
            return self.interval(left, i, *op == Minus);
        }
        match op {
            And | Or | Xor => {
                return Ok(format!(
                    "({} {} {})",
                    self.truth(left)?,
                    if *op == Xor {
                        "<>"
                    } else if *op == And {
                        "AND"
                    } else {
                        "OR"
                    },
                    self.truth(right)?
                ));
            }
            StringConcat => {
                return Ok(format!(
                    "(CAST({} AS text) || CAST({} AS text))",
                    self.expr(left, None)?,
                    self.expr(right, None)?
                ));
            }
            Plus | Minus | Multiply | Divide | Modulo | MyIntegerDivide | BitwiseOr
            | BitwiseAnd | BitwiseXor | PGBitwiseShiftLeft | PGBitwiseShiftRight => {
                let l = self.numeric(left)?;
                let r = self.numeric(right)?;
                if *op == MyIntegerDivide {
                    return Ok(format!("TRUNC({l}/NULLIF({r},0))"));
                }
                let oper = match op {
                    BitwiseXor => "#".into(),
                    _ => op.to_string(),
                };
                return Ok(format!("({l} {oper} {r})"));
            }
            Eq | NotEq | Gt | Lt | GtEq | LtEq | Spaceship => {}
            _ => return Err("Unsupported binary operator".into()),
        }
        let oper = if *op == Spaceship {
            "IS NOT DISTINCT FROM".into()
        } else {
            op.to_string()
        };
        if Self::is_binary(left) || Self::is_binary(right) {
            return Ok(format!(
                "({} {oper} {})",
                self.bytes(left)?,
                self.bytes(right)?
            ));
        }
        let lnum = Self::slot(left).is_some_and(|i| self.shape.numeric[i]);
        let rnum = Self::slot(right).is_some_and(|i| self.shape.numeric[i]);
        let fmt = |e: &Expr| matches!(e,Expr::Function(f) if f.name.to_string().eq_ignore_ascii_case("DATE_FORMAT"));
        if (lnum && fmt(right)) || (rnum && fmt(left)) {
            return Ok(format!(
                "({} {oper} {})",
                self.numeric(left)?,
                self.numeric(right)?
            ));
        }
        let lt = self.ty(left);
        let rt = self.ty(right);
        if let Some(lt) = lt.as_deref()
            && Self::slot(right).is_some()
        {
            if rnum && !Self::number_type(lt) {
                return Ok(format!(
                    "({} {oper} {})",
                    self.numeric(left)?,
                    self.numeric(right)?
                ));
            }
            let l = self.expr(left, None)?;
            let r = self.expr(right, Some(lt))?;
            let (qualifier, field) = match left {
                Expr::Identifier(i) => (None, i.value.as_str()),
                Expr::CompoundIdentifier(v) if v.len() == 2 => {
                    (Some(v[0].value.as_str()), v[1].value.as_str())
                }
                _ => (None, ""),
            };
            let origins: std::collections::BTreeSet<_> = self
                .aliases
                .iter()
                .filter(|(alias, table)| {
                    qualifier.map_or_else(
                        || self.local_tables.is_empty() || self.local_tables.contains(*table),
                        |q| q == alias.as_str(),
                    ) && self.schema.get(*table).is_some_and(|t| {
                        t.columns.iter().any(|c| c.name.eq_ignore_ascii_case(field))
                    })
                })
                .map(|(_, table)| table)
                .collect();
            let user =
                origins.len() == 1 && origins.iter().next().is_some_and(|t| t.ends_with("_users"));
            if user
                && *op == Eq
                && ["user_login", "user_email"]
                    .iter()
                    .any(|f| field.eq_ignore_ascii_case(f))
            {
                return Ok(format!("(LOWER({l}) = LOWER({r}))"));
            }
            return Ok(format!("({l} {oper} {r})"));
        }
        if rt.is_some() && Self::slot(left).is_some() {
            let reversed = match op {
                Gt => Lt,
                Lt => Gt,
                GtEq => LtEq,
                LtEq => GtEq,
                _ => op.clone(),
            };
            return self.binary(right, &reversed, left);
        }
        Ok(format!(
            "({} {oper} {})",
            self.expr(left, None)?,
            self.expr(right, None)?
        ))
    }
    pub(crate) fn cast(&mut self, e: &Expr, t: &DataType) -> Result<String> {
        let (ty, num) = match t {
            DataType::Binary(None) => return self.bytes(e),
            DataType::Binary(Some(_)) => {
                return Err("Sized binary casts require explicit support".into());
            }
            DataType::Signed | DataType::SignedInteger => ("bigint".into(), true),
            DataType::Unsigned | DataType::UnsignedInteger => ("numeric(20)".into(), true),
            DataType::Char(_) | DataType::Varchar(_) | DataType::Nvarchar(_) | DataType::Text => {
                ("text".into(), false)
            }
            DataType::Datetime(_) | DataType::Timestamp(_, _) => ("timestamp".into(), false),
            DataType::Date => ("date".into(), false),
            DataType::Time(_, _) => ("time".into(), false),
            DataType::JSON => ("json".into(), false),
            DataType::Decimal(n) | DataType::Numeric(n) => (format!("numeric{n}"), false),
            DataType::Double(_) | DataType::DoublePrecision | DataType::Real => {
                ("double precision".into(), false)
            }
            DataType::Float(_) => ("real".into(), false),
            _ => return Err("Unsupported CAST type".into()),
        };
        Ok(format!(
            "CAST({} AS {ty})",
            if num {
                self.numeric(e)?
            } else {
                self.expr(e, None)?
            }
        ))
    }
    fn interval(&mut self, date: &Expr, i: &Interval, subtract: bool) -> Result<String> {
        check(
            i.last_field.is_none()
                && i.leading_precision.is_none()
                && i.fractional_seconds_precision.is_none(),
        )?;
        let unit = i
            .leading_field
            .as_ref()
            .ok_or("An interval unit is required")?
            .to_string()
            .to_ascii_lowercase();
        check(
            [
                "microsecond",
                "second",
                "minute",
                "hour",
                "day",
                "week",
                "month",
                "quarter",
                "year",
            ]
            .contains(&unit.as_str()),
        )?;
        let unit = if unit == "quarter" {
            "3 month".into()
        } else {
            format!("1 {unit}")
        };
        Ok(format!(
            "(CAST({} AS timestamp) {} ({} * INTERVAL '{unit}'))",
            self.expr(date, None)?,
            if subtract { "-" } else { "+" },
            self.numeric(&i.value)?
        ))
    }
    pub(crate) fn function(&mut self, f: &Function) -> Result<String> {
        check(
            !f.uses_odbc_syntax
                && f.filter.is_none()
                && f.over.is_none()
                && f.within_group.is_empty()
                && f.null_treatment.is_none()
                && matches!(f.parameters, FunctionArguments::None),
        )?;
        let n = name(&f.name)?.to_ascii_uppercase();
        let args = match &f.args {
            FunctionArguments::List(a) => Some(a),
            FunctionArguments::None => None,
            _ => return Err("Unsupported function arguments".into()),
        };
        let mut es = vec![];
        let mut star = false;
        if let Some(a) = args {
            for arg in &a.args {
                match arg {
                    FunctionArg::Unnamed(FunctionArgExpr::Expr(e)) => es.push(e),
                    FunctionArg::Unnamed(FunctionArgExpr::Wildcard) if n == "COUNT" => star = true,
                    _ => return Err("Unsupported function argument".into()),
                }
            }
        }
        if n == "VALUES" {
            check(self.upsert && es.len() == 1)?;
            let col = match es[0] {
                Expr::Identifier(i) => i,
                _ => return Err("VALUES requires an upsert column".into()),
            };
            self.upsert = false;
            let resolved = self.column(std::slice::from_ref(col));
            self.upsert = true;
            let (column, _) = resolved?;
            return Ok(format!("EXCLUDED.{column}"));
        }
        let len = es.len();
        let distinct =
            args.is_some_and(|a| a.duplicate_treatment == Some(DuplicateTreatment::Distinct));
        let aggregate = [
            "COUNT",
            "SUM",
            "AVG",
            "MIN",
            "MAX",
            "GROUP_CONCAT",
            "STD",
            "STDDEV_SAMP",
            "VARIANCE",
            "VAR_SAMP",
        ]
        .contains(&n.as_str());
        if !aggregate {
            check(!distinct && args.is_none_or(|a| a.clauses.is_empty()))?;
        }
        if star {
            check(len == 0 && args.is_some_and(|a| a.clauses.is_empty()) && !distinct)?;
            return Ok("COUNT(*)".into());
        }
        if aggregate {
            check(len == 1)?;
            let arg = if ["SUM", "AVG"].contains(&n.as_str()) {
                self.numeric(es[0])?
            } else {
                self.expr(es[0], None)?
            };
            let distinct = if distinct { "DISTINCT " } else { "" };
            if n == "GROUP_CONCAT" {
                let mut separator = "','".to_string();
                let mut order = String::new();
                for clause in &args.unwrap().clauses {
                    match clause {
                        FunctionArgumentClause::Separator(v) => {
                            separator = self.expr(&Expr::Value(v.clone()), Some("text"))?
                        }
                        FunctionArgumentClause::OrderBy(es) => {
                            let mut parts = vec![];
                            for e in es {
                                check(e.with_fill.is_none())?;
                                parts.push(format!("{}{}", self.expr(&e.expr, None)?, e.options));
                            }
                            order = format!(" ORDER BY {}", parts.join(","));
                        }
                        _ => return Err("Unsupported GROUP_CONCAT option".into()),
                    }
                }
                return Ok(format!(
                    "STRING_AGG({distinct}CAST({arg} AS text),{separator}{order})"
                ));
            }
            check(args.is_none_or(|a| a.clauses.is_empty()))?;
            let function = match n.as_str() {
                "STD" => "STDDEV_POP",
                "VARIANCE" => "VAR_POP",
                _ => &n,
            };
            return Ok(format!("{function}({distinct}{arg})"));
        }
        if n == "NULLIF"
            && len == 2
            && matches!(es[1],Expr::Value(v) if matches!(v.value,Value::Boolean(_)))
        {
            return Ok(format!(
                "NULLIF({},{})",
                self.truth(es[0])?,
                self.expr(es[1], None)?
            ));
        }
        match n.as_str() {
            "VERSION" => {
                check(len == 0)?;
                return Ok("CAST('8.0.17-Aurora-DSQL-compat' AS text)".into());
            }
            "CURRENT_USER" | "USER" | "SESSION_USER" => {
                check(len == 0)?;
                return Ok(if n == "SESSION_USER" {
                    "SESSION_USER"
                } else {
                    "CURRENT_USER"
                }
                .into());
            }
            "CURRENT_DATABASE" => {
                check(len == 0)?;
                return Ok("CURRENT_DATABASE()".into());
            }
            "NOW" | "CURRENT_TIMESTAMP" | "CURRENT_DATE" | "CURRENT_TIME" | "SYSDATE"
            | "CURDATE" | "CURTIME" | "UTC_TIMESTAMP" | "UTC_DATE" | "UTC_TIME" => {
                check(len == 0)?;
                return Ok(match n.as_str() {
                    "CURDATE" | "CURRENT_DATE" => "CURRENT_DATE",
                    "CURTIME" | "CURRENT_TIME" => "CURRENT_TIME",
                    "UTC_TIMESTAMP" => "(CURRENT_TIMESTAMP AT TIME ZONE 'UTC')",
                    "UTC_DATE" => "CAST(CURRENT_TIMESTAMP AT TIME ZONE 'UTC' AS date)",
                    "UTC_TIME" => "CAST(CURRENT_TIMESTAMP AT TIME ZONE 'UTC' AS time)",
                    _ => "CURRENT_TIMESTAMP",
                }
                .into());
            }
            "CONCAT" => {
                check(len > 0)?;
                let a = es
                    .iter()
                    .map(|e| self.expr(e, None).map(|s| format!("CAST({s} AS text)")))
                    .collect::<Result<Vec<_>>>()?;
                return Ok(format!("({})", a.join(" || ")));
            }
            "IF" => {
                check(len == 3)?;
                return Ok(format!(
                    "(CASE WHEN {} THEN {} ELSE {} END)",
                    self.truth(es[0])?,
                    self.expr(es[1], None)?,
                    self.expr(es[2], None)?
                ));
            }
            "FIELD" => {
                check(len >= 2)?;
                let mut s = "CASE".to_string();
                for (i, e) in es[1..].iter().enumerate() {
                    s.push_str(&format!(
                        " WHEN {} THEN {}",
                        self.binary(es[0], &BinaryOperator::Eq, e)?,
                        i + 1
                    ));
                }
                return Ok(format!("({s} ELSE 0 END)"));
            }
            "RAND" => {
                check(len == 0)?;
                return Ok("RANDOM()".into());
            }
            "DATE_ADD" | "DATE_SUB" | "ADDDATE" | "SUBDATE" => {
                check(len == 2)?;
                if let Expr::Interval(i) = es[1] {
                    return self.interval(es[0], i, ["DATE_SUB", "SUBDATE"].contains(&n.as_str()));
                }
                return Err("Date arithmetic requires explicit interval".into());
            }
            "YEAR" | "MONTH" | "DAY" | "DAYOFMONTH" | "HOUR" | "MINUTE" | "SECOND" => {
                check(len == 1)?;
                return Ok(format!(
                    "EXTRACT({} FROM CAST({} AS timestamp))",
                    if n == "DAYOFMONTH" { "DAY" } else { &n },
                    self.expr(es[0], None)?
                ));
            }
            "UNIX_TIMESTAMP" => {
                check(len <= 1)?;
                return Ok(format!(
                    "EXTRACT(EPOCH FROM {})",
                    if len == 0 {
                        "CURRENT_TIMESTAMP".into()
                    } else {
                        self.expr(es[0], None)?
                    }
                ));
            }
            "WEEK" | "DAYOFYEAR" | "DAYOFWEEK" | "WEEKDAY" => {
                check(len >= 1 && len <= if n == "WEEK" { 2 } else { 1 })?;
                let mut mode = 0;
                if len == 2 {
                    let slot = Self::slot(es[1]).ok_or("Literal WEEK mode required")?;
                    let raw = &self.shape.values[slot];
                    check(self.shape.numeric[slot] && raw.len() == 1)?;
                    mode = raw.parse::<u8>().map_err(|_| "Invalid WEEK mode")?;
                    check(mode <= 7)?;
                    self.cacheable = false;
                }
                return Ok(calendar(&n, &self.expr(es[0], None)?, mode));
            }
            "DATE_FORMAT" => {
                check(len == 2 && Self::slot(es[1]).is_some())?;
                let date = self.expr(es[0], None)?;
                let fmt = self.expr(es[1], Some("text"))?;
                self.bindings.last_mut().unwrap().format = true;
                return Ok(format!("TO_CHAR(CAST({date} AS timestamp),{fmt})"));
            }
            _ => {}
        }
        let allowed = [
            "COALESCE",
            "NULLIF",
            "LOWER",
            "UPPER",
            "CONCAT_WS",
            "IFNULL",
            "ABS",
            "ROUND",
            "CEIL",
            "CEILING",
            "FLOOR",
            "LENGTH",
            "CHAR_LENGTH",
            "CHARACTER_LENGTH",
            "SUBSTRING",
            "SUBSTR",
            "TRIM",
            "LTRIM",
            "RTRIM",
            "REPLACE",
            "LEFT",
            "RIGHT",
            "MOD",
            "MD5",
            "REVERSE",
            "GREATEST",
            "LEAST",
        ];
        check(allowed.contains(&n.as_str()) && len > 0)?;
        let mapped = match n.as_str() {
            "IFNULL" => "COALESCE",
            "LENGTH" => "OCTET_LENGTH",
            "CHARACTER_LENGTH" => "CHAR_LENGTH",
            "SUBSTR" => "SUBSTRING",
            _ => &n,
        };
        let mut values = vec![];
        for (i, e) in es.iter().enumerate() {
            let ty = if ((mapped == "SUBSTRING" && i > 0) || (mapped == "ROUND" && i == 1))
                || (["LEFT", "RIGHT"].contains(&mapped) && i == 1)
            {
                Some("integer")
            } else {
                None
            };
            values.push(self.expr(e, ty)?);
        }
        Ok(format!("{mapped}({})", values.join(",")))
    }
}

/// Expand only lexer-validated numeric/ordinal slots; cached plans never retain values.
pub fn resolve_numbers(sql: &str, values: &[String]) -> Result<String> {
    static MARKERS: OnceLock<Regex> = OnceLock::new();
    static NUMBER: OnceLock<Regex> = OnceLock::new();
    let markers = MARKERS.get_or_init(|| Regex::new("\u{1f}([NO])([0-9]+)\u{1f}").unwrap());
    let number = NUMBER.get_or_init(|| {
        Regex::new(r"^(?:[0-9]+(?:[.][0-9]*)?|[.][0-9]+)(?:[eE][+-]?[0-9]+)?$").unwrap()
    });
    let mut out = String::new();
    let mut at = 0;
    for cap in markers.captures_iter(sql) {
        let m = cap.get(0).unwrap();
        out.push_str(&sql[at..m.start()]);
        let i = cap[2]
            .parse::<usize>()
            .map_err(|_| "Invalid numeric slot")?;
        let value = values.get(i).ok_or("Missing numeric slot")?;
        check(if &cap[1] == "O" {
            !value.is_empty() && value.bytes().all(|b| b.is_ascii_digit())
        } else {
            number.is_match(value)
        })?;
        out.push_str(value);
        at = m.end();
    }
    out.push_str(&sql[at..]);
    Ok(out)
}
