//! Mutation plans preserve the current adapter's atomicity and conflict guards.
use crate::compiler::{Column, Command, Compiler, Result, check, name, qi};
use sqlparser::ast::*;
use std::{collections::BTreeSet, ops::ControlFlow};
impl Compiler<'_> {
    fn write_value(&mut self, table: &str, col: &Column, e: &Expr) -> Result<String> {
        if matches!(e,Expr::Identifier(i) if i.quote_style.is_none()&&i.value.eq_ignore_ascii_case("DEFAULT"))
        {
            return Ok("DEFAULT".into());
        }
        let indexed = self.schema[table]
            .keys
            .iter()
            .any(|k| k.columns.contains(&col.name));
        self.write_column = Some((
            col.name.clone(),
            if !indexed {
                col.ty.clone()
            } else {
                String::new()
            },
        ));
        let result = self.expr(
            e,
            if Self::slot(e).is_some() {
                Some(&col.ty)
            } else {
                None
            },
        );
        self.write_column = None;
        result
    }
    fn generated(&mut self, e: &Expr, c: &Column) -> bool {
        if !c.identity {
            return false;
        }
        self.cacheable = false;
        if matches!(e,Expr::Value(v) if matches!(v.value,Value::Null)) {
            return true;
        }
        Self::slot(e).is_some_and(|i| {
            let v = &self.shape.values[i];
            v.starts_with('0')
                && v.split('.').count() <= 2
                && v.chars().all(|c| c == '0' || c == '.')
        })
    }
    fn assign(&mut self, table: &str, assignments: &[Assignment]) -> Result<String> {
        let mut assigned = BTreeSet::new();
        let mut out = vec![];
        for a in assignments {
            let field = match &a.target {
                AssignmentTarget::ColumnName(n) => name(n)?,
                _ => return Err("Unsupported assignment target".into()),
            };
            let col = self.schema[table]
                .iter()
                .find(|c| c.name.eq_ignore_ascii_case(&field))
                .cloned()
                .ok_or("Unknown assignment column")?;
            let mut value = a.value.clone();
            let _: ControlFlow<()> = visit_expressions_mut(&mut value, |e| {
                if matches!(e,Expr::Function(f) if f.name.to_string().eq_ignore_ascii_case("VALUES"))
                {
                    *e = Expr::Value(Value::Null.into());
                }
                ControlFlow::Continue(())
            });
            let result = visit_expressions(&value, |e| {
                let field = match e {
                    Expr::Identifier(i) => Some(&i.value),
                    Expr::CompoundIdentifier(v) => v.last().map(|i| &i.value),
                    _ => None,
                };
                if field.is_some_and(|f| assigned.contains(&f.to_ascii_lowercase())) {
                    ControlFlow::Break(
                        "Sequentially dependent assignments require explicit translation"
                            .to_string(),
                    )
                } else {
                    ControlFlow::Continue(())
                }
            });
            if let ControlFlow::Break(e) = result {
                return Err(e);
            }
            out.push(format!(
                "{} = {}",
                qi(&col.name),
                self.write_value(table, &col, &a.value)?
            ));
            assigned.insert(col.name.to_ascii_lowercase());
        }
        Ok(out.join(","))
    }
    pub(crate) fn insert(&mut self, i: &Insert) -> Result<(String, bool)> {
        check(
            i.optimizer_hints.is_empty()
                && i.or.is_none()
                && i.table_alias.is_none()
                && !i.overwrite
                && i.assignments.is_empty()
                && i.partitioned.is_none()
                && i.after_columns.is_empty()
                && i.returning.is_none()
                && i.output.is_none()
                && i.priority.is_none()
                && i.insert_alias.is_none()
                && i.settings.is_none()
                && i.format_clause.is_none()
                && i.multi_table_insert_type.is_none()
                && i.multi_table_into_clauses.is_empty()
                && i.multi_table_when_clauses.is_empty()
                && i.multi_table_else_clause.is_none(),
        )?;
        let table = match &i.table {
            TableObject::TableName(n) => self.target(n)?,
            _ => return Err("Unsupported INSERT target".into()),
        };
        let meta = self.schema[&table].clone();
        let columns = if i.columns.is_empty() {
            meta.columns.clone()
        } else {
            i.columns
                .iter()
                .map(|n| {
                    let n = name(n)?;
                    meta.iter()
                        .find(|c| c.name.eq_ignore_ascii_case(&n))
                        .cloned()
                        .ok_or("Unknown INSERT column".into())
                })
                .collect::<Result<Vec<_>>>()?
        };
        check(
            columns
                .iter()
                .map(|c| &c.name)
                .collect::<BTreeSet<_>>()
                .len()
                == columns.len(),
        )?;
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
            _ => return Err("INSERT requires VALUES".into()),
        };
        check(!rows.is_empty() && rows.iter().all(|r| r.len() == columns.len()))?;
        if i.replace_into {
            check(rows.len() == 1 && i.on.is_none() && !i.ignore)?;
            let mut conflicts = vec![];
            for key in &meta.keys {
                if !key.unique {
                    continue;
                }
                let checkpoint = self.bindings.len();
                let mut parts = vec![];
                let mut skip = false;
                for name in &key.columns {
                    let col = meta
                        .iter()
                        .find(|c| c.name == *name)
                        .ok_or("Unknown conflict column")?;
                    let value = columns
                        .iter()
                        .position(|c| c.name == *name)
                        .map(|i| &rows[0][i]);
                    let Some(value) = value else {
                        if col.identity || (col.nullable && col.default.is_none()) {
                            skip = true;
                            break;
                        }
                        return Err("REPLACE requires non-identity unique key values".into());
                    };
                    if self.generated(value, col)
                        || matches!(value,Expr::Value(v) if matches!(v.value,Value::Null))
                    {
                        skip = true;
                        break;
                    }
                    check(Self::slot(value).is_some())?;
                    parts.push(format!(
                        "{} = {}",
                        qi(name),
                        self.write_value(&table, col, value)?
                    ));
                }
                if skip {
                    self.bindings.truncate(checkpoint);
                } else {
                    conflicts.push(format!("({})", parts.join(" AND ")));
                }
            }
            self.leading.push(Command {
                sql: format!(
                    "DELETE FROM {} WHERE {}",
                    qi(&table),
                    if conflicts.is_empty() {
                        "FALSE".into()
                    } else {
                        conflicts.join(" OR ")
                    }
                ),
                bindings: std::mem::take(&mut self.bindings),
            });
        }
        let mut values = vec![];
        for row in rows {
            let mut r = vec![];
            for (e, c) in row.iter().zip(&columns) {
                r.push(if self.generated(e, c) {
                    "DEFAULT".into()
                } else {
                    self.write_value(&table, c, e)?
                });
            }
            values.push(format!("({})", r.join(",")));
        }
        let mut sql = format!(
            "INSERT INTO {} ({}) VALUES {}",
            qi(&table),
            columns
                .iter()
                .map(|c| qi(&c.name))
                .collect::<Vec<_>>()
                .join(","),
            values.join(",")
        );
        if let Some(on) = &i.on {
            let assignments = match on {
                OnInsert::DuplicateKeyUpdate(a) => a,
                _ => return Err("Unsupported INSERT conflict action".into()),
            };
            let mut candidates: BTreeSet<Vec<String>> = BTreeSet::new();
            for key in &meta.keys {
                if key.unique
                    && key
                        .columns
                        .iter()
                        .all(|k| columns.iter().any(|c| &c.name == k))
                {
                    candidates.insert(key.columns.clone());
                }
            }
            if candidates.len() != 1 {
                return Err("Upsert requires one unambiguous unique conflict target".into());
            }
            self.upsert = true;
            let updates = self.assign(&table, assignments);
            self.upsert = false;
            sql.push_str(&format!(
                " ON CONFLICT ({}) DO UPDATE SET {}",
                candidates
                    .first()
                    .unwrap()
                    .iter()
                    .map(|c| qi(c))
                    .collect::<Vec<_>>()
                    .join(","),
                updates?
            ));
        } else if i.ignore {
            sql.push_str(" ON CONFLICT DO NOTHING");
        }
        sql.push_str(" RETURNING *");
        Ok((sql, true))
    }
    fn physical(t: &TableFactor) -> Result<String> {
        match t {
            TableFactor::Table { name: n, .. } => name(n),
            _ => Err("Physical write table required".into()),
        }
    }
    fn ordered(&mut self, order: &[OrderByExpr]) -> Result<String> {
        if order.is_empty() {
            return Ok(String::new());
        }
        let mut out = vec![];
        for e in order {
            check(e.with_fill.is_none())?;
            out.push(format!("{}{}", self.ordinal(&e.expr)?, e.options));
        }
        Ok(format!(" ORDER BY {}", out.join(",")))
    }
    fn write_predicate(
        &mut self,
        table: &str,
        target: &str,
        selection: &Option<Expr>,
        order: &[OrderByExpr],
        limit: &Option<Expr>,
    ) -> Result<String> {
        let filter = if let Some(e) = selection {
            format!(" WHERE {}", self.truth(e)?)
        } else {
            String::new()
        };
        if let Some(limit) = limit {
            let key = self.schema[table]
                .keys
                .iter()
                .find(|k| k.primary)
                .ok_or("Limited writes require a primary key")?
                .columns
                .clone();
            let cols = key.iter().map(|c| qi(c)).collect::<Vec<_>>().join(",");
            let expr = if key.len() > 1 {
                format!("({cols})")
            } else {
                cols.clone()
            };
            Ok(format!(
                " WHERE {expr} IN (SELECT {cols} FROM {target}{filter}{} LIMIT {})",
                self.ordered(order)?,
                self.expr(limit, Some("bigint"))?
            ))
        } else {
            Ok(filter)
        }
    }
    pub(crate) fn update(&mut self, u: &Update) -> Result<String> {
        check(
            u.optimizer_hints.is_empty()
                && u.table.joins.is_empty()
                && u.from.is_none()
                && u.returning.is_none()
                && u.output.is_none()
                && u.or.is_none(),
        )?;
        let table = Self::physical(&u.table.relation)?;
        let target = self.table(&u.table.relation)?;
        Ok(format!(
            "UPDATE {target} SET {}{}",
            self.assign(&table, &u.assignments)?,
            self.write_predicate(&table, &target, &u.selection, &u.order_by, &u.limit)?
        ))
    }
    pub(crate) fn delete(&mut self, d: &Delete) -> Result<String> {
        check(
            d.optimizer_hints.is_empty()
                && d.using.is_none()
                && d.returning.is_none()
                && d.output.is_none(),
        )?;
        let from = match &d.from {
            FromTable::WithFromKeyword(f) | FromTable::WithoutKeyword(f) => f,
        };
        if d.tables.is_empty() {
            check(from.len() == 1 && from[0].joins.is_empty())?;
            let table = Self::physical(&from[0].relation)?;
            let target = self.table(&from[0].relation)?;
            return Ok(format!(
                "DELETE FROM {target}{}",
                self.write_predicate(&table, &target, &d.selection, &d.order_by, &d.limit)?
            ));
        }
        check(d.order_by.is_empty() && d.limit.is_none() && !from.is_empty())?;
        let table = Self::physical(&from[0].relation)?;
        let mut factors = vec![];
        for f in from {
            factors.push(&f.relation);
            for j in &f.joins {
                factors.push(&j.relation);
            }
        }
        check(
            factors.len() == 2
                && factors
                    .iter()
                    .all(|f| Self::physical(f).as_ref() == Ok(&table)),
        )?;
        let targets = d.tables.iter().map(name).collect::<Result<Vec<_>>>()?;
        check(
            !targets.is_empty()
                && targets.len() <= 2
                && (targets.len() == 1 || from.iter().all(|f| f.joins.is_empty())),
        )?;
        let key = self.schema[&table]
            .keys
            .iter()
            .find(|k| k.primary)
            .ok_or("Self-join DELETE requires a primary key")?
            .columns
            .clone();
        let source = from
            .iter()
            .map(|f| self.from(f))
            .collect::<Result<Vec<_>>>()?
            .join(",");
        let mut branches = vec![];
        for target in targets {
            check(self.aliases.contains_key(&target))?;
            let selected = key
                .iter()
                .map(|c| format!("{}.{}", qi(&target), qi(c)))
                .collect::<Vec<_>>()
                .join(",");
            let filter = if let Some(e) = &d.selection {
                format!(" WHERE {}", self.truth(e)?)
            } else {
                String::new()
            };
            branches.push(format!("SELECT {selected} FROM {source}{filter}"));
        }
        let columns = key.iter().map(|c| qi(c)).collect::<Vec<_>>().join(",");
        let columns = if key.len() > 1 {
            format!("({columns})")
        } else {
            columns
        };
        Ok(format!(
            "DELETE FROM {} WHERE {columns} IN ({})",
            qi(&table),
            branches.join(" UNION ")
        ))
    }
}
