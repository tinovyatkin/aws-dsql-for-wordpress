//! Restricted, three-valued evaluation for emulated MySQL catalog rows.
use crate::compiler::{Result, check};
use serde_json::{Map, Value};
use sqlparser::ast::*;
use std::cmp::Ordering;
pub type Row = Map<String, Value>;
pub fn string(v: &Value) -> Option<String> {
    match v {
        Value::Null => None,
        Value::String(s) => Some(s.clone()),
        Value::Bool(b) => Some(if *b { "1" } else { "0" }.into()),
        _ => Some(v.to_string()),
    }
}
pub fn truth(v: &Value) -> Option<bool> {
    match v {
        Value::Null => None,
        Value::Bool(b) => Some(*b),
        _ => Some(
            crate::expression::numeric_prefix(&string(v).unwrap())
                .parse::<f64>()
                .unwrap_or(0.)
                != 0.,
        ),
    }
}
pub fn compare(a: &Value, b: &Value) -> Ordering {
    if a.is_null() || b.is_null() {
        return a.is_null().cmp(&b.is_null()).reverse();
    }
    let a = string(a).unwrap();
    let b = string(b).unwrap();
    if let (Ok(a), Ok(b)) = (
        a.trim().parse::<sqlx::types::BigDecimal>(),
        b.trim().parse::<sqlx::types::BigDecimal>(),
    ) {
        a.cmp(&b)
    } else {
        a.to_ascii_lowercase().cmp(&b.to_ascii_lowercase())
    }
}
pub fn like(value: &str, pattern: &str) -> Result<bool> {
    let mut regex = String::from(r"\A");
    let mut chars = pattern.chars();
    while let Some(c) = chars.next() {
        match c {
            '\\' => {
                if let Some(c) = chars.next() {
                    regex.push_str(&regex::escape(&c.to_string()));
                } else {
                    regex.push_str(r"\\");
                }
            }
            '%' => regex.push_str(".*"),
            '_' => regex.push('.'),
            _ => regex.push_str(&regex::escape(&c.to_string())),
        }
    }
    regex.push_str(r"\z");
    Ok(regex::RegexBuilder::new(&regex)
        .case_insensitive(true)
        .dot_matches_new_line(true)
        .build()
        .map_err(|_| "Invalid metadata pattern")?
        .is_match(value))
}
pub struct Eval<'a> {
    pub values: &'a [String],
    pub columns: Vec<String>,
    pub database: &'a str,
}
impl Eval<'_> {
    pub fn column(&self, ids: &[Ident]) -> Result<String> {
        let name = ids
            .iter()
            .map(|i| i.value.as_str())
            .collect::<Vec<_>>()
            .join(".");
        self.columns
            .iter()
            .find(|c| c.eq_ignore_ascii_case(&name))
            .map(|s| s.rsplit('.').next().unwrap().to_string())
            .ok_or("Unknown metadata column".into())
    }
    pub fn eval(&self, e: &Expr, row: &Row) -> Result<Value> {
        use Value as V;
        Ok(match e {
            Expr::Identifier(i) => row
                .get(&self.column(std::slice::from_ref(i))?)
                .cloned()
                .unwrap_or(V::Null),
            Expr::CompoundIdentifier(ids) => {
                row.get(&self.column(ids)?).cloned().unwrap_or(V::Null)
            }
            Expr::Value(v) => match &v.value {
                sqlparser::ast::Value::Placeholder(p) => {
                    let i = p
                        .strip_prefix('$')
                        .and_then(|s| s.parse::<usize>().ok())
                        .and_then(|i| i.checked_sub(1))
                        .ok_or("Invalid metadata binding")?;
                    V::String(
                        self.values
                            .get(i)
                            .ok_or("Invalid metadata binding")?
                            .clone(),
                    )
                }
                sqlparser::ast::Value::SingleQuotedString(s)
                | sqlparser::ast::Value::DoubleQuotedString(s)
                | sqlparser::ast::Value::NationalStringLiteral(s)
                | sqlparser::ast::Value::Number(s, _) => V::String(s.clone()),
                sqlparser::ast::Value::Null => V::Null,
                sqlparser::ast::Value::Boolean(b) => V::Bool(*b),
                _ => return Err("Unsupported metadata literal".into()),
            },
            Expr::Nested(e) => self.eval(e, row)?,
            Expr::UnaryOp {
                op: UnaryOperator::Not,
                expr,
            } => truth(&self.eval(expr, row)?)
                .map(|v| V::Bool(!v))
                .unwrap_or(V::Null),
            Expr::UnaryOp {
                op: UnaryOperator::Minus,
                expr,
            } => {
                let v = self.eval(expr, row)?;
                if v.is_null() {
                    V::Null
                } else {
                    V::String(format!("-{}", string(&v).unwrap()))
                }
            }
            Expr::IsNull(e) => V::Bool(self.eval(e, row)?.is_null()),
            Expr::IsNotNull(e) => V::Bool(!self.eval(e, row)?.is_null()),
            Expr::BinaryOp { left, op, right } => {
                check(matches!(
                    op,
                    BinaryOperator::And
                        | BinaryOperator::Or
                        | BinaryOperator::Xor
                        | BinaryOperator::Spaceship
                        | BinaryOperator::Eq
                        | BinaryOperator::NotEq
                        | BinaryOperator::Lt
                        | BinaryOperator::Gt
                        | BinaryOperator::LtEq
                        | BinaryOperator::GtEq
                        | BinaryOperator::Plus
                ))?;
                let a = self.eval(left, row)?;
                let b = self.eval(right, row)?;
                match op {
                    BinaryOperator::And => match (truth(&a), truth(&b)) {
                        (Some(false), _) | (_, Some(false)) => V::Bool(false),
                        (None, _) | (_, None) => V::Null,
                        _ => V::Bool(true),
                    },
                    BinaryOperator::Or => match (truth(&a), truth(&b)) {
                        (Some(true), _) | (_, Some(true)) => V::Bool(true),
                        (None, _) | (_, None) => V::Null,
                        _ => V::Bool(false),
                    },
                    BinaryOperator::Spaceship if a.is_null() || b.is_null() => {
                        V::Bool(a.is_null() == b.is_null())
                    }
                    _ if a.is_null() || b.is_null() => V::Null,
                    _ => {
                        let c = compare(&a, &b);
                        match op {
                            BinaryOperator::Eq | BinaryOperator::Spaceship => {
                                V::Bool(c == Ordering::Equal)
                            }
                            BinaryOperator::NotEq => V::Bool(c != Ordering::Equal),
                            BinaryOperator::Gt => V::Bool(c == Ordering::Greater),
                            BinaryOperator::Lt => V::Bool(c == Ordering::Less),
                            BinaryOperator::GtEq => V::Bool(c != Ordering::Less),
                            BinaryOperator::LtEq => V::Bool(c != Ordering::Greater),
                            BinaryOperator::Plus => {
                                let a = string(&a)
                                    .unwrap()
                                    .parse::<sqlx::types::BigDecimal>()
                                    .map_err(|_| "Non-numeric metadata addition")?;
                                let b = string(&b)
                                    .unwrap()
                                    .parse::<sqlx::types::BigDecimal>()
                                    .map_err(|_| "Non-numeric metadata addition")?;
                                V::String((a + b).to_string())
                            }
                            BinaryOperator::Xor => V::Bool(truth(&a) != truth(&b)),
                            _ => return Err("Unsupported metadata operator".into()),
                        }
                    }
                }
            }
            Expr::InList {
                expr,
                list,
                negated,
            } => {
                let value = self.eval(expr, row)?;
                let mut null = value.is_null();
                let mut matched = false;
                for e in list {
                    check(matches!(e, Expr::Value(_) | Expr::UnaryOp { .. }))?;
                    let candidate = self.eval(e, row)?;
                    if candidate.is_null() {
                        null = true;
                    } else if !value.is_null() && compare(&value, &candidate) == Ordering::Equal {
                        matched = true;
                    }
                }
                if matched {
                    V::Bool(!*negated)
                } else if null {
                    V::Null
                } else {
                    V::Bool(*negated)
                }
            }
            Expr::Like {
                expr,
                pattern,
                negated,
                any,
                escape_char,
            } => {
                check(!*any && escape_char.is_none())?;
                match (
                    string(&self.eval(expr, row)?),
                    string(&self.eval(pattern, row)?),
                ) {
                    (Some(a), Some(b)) => V::Bool(like(&a, &b)? != *negated),
                    _ => V::Null,
                }
            }
            Expr::Function(f) => {
                check(
                    f.filter.is_none()
                        && f.over.is_none()
                        && f.within_group.is_empty()
                        && f.null_treatment.is_none()
                        && matches!(f.parameters, FunctionArguments::None),
                )?;
                let name = f.name.to_string().to_ascii_uppercase();
                let args = match &f.args {
                    FunctionArguments::None => vec![],
                    FunctionArguments::List(a) => {
                        check(a.duplicate_treatment.is_none() && a.clauses.is_empty())?;
                        a.args
                            .iter()
                            .map(|a| match a {
                                FunctionArg::Unnamed(FunctionArgExpr::Expr(e)) => Ok(e),
                                _ => Err("Unsupported metadata function argument".into()),
                            })
                            .collect::<Result<Vec<_>>>()?
                    }
                    _ => return Err("Unsupported metadata function".into()),
                };
                if name == "DATABASE" && args.is_empty() {
                    V::String(self.database.into())
                } else if ["LOWER", "UPPER", "SUM"].contains(&name.as_str()) && args.len() == 1 {
                    let value = self.eval(args[0], row)?;
                    if name == "SUM" {
                        value
                    } else {
                        string(&value)
                            .map(|s| {
                                V::String(if name == "LOWER" {
                                    s.to_lowercase()
                                } else {
                                    s.to_uppercase()
                                })
                            })
                            .unwrap_or(V::Null)
                    }
                } else {
                    return Err("Unsupported metadata function".into());
                }
            }
            _ => return Err("Unsupported metadata expression".into()),
        })
    }
    pub fn validate(&self, e: &Expr) -> Result<()> {
        use std::ops::ControlFlow;
        // Visit every subtree even when a NULL dummy value short-circuits evaluation.
        let result = visit_expressions(e, |e| match self.eval(e, &Row::new()) {
            Ok(_) => ControlFlow::Continue(()),
            Err(e) => ControlFlow::Break(e),
        });
        if let ControlFlow::Break(e) = result {
            Err(e)
        } else {
            Ok(())
        }
    }
}
pub fn mentions(e: &Expr, word: &str) -> bool {
    use std::ops::ControlFlow;
    matches!(
        visit_expressions(e, |e| {
            let found = match e {
                Expr::Identifier(i) => i.value.eq_ignore_ascii_case(word),
                Expr::CompoundIdentifier(v) => {
                    v.last().is_some_and(|i| i.value.eq_ignore_ascii_case(word))
                }
                Expr::Function(f) => f.name.to_string().eq_ignore_ascii_case(word),
                _ => false,
            };
            if found {
                ControlFlow::Break(())
            } else {
                ControlFlow::Continue(())
            }
        }),
        ControlFlow::Break(())
    )
}
