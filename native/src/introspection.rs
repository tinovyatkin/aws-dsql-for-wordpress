//! MySQL SHOW/INFORMATION_SCHEMA compatibility from the versioned logical catalog.
use crate::{
    catalog_expr::{self, Eval, Row},
    catalog_model as model,
    compiler::{Result, Shape, check, qi},
    engine::{Client, Output},
    metadata_syntax::{Command, Filter},
};
use serde_json::{Value, json};
use sqlparser::ast::*;
fn fields(names: &[&str]) -> Vec<String> {
    names.iter().map(|s| s.to_string()).collect()
}
fn output(rows: Vec<Row>, columns: Vec<String>, operation: &str) -> Output {
    Output {
        affected: rows.len() as u64,
        rows: rows
            .into_iter()
            .map(|r| {
                r.into_iter()
                    .map(|(k, v)| (k, catalog_expr::string(&v).map(String::into_bytes)))
                    .collect()
            })
            .collect(),
        metadata: columns
            .iter()
            .map(|s| {
                [
                    ("name".into(), json!(s)),
                    ("native_type".into(), json!("text")),
                ]
                .into()
            })
            .collect(),
        columns,
        operation: operation.into(),
        ..Output::default()
    }
}
fn row(v: Value) -> Row {
    v.as_object().unwrap().clone()
}
fn status_fields() -> Vec<String> {
    fields(&[
        "Name",
        "Engine",
        "Version",
        "Row_format",
        "Rows",
        "Avg_row_length",
        "Data_length",
        "Max_data_length",
        "Index_length",
        "Data_free",
        "Auto_increment",
        "Create_time",
        "Update_time",
        "Check_time",
        "Collation",
        "Checksum",
        "Create_options",
        "Comment",
    ])
}
fn filter(
    rows: Vec<Row>,
    command: &Command,
    columns: &[String],
    field: &str,
    values: &[String],
) -> Result<Vec<Row>> {
    let eval = Eval {
        values,
        columns: columns.to_vec(),
        database: "postgres",
    };
    if let Some(Filter::Where(e)) = &command.filter {
        eval.validate(e)?;
    }
    rows.into_iter()
        .filter_map(|r| {
            let keep = match &command.filter {
                None => Ok(true),
                Some(Filter::Like(pattern)) => {
                    catalog_expr::like(r.get(field).and_then(Value::as_str).unwrap_or(""), pattern)
                }
                Some(Filter::Where(e)) => eval
                    .eval(e, &r)
                    .map(|v| catalog_expr::truth(&v) == Some(true)),
            };
            match keep {
                Ok(true) => Some(Ok(r)),
                Ok(false) => None,
                Err(e) => Some(Err(e)),
            }
        })
        .collect()
}
fn show(client: &mut Client, command: &Command, shape: &Shape) -> Result<Output> {
    if let Some(database) = &command.database {
        check(
            database.eq_ignore_ascii_case("postgres")
                || database.eq_ignore_ascii_case(&client.config.schema),
        )?;
    }
    let (rows, columns, field) = match command.kind.as_str() {
        "variables" => (
            vec![],
            fields(&["Variable_name", "Value"]),
            "Variable_name".to_string(),
        ),
        "tables" => {
            let key = "Tables_in_postgres";
            let rows = client
                .names()?
                .into_iter()
                .map(|name| {
                    let mut r = row(json!({key:name}));
                    if command.full {
                        r.insert("Table_type".into(), json!("BASE TABLE"));
                    }
                    r
                })
                .collect();
            (
                rows,
                if command.full {
                    fields(&[key, "Table_type"])
                } else {
                    fields(&[key])
                },
                key.into(),
            )
        }
        "status" => {
            let mut rows = vec![];
            for name in client.names()? {
                if let Some(Filter::Like(pattern)) = &command.filter
                    && !catalog_expr::like(&name, pattern)?
                {
                    continue;
                }
                if let Some(table) = client.logical_table(&name)? {
                    rows.push(row(json!({"Name":name,"Engine":"Aurora DSQL","Version":null,"Row_format":null,"Rows":client.row_count(&name)?,"Avg_row_length":null,"Data_length":null,"Max_data_length":null,"Index_length":null,"Data_free":null,"Auto_increment":null,"Create_time":null,"Update_time":null,"Check_time":null,"Collation":table["table_options"]["collation"],"Checksum":null,"Create_options":"","Comment":"Physical storage sizes are unavailable through the DSQL SQL interface."})));
                }
            }
            (rows, status_fields(), "Name".into())
        }
        "columns" | "describe" | "keys" | "create" => {
            let name = client.table_name(command.names.first().ok_or("Missing metadata table")?)?;
            let table = client.logical_table(&name)?;
            if table.is_none() && command.kind == "describe" {
                return Ok(output(vec![], vec![], "DESCRIBE"));
            }
            let table = table.ok_or("Unknown metadata table")?;
            if command.kind == "create" {
                return Ok(output(
                    vec![row(
                        json!({"Table":name,"Create Table":model::mysql(&table)?}),
                    )],
                    fields(&["Table", "Create Table"]),
                    "SHOW",
                ));
            }
            if command.kind == "keys" {
                let rows = table["indexes"]
                    .as_array()
                    .ok_or("Invalid logical indexes")?
                    .iter()
                    .map(|r| r.as_object().cloned().ok_or("Invalid index row".into()))
                    .collect::<Result<Vec<_>>>()?;
                let columns = rows
                    .first()
                    .map(|r| r.keys().cloned().collect())
                    .unwrap_or(fields(&[
                        "Table",
                        "Non_unique",
                        "Key_name",
                        "Seq_in_index",
                        "Column_name",
                        "Collation",
                        "Sub_part",
                        "Index_type",
                    ]));
                (rows, columns, "Key_name".into())
            } else {
                let rows = model::columns(&table, command.full)?;
                let columns = rows
                    .first()
                    .map(|r| r.keys().cloned().collect())
                    .unwrap_or_default();
                (rows, columns, "Field".into())
            }
        }
        "check" | "repair" | "analyze" | "optimize" => {
            let mut rows = vec![];
            for name in &command.names {
                let name = client.table_name(name)?;
                if command.kind == "check" {
                    client.catalog.as_mut().unwrap().models.remove(&name);
                }
                let table = client.logical_table(&name)?;
                let (kind, message) = if table.is_none() {
                    ("error", "Table does not exist".into())
                } else if command.kind == "check" {
                    client.raw(
                        format!(
                            "SELECT 1 FROM {}.{} LIMIT 1",
                            qi(&client.config.schema),
                            qi(&name)
                        ),
                        vec![],
                    )?;
                    ("status", "OK".into())
                } else {
                    (
                        "note",
                        format!(
                            "Aurora DSQL manages physical storage; MySQL {} is not applicable. No maintenance was performed.",
                            command.kind
                        ),
                    )
                };
                rows.push(row(json!({"Table":format!("postgres.{name}"),"Op":command.kind,"Msg_type":kind,"Msg_text":message})));
            }
            return Ok(output(
                rows,
                fields(&["Table", "Op", "Msg_type", "Msg_text"]),
                &command.kind.to_ascii_uppercase(),
            ));
        }
        _ => return Err("Unsupported metadata statement".into()),
    };
    Ok(output(
        filter(rows, command, &columns, &field, &shape.values)?,
        columns,
        "SHOW",
    ))
}
fn info_fields(kind: &str) -> Result<Vec<String>> {
    Ok(match kind {
        "TABLES" => fields(&[
            "TABLE_CATALOG",
            "TABLE_SCHEMA",
            "TABLE_NAME",
            "TABLE_TYPE",
            "ENGINE",
            "TABLE_COLLATION",
            "TABLE_COMMENT",
            "TABLE_ROWS",
            "DATA_LENGTH",
            "INDEX_LENGTH",
        ]),
        "COLUMNS" => fields(&[
            "TABLE_CATALOG",
            "TABLE_SCHEMA",
            "TABLE_NAME",
            "COLUMN_NAME",
            "ORDINAL_POSITION",
            "COLUMN_DEFAULT",
            "IS_NULLABLE",
            "DATA_TYPE",
            "CHARACTER_MAXIMUM_LENGTH",
            "CHARACTER_OCTET_LENGTH",
            "NUMERIC_PRECISION",
            "NUMERIC_SCALE",
            "DATETIME_PRECISION",
            "CHARACTER_SET_NAME",
            "COLLATION_NAME",
            "COLUMN_TYPE",
            "COLUMN_KEY",
            "EXTRA",
            "PRIVILEGES",
            "COLUMN_COMMENT",
        ]),
        "STATISTICS" => fields(&[
            "TABLE_CATALOG",
            "TABLE_SCHEMA",
            "TABLE_NAME",
            "NON_UNIQUE",
            "INDEX_SCHEMA",
            "INDEX_NAME",
            "SEQ_IN_INDEX",
            "COLUMN_NAME",
            "COLLATION",
            "CARDINALITY",
            "SUB_PART",
            "PACKED",
            "NULLABLE",
            "INDEX_TYPE",
            "COMMENT",
            "INDEX_COMMENT",
            "IS_VISIBLE",
            "EXPRESSION",
        ]),
        "SCHEMATA" => fields(&[
            "CATALOG_NAME",
            "SCHEMA_NAME",
            "DEFAULT_CHARACTER_SET_NAME",
            "DEFAULT_COLLATION_NAME",
            "SQL_PATH",
        ]),
        _ => return Err("Unsupported INFORMATION_SCHEMA table".into()),
    })
}
fn info_rows(client: &mut Client, kind: &str, target: Option<&str>) -> Result<Vec<Row>> {
    if kind == "SCHEMATA" {
        return Ok(vec![row(
            json!({"CATALOG_NAME":"def","SCHEMA_NAME":"postgres","DEFAULT_CHARACTER_SET_NAME":"utf8mb4","DEFAULT_COLLATION_NAME":"utf8mb4_unicode_ci","SQL_PATH":null}),
        )]);
    }
    let mut rows = vec![];
    for name in client.names()? {
        if target.is_some_and(|target| !name.eq_ignore_ascii_case(target)) {
            continue;
        }
        let Some(table) = client.logical_table(&name)? else {
            continue;
        };
        match kind{
            "TABLES"=>rows.push(row(json!({"TABLE_CATALOG":"def","TABLE_SCHEMA":"postgres","TABLE_NAME":name,"TABLE_TYPE":"BASE TABLE","ENGINE":table["table_options"]["engine"],"TABLE_COLLATION":table["table_options"]["collation"],"TABLE_COMMENT":table["table_options"]["comment"],"TABLE_ROWS":null,"DATA_LENGTH":null,"INDEX_LENGTH":null}))),
            "COLUMNS"=>rows.extend(model::information_columns(&table,"postgres")?),
            "STATISTICS"=>for i in table["indexes"].as_array().ok_or("Invalid logical indexes")?{rows.push(row(json!({"TABLE_CATALOG":"def","TABLE_SCHEMA":"postgres","TABLE_NAME":name,"NON_UNIQUE":catalog_expr::string(&i["Non_unique"]),"INDEX_SCHEMA":"postgres","INDEX_NAME":i["Key_name"],"SEQ_IN_INDEX":catalog_expr::string(&i["Seq_in_index"]),"COLUMN_NAME":i["Column_name"],"COLLATION":i.get("Collation").cloned().unwrap_or(Value::Null),"CARDINALITY":i.get("Cardinality").cloned().unwrap_or(Value::Null),"SUB_PART":i["Sub_part"],"PACKED":i.get("Packed").cloned().unwrap_or(Value::Null),"NULLABLE":i.get("Null").cloned().unwrap_or(json!("")),"INDEX_TYPE":i["Index_type"],"COMMENT":i.get("Comment").cloned().unwrap_or(json!("")),"INDEX_COMMENT":i.get("Index_comment").cloned().unwrap_or(json!("")),"IS_VISIBLE":i.get("Visible").cloned().unwrap_or(json!("YES")),"EXPRESSION":i.get("Expression").cloned().unwrap_or(Value::Null)})));},_=>unreachable!()
        }
    }
    Ok(rows)
}
fn table_filter(e: &Expr, eval: &Eval) -> Option<String> {
    if let Expr::BinaryOp { left, op, right } = e {
        if *op == BinaryOperator::And {
            return table_filter(left, eval).or_else(|| table_filter(right, eval));
        }
        if *op == BinaryOperator::Eq {
            let name = match &**left {
                Expr::Identifier(i) => Some(i.value.as_str()),
                Expr::CompoundIdentifier(v) => v.last().map(|i| i.value.as_str()),
                _ => None,
            };
            if name.is_some_and(|n| n.eq_ignore_ascii_case("TABLE_NAME"))
                && matches!(&**right, Expr::Value(_))
            {
                return eval
                    .eval(right, &Row::new())
                    .ok()
                    .and_then(|v| catalog_expr::string(&v));
            }
        }
    }
    None
}
fn limit(e: &Expr, eval: &Eval) -> Result<usize> {
    check(matches!(e, Expr::Value(_)))?;
    let raw = catalog_expr::string(&eval.eval(e, &Row::new())?)
        .ok_or("Literal metadata limit required")?;
    check(!raw.is_empty() && raw.bytes().all(|c| c.is_ascii_digit()))?;
    raw.parse()
        .map_err(|_| "Metadata limit out of range".into())
}
fn select(client: &mut Client, q: &Query, shape: &Shape) -> Result<Option<Output>> {
    use std::ops::ControlFlow;
    let mut references = vec![];
    let _: ControlFlow<()> = visit_relations(q, |n| {
        references.push(n.clone());
        ControlFlow::Continue(())
    });
    if !references.iter().any(|n| {
        n.0.len() == 2
            && n.0[0]
                .as_ident()
                .is_some_and(|i| i.value.eq_ignore_ascii_case("information_schema"))
    }) {
        return Ok(None);
    }
    check(
        references.len() == 1
            && q.with.is_none()
            && q.fetch.is_none()
            && q.locks.is_empty()
            && q.for_clause.is_none()
            && q.settings.is_none()
            && q.format_clause.is_none()
            && q.pipe_operators.is_empty(),
    )?;
    let s = match &*q.body {
        SetExpr::Select(s) => s,
        _ => return Err("Metadata unions/subqueries are unsupported".into()),
    };
    check(
        s.from.len() == 1
            && s.from[0].joins.is_empty()
            && s.distinct.is_none()
            && s.select_modifiers.is_none()
            && s.top.is_none()
            && s.into.is_none()
            && s.having.is_none()
            && s.optimizer_hints.is_empty()
            && s.prewhere.is_none()
            && s.qualify.is_none()
            && s.named_window.is_empty()
            && s.lateral_views.is_empty()
            && s.connect_by.is_empty()
            && s.cluster_by.is_empty()
            && s.distribute_by.is_empty()
            && s.sort_by.is_empty()
            && s.value_table_mode.is_none()
            && s.exclude.is_none(),
    )?;
    let (name, alias) = match &s.from[0].relation {
        TableFactor::Table {
            name,
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
            (name, alias)
        }
        _ => return Err("Physical metadata table required".into()),
    };
    let kind = name.0[1]
        .as_ident()
        .ok_or("Invalid metadata table")?
        .value
        .to_ascii_uppercase();
    let columns = info_fields(&kind)?;
    let mut allowed = columns.clone();
    let mut qualifiers = vec![kind.clone()];
    if let Some(a) = alias {
        check(a.columns.is_empty())?;
        qualifiers.push(a.name.value.clone());
    }
    for qualifier in qualifiers {
        allowed.extend(columns.iter().map(|c| format!("{qualifier}.{c}")));
    }
    let eval = Eval {
        values: &shape.values,
        columns: allowed,
        database: "postgres",
    };
    let grouped = match &s.group_by {
        GroupByExpr::Expressions(es, mods) => {
            check(mods.is_empty())?;
            if es.is_empty() {
                false
            } else {
                check(kind == "TABLES" && es.len() == 1)?;
                let col = match &es[0] {
                    Expr::Identifier(i) => eval.column(std::slice::from_ref(i))?,
                    Expr::CompoundIdentifier(ids) => eval.column(ids)?,
                    _ => return Err("Metadata grouping requires TABLE_NAME".into()),
                };
                check(col == "TABLE_NAME")?;
                true
            }
        }
        _ => return Err("Unsupported metadata grouping".into()),
    };
    if let Some(e) = &s.selection {
        eval.validate(e)?;
        check(!catalog_expr::mentions(e, "SUM"))?;
    }
    let mut projections: Vec<(String, Option<Expr>)> = vec![];
    let mut count = false;
    for p in &s.projection {
        match p {
            SelectItem::Wildcard(o) if o.to_string().is_empty() => {
                for c in &columns {
                    projections.push((c.clone(), Some(Expr::Identifier(Ident::new(c)))));
                }
            }
            SelectItem::UnnamedExpr(e) | SelectItem::ExprWithAlias { expr: e, .. } => {
                let label = match p {
                    SelectItem::ExprWithAlias { alias, .. } => alias.value.clone(),
                    _ => match e {
                        Expr::Identifier(i) => i.value.clone(),
                        Expr::CompoundIdentifier(v) => v.last().unwrap().value.clone(),
                        _ => e.to_string(),
                    },
                };
                let is_count = matches!(e,Expr::Function(f) if f.name.to_string().eq_ignore_ascii_case("COUNT")&&matches!(&f.args,FunctionArguments::List(a) if a.args.len()==1&&matches!(a.args[0],FunctionArg::Unnamed(FunctionArgExpr::Wildcard))&&a.clauses.is_empty()&&a.duplicate_treatment.is_none())&&f.filter.is_none()&&f.over.is_none()&&f.within_group.is_empty());
                if is_count {
                    count = true;
                    projections.push((label, None));
                } else {
                    eval.validate(e)?;
                    check(grouped || !catalog_expr::mentions(e, "SUM"))?;
                    projections.push((label, Some(e.clone())));
                }
            }
            _ => return Err("Unsupported metadata projection".into()),
        }
    }
    check(!count || (projections.len() == 1 && !grouped))?;
    let labels = projections.iter().map(|p| p.0.clone()).collect::<Vec<_>>();
    check(
        labels
            .iter()
            .collect::<std::collections::BTreeSet<_>>()
            .len()
            == labels.len(),
    )?;
    let mut order = vec![];
    if let Some(o) = &q.order_by {
        check(o.interpolate.is_none())?;
        let orders = match &o.kind {
            OrderByKind::Expressions(o) => o,
            _ => return Err("Unsupported metadata order".into()),
        };
        for o in orders {
            check(o.with_fill.is_none() && o.options.nulls_first.is_none())?;
            eval.validate(&o.expr)?;
            check(!catalog_expr::mentions(&o.expr, "SUM"))?;
            let reverse = match o.options.sort {
                None | Some(OrderBySort::Asc) => false,
                Some(OrderBySort::Desc) => true,
                _ => return Err("Unsupported metadata order".into()),
            };
            order.push((&o.expr, reverse));
        }
    }
    let target = s.selection.as_ref().and_then(|e| table_filter(e, &eval));
    let mut rows = info_rows(client, &kind, target.as_deref())?;
    let count_before = s
        .selection
        .as_ref()
        .is_some_and(|e| catalog_expr::mentions(e, "TABLE_ROWS"));
    let enrich = |client: &mut Client, rows: &mut Vec<Row>| -> Result<()> {
        for r in rows {
            let name = r["TABLE_NAME"]
                .as_str()
                .ok_or("Missing table name")?
                .to_string();
            r.insert("TABLE_ROWS".into(), json!(client.row_count(&name)?));
        }
        Ok(())
    };
    if count_before {
        enrich(client, &mut rows)?;
    }
    if let Some(e) = &s.selection {
        rows = rows
            .into_iter()
            .filter_map(|r| match eval.eval(e, &r) {
                Ok(v) if catalog_expr::truth(&v) == Some(true) => Some(Ok(r)),
                Ok(_) => None,
                Err(e) => Some(Err(e)),
            })
            .collect::<Result<Vec<_>>>()?;
    }
    if !count_before
        && (projections.iter().any(|(_, e)| {
            e.as_ref()
                .is_some_and(|e| catalog_expr::mentions(e, "TABLE_ROWS"))
        }) || order
            .iter()
            .any(|(e, _)| catalog_expr::mentions(e, "TABLE_ROWS")))
    {
        enrich(client, &mut rows)?;
    }
    let mut decorated = rows
        .into_iter()
        .map(|r| {
            Ok((
                order
                    .iter()
                    .map(|(e, _)| eval.eval(e, &r))
                    .collect::<Result<Vec<_>>>()?,
                r,
            ))
        })
        .collect::<Result<Vec<_>>>()?;
    decorated.sort_by(|a, b| {
        for (i, (_, reverse)) in order.iter().enumerate() {
            let cmp = catalog_expr::compare(&a.0[i], &b.0[i]);
            if cmp != std::cmp::Ordering::Equal {
                return if *reverse { cmp.reverse() } else { cmp };
            }
        }
        std::cmp::Ordering::Equal
    });
    let mut result = if count {
        vec![
            [(labels[0].clone(), json!(decorated.len().to_string()))]
                .into_iter()
                .collect(),
        ]
    } else {
        decorated
            .into_iter()
            .map(|(_, r)| {
                projections
                    .iter()
                    .map(|(label, e)| Ok((label.clone(), eval.eval(e.as_ref().unwrap(), &r)?)))
                    .collect()
            })
            .collect::<Result<Vec<Row>>>()?
    };
    if let Some(l) = &q.limit_clause {
        let (offset, count) = match l {
            LimitClause::OffsetCommaLimit { offset, limit: e } => {
                (limit(offset, &eval)?, Some(limit(e, &eval)?))
            }
            LimitClause::LimitOffset {
                limit: e,
                offset,
                limit_by,
            } => {
                check(limit_by.is_empty())?;
                (
                    offset
                        .as_ref()
                        .map(|o| limit(&o.value, &eval))
                        .transpose()?
                        .unwrap_or(0),
                    e.as_ref().map(|e| limit(e, &eval)).transpose()?,
                )
            }
        };
        result = result
            .into_iter()
            .skip(offset)
            .take(count.unwrap_or(usize::MAX))
            .collect();
    }
    Ok(Some(output(result, labels, "SELECT")))
}
pub fn query(client: &mut Client, shape: &Shape) -> Result<Option<Output>> {
    if let Some(command) = &shape.metadata {
        return show(client, command, shape).map(Some);
    }
    if let Statement::Query(q) = &shape.statement {
        return select(client, q, shape);
    }
    Ok(None)
}
