use crate::compiler::{Compiler, Result, check, name, qi};
use sqlparser::ast::*;
use std::collections::BTreeSet;
impl Compiler<'_> {
    pub(crate) fn query(&mut self, q: &Query) -> Result<String> {
        let saved = self.aliases.clone();
        let saved_local = self.local_tables.clone();
        let result = self.query_inner(q);
        self.aliases = saved;
        self.local_tables = saved_local;
        result
    }
    fn projection_type(&self, e: &Expr) -> Option<&'static str> {
        if let Some(i) = Self::slot(e) {
            return Some(if self.shape.numeric[i] {
                "number"
            } else {
                "text"
            });
        }
        if let Some(t) = self.ty(e) {
            return Some(if Self::number_type(&t) {
                "number"
            } else if t == "date" || t.starts_with("timestamp") {
                "date"
            } else {
                "text"
            });
        }
        None
    }
    fn local_scope(&mut self, s: &Select) {
        let mut local = BTreeSet::new();
        for f in &s.from {
            for t in std::iter::once(&f.relation).chain(f.joins.iter().map(|j| &j.relation)) {
                if let TableFactor::Table { name: n, .. } = t
                    && let Ok(name) = name(n)
                {
                    local.insert(name);
                }
            }
        }
        if !local.is_empty() {
            self.local_tables = local;
        }
    }
    fn register(&mut self, f: &TableWithJoins) {
        for t in std::iter::once(&f.relation).chain(f.joins.iter().map(|j| &j.relation)) {
            if let TableFactor::Table { name: n, alias, .. } = t
                && let Ok(n) = name(n)
            {
                self.aliases.insert(n.clone(), n.clone());
                if let Some(a) = alias {
                    self.aliases.insert(a.name.value.clone(), n);
                }
            }
        }
    }
    fn projection_types(&mut self, body: &SetExpr) -> Vec<Vec<Option<&'static str>>> {
        match body {
            SetExpr::Select(s) => {
                let saved = self.aliases.clone();
                let saved_local = self.local_tables.clone();
                self.local_scope(s);
                for t in &s.from {
                    self.register(t);
                }
                let p =
                    s.projection
                        .iter()
                        .map(|p| match p {
                            SelectItem::UnnamedExpr(e)
                            | SelectItem::ExprWithAlias { expr: e, .. } => self.projection_type(e),
                            _ => None,
                        })
                        .collect();
                self.aliases = saved;
                self.local_tables = saved_local;
                vec![p]
            }
            SetExpr::Query(q) => self.projection_types(&q.body),
            SetExpr::SetOperation { left, right, .. } => {
                let mut v = self.projection_types(left);
                v.extend(self.projection_types(right));
                v
            }
            _ => vec![],
        }
    }
    fn set(&mut self, body: &SetExpr, text: &BTreeSet<usize>) -> Result<String> {
        match body {
            SetExpr::Select(s) => {
                let saved = self.aliases.clone();
                let old_local = self.local_tables.clone();
                self.local_scope(s);
                let result = self.select(s, text);
                self.aliases = saved;
                self.local_tables = old_local;
                result
            }
            SetExpr::Query(q) => Ok(format!("({})", self.query(q)?)),
            SetExpr::SetOperation {
                left,
                right,
                op,
                set_quantifier,
            } => {
                check(
                    *op == SetOperator::Union
                        && matches!(
                            set_quantifier,
                            SetQuantifier::None | SetQuantifier::All | SetQuantifier::Distinct
                        ),
                )?;
                Ok(format!(
                    "{} UNION {} {}",
                    self.set(left, text)?,
                    if *set_quantifier == SetQuantifier::All {
                        "ALL"
                    } else {
                        ""
                    },
                    self.set(right, text)?
                ))
            }
            _ => Err("Unsupported query body".into()),
        }
    }
    fn global_aggregate(e: &Expr) -> (bool, bool) {
        match e {
            Expr::Function(f)
                if [
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
                .contains(&f.name.to_string().to_ascii_uppercase().as_str()) =>
            {
                (true, false)
            }
            Expr::Identifier(_) | Expr::CompoundIdentifier(_) | Expr::Subquery(_) => (false, true),
            Expr::Nested(e) | Expr::Cast { expr: e, .. } => Self::global_aggregate(e),
            Expr::BinaryOp { left, right, .. } => {
                let a = Self::global_aggregate(left);
                let b = Self::global_aggregate(right);
                (a.0 || b.0, a.1 || b.1)
            }
            _ => (false, false),
        }
    }
    fn query_inner(&mut self, q: &Query) -> Result<String> {
        check(
            q.with.is_none()
                && q.fetch.is_none()
                && q.locks.is_empty()
                && q.for_clause.is_none()
                && q.settings.is_none()
                && q.format_clause.is_none()
                && q.pipe_operators.is_empty(),
        )?;
        let branches = self.projection_types(&q.body);
        let mut text = BTreeSet::new();
        for (i, _) in branches.first().into_iter().flatten().enumerate() {
            if branches.iter().any(|p| p.get(i) == Some(&Some("number")))
                && branches.iter().any(|p| p.get(i) == Some(&Some("text")))
            {
                text.insert(i);
            }
        }
        let mut effective = q.body.as_ref().clone();
        let mut calendar_order = None;
        let mut omit_order = false;
        if let SetExpr::Select(s) = &mut effective {
            self.local_scope(s);
            for f in &s.from {
                self.register(f);
            }
            if let Some(OrderBy {
                kind: OrderByKind::Expressions(order),
                ..
            }) = &q.order_by
            {
                let ungrouped = matches!(&s.group_by,GroupByExpr::Expressions(es,mods) if es.is_empty()&&mods.is_empty());
                if ungrouped {
                    let mut agg = false;
                    let mut free = false;
                    for p in &s.projection {
                        if let SelectItem::UnnamedExpr(e)
                        | SelectItem::ExprWithAlias { expr: e, .. } = p
                        {
                            let flags = Self::global_aggregate(e);
                            agg |= flags.0;
                            free |= flags.1;
                        } else {
                            free = true;
                        }
                    }
                    omit_order = agg
                        && !free
                        && order.iter().all(|o| {
                            matches!(o.expr, Expr::Identifier(_) | Expr::CompoundIdentifier(_))
                                && self.ty(&o.expr).is_some()
                        });
                }
                if s.distinct.is_some() && ungrouped && order.len() == 1 && s.projection.len() == 2
                {
                    let mut years = None;
                    let mut months = None;
                    for (i, p) in s.projection.iter().enumerate() {
                        if let SelectItem::UnnamedExpr(Expr::Function(f))
                        | SelectItem::ExprWithAlias {
                            expr: Expr::Function(f),
                            ..
                        } = p
                            && let FunctionArguments::List(a) = &f.args
                            && a.args.len() == 1
                            && matches!(&a.args[0],FunctionArg::Unnamed(FunctionArgExpr::Expr(e)) if e==&order[0].expr)
                        {
                            if f.name.to_string().eq_ignore_ascii_case("YEAR") {
                                years = Some(i + 1);
                            }
                            if f.name.to_string().eq_ignore_ascii_case("MONTH") {
                                months = Some(i + 1);
                            }
                        }
                    }
                    if let (Some(year), Some(month), Some(ty)) =
                        (years, months, self.ty(&order[0].expr))
                        && (ty == "date" || ty.starts_with("timestamp"))
                    {
                        calendar_order = Some(format!(
                            " ORDER BY {year}{}, {month}{}",
                            order[0].options, order[0].options
                        ));
                    }
                }
                // Core term ordering is valid with a unique term_id even when name isn't projected.
                if s.distinct.is_some()
                    && ungrouped
                    && s.having.is_none()
                    && order.len() == 1
                    && matches!(&order[0].expr,Expr::CompoundIdentifier(v) if v.len()==2&&v[0].value=="t"&&v[1].value=="name")
                    && let Some(table) = self.aliases.get("t").filter(|t| t.ends_with("_terms"))
                {
                    let valid=!s.projection.is_empty()&&s.projection.len()<=2&&s.projection.iter().all(|p|matches!(p,SelectItem::UnnamedExpr(Expr::CompoundIdentifier(v)) if v.len()==2&&((v[0].value=="t"&&v[1].value=="term_id")||(v[0].value=="tr"&&v[1].value=="object_id"))));
                    if valid {
                        check(
                            self.schema[table]
                                .keys
                                .iter()
                                .any(|k| k.unique && k.columns == ["term_id"])
                                && self.schema[table]
                                    .iter()
                                    .any(|c| c.name == "term_id" && !c.nullable),
                        )?;
                        let mut group = s
                            .projection
                            .iter()
                            .map(|p| match p {
                                SelectItem::UnnamedExpr(e) => e.clone(),
                                _ => unreachable!(),
                            })
                            .collect::<Vec<_>>();
                        group.push(order[0].expr.clone());
                        s.distinct = None;
                        s.group_by = GroupByExpr::Expressions(group, vec![]);
                    }
                }
            }
        }
        let mut sql = self.set(&effective, &text)?;
        if let Some(order) = calendar_order {
            sql.push_str(&order);
        } else if !omit_order && let Some(o) = &q.order_by {
            check(o.interpolate.is_none())?;
            let order = match &o.kind {
                OrderByKind::Expressions(es) => es,
                _ => return Err("Unsupported ORDER BY".into()),
            };
            let mut rendered = vec![];
            for e in order {
                check(e.with_fill.is_none() && e.options.nulls_first.is_none())?;
                let direction = match e.options.sort {
                    None | Some(OrderBySort::Asc) => " ASC NULLS FIRST",
                    Some(OrderBySort::Desc) => " DESC NULLS LAST",
                    _ => return Err("Unsupported ordering".into()),
                };
                rendered.push(format!("{}{direction}", self.ordinal(&e.expr)?));
            }
            sql.push_str(&format!(" ORDER BY {}", rendered.join(",")));
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
                    if let Some(e) = limit {
                        sql.push_str(&format!(" LIMIT {}", self.expr(e, Some("bigint"))?));
                    }
                    if let Some(e) = offset {
                        sql.push_str(&format!(" OFFSET {}", self.expr(&e.value, Some("bigint"))?));
                    }
                }
            }
        }
        Ok(sql)
    }
    fn select(&mut self, s: &Select, text: &BTreeSet<usize>) -> Result<String> {
        check(
            s.optimizer_hints.is_empty()
                && s.select_modifiers.as_ref().is_none_or(|m| {
                    !m.high_priority
                        && !m.straight_join
                        && !m.sql_small_result
                        && !m.sql_big_result
                        && !m.sql_buffer_result
                        && !m.sql_no_cache
                })
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
        let from=s.from.iter().filter(|t|!matches!(&t.relation,TableFactor::Table{name:n,..} if n.to_string().eq_ignore_ascii_case("dual"))).map(|f|self.from(f)).collect::<Result<Vec<_>>>()?.join(",");
        let mut projection = vec![];
        for (i, p) in s.projection.iter().enumerate() {
            let item = match p {
                SelectItem::UnnamedExpr(e) | SelectItem::ExprWithAlias { expr: e, .. } => {
                    let kind = self.projection_type(e);
                    let mut value = self.expr(e, None)?;
                    if text.contains(&i) && kind == Some("number") {
                        value = format!("CAST({value} AS text)");
                    }
                    if let SelectItem::ExprWithAlias { alias, .. } = p {
                        value.push_str(&format!(" AS {}", qi(&alias.value)));
                    }
                    value
                }
                SelectItem::Wildcard(o) if o.to_string().is_empty() => "*".into(),
                SelectItem::QualifiedWildcard(
                    SelectItemQualifiedWildcardKind::ObjectName(n),
                    o,
                ) if o.to_string().is_empty() => format!("{}.*", qi(&name(n)?)),
                _ => return Err("Unsupported projection".into()),
            };
            projection.push(item);
        }
        let distinct = match s.distinct {
            None => "",
            Some(Distinct::Distinct) => "DISTINCT ",
            _ => return Err("Unsupported DISTINCT".into()),
        };
        let mut sql = format!("SELECT {distinct}{}", projection.join(","));
        if !from.is_empty() {
            sql.push_str(&format!(" FROM {from}"));
        }
        if let Some(e) = &s.selection {
            sql.push_str(&format!(" WHERE {}", self.truth(e)?));
        }
        match &s.group_by {
            GroupByExpr::Expressions(es, mods) if mods.is_empty() => {
                if !es.is_empty() {
                    let es = es
                        .iter()
                        .map(|e| self.ordinal(e))
                        .collect::<Result<Vec<_>>>()?;
                    sql.push_str(&format!(" GROUP BY {}", es.join(",")));
                }
            }
            _ => return Err("Unsupported GROUP BY".into()),
        }
        if let Some(e) = &s.having {
            sql.push_str(&format!(" HAVING {}", self.truth(e)?));
        }
        Ok(sql)
    }
}
