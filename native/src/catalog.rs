use crate::{
    catalog_expr::Row,
    catalog_model as model,
    compiler::{Result, qi},
    engine::{Client, Output},
};
use serde_json::{Value, json};
use sha2::{Digest, Sha256};
use std::collections::BTreeMap;
#[derive(Default)]
pub struct Catalog {
    pub present: bool,
    pub managed: bool,
    pub pending: Vec<String>,
    pub models: BTreeMap<String, Option<Value>>,
}
pub fn rows(out: Output) -> Result<Vec<Row>> {
    let columns = out.columns;
    out.rows
        .into_iter()
        .map(|row| {
            columns
                .iter()
                .map(|k| {
                    Ok((
                        k.clone(),
                        match row.get(k).and_then(|v| v.as_ref()) {
                            Some(v) => Value::String(
                                String::from_utf8(v.clone())
                                    .map_err(|_| "Non-UTF8 catalog field")?,
                            ),
                            None => Value::Null,
                        },
                    ))
                })
                .collect()
        })
        .collect()
}
pub fn cell(row: &Row, name: &str) -> Option<String> {
    crate::catalog_expr::string(row.get(name).unwrap_or(&Value::Null))
}
impl Client {
    pub(crate) fn ensure_catalog(&mut self) -> Result<()> {
        if self.catalog.is_some() && self.epoch_matches()? {
            return Ok(());
        }
        if self.catalog.is_some() && self.in_transaction() {
            return Err(
                "Schema changed during a caller-owned transaction; rollback is required".into(),
            );
        }
        self.catalog = None;
        let relation = format!("{}.{}", qi(&self.config.schema), qi("__wp_dsql_schema"));
        let exists = rows(self.raw(
            "SELECT to_regclass($1)::text AS name".into(),
            vec![relation.clone()],
        )?)?
        .first()
        .and_then(|r| cell(r, "name"))
        .is_some();
        let mut catalog = Catalog {
            present: exists,
            managed: exists,
            ..Catalog::default()
        };
        let mut signature = String::new();
        if exists {
            let data=rows(self.raw(format!("SELECT table_name,fingerprint,CASE WHEN table_name='__catalog__' THEN metadata ELSE NULL END AS control FROM {relation} ORDER BY table_name"),vec![])?)?;
            for r in data {
                let table = cell(&r, "table_name").ok_or("Invalid catalog name")?;
                let fingerprint = cell(&r, "fingerprint").ok_or("Invalid catalog fingerprint")?;
                if fingerprint == "pending" {
                    catalog.pending.push(table.clone());
                }
                signature.push_str(&table);
                signature.push('\0');
                signature.push_str(&fingerprint);
                signature.push('\0');
                if table == "__catalog__" {
                    let value: Value = serde_json::from_str(
                        &cell(&r, "control").ok_or("Missing catalog control")?,
                    )
                    .map_err(|_| "Invalid catalog control")?;
                    if value["schema_version"] != 2 || !value["managed"].is_boolean() {
                        return Err("Native engine requires a version-2 logical catalog".into());
                    }
                    catalog.managed = value["managed"].as_bool().unwrap();
                }
            }
        }
        self.generation(format!("{:x}", Sha256::digest(signature.as_bytes())))?;
        self.catalog = Some(catalog);
        Ok(())
    }
    pub(crate) fn table_name(&self, parts: &[String]) -> Result<String> {
        if parts.len() == 2
            && !parts[0].eq_ignore_ascii_case("postgres")
            && !parts[0].eq_ignore_ascii_case(&self.config.schema)
        {
            return Err("Only the active database may be introspected".into());
        }
        if parts.is_empty() || parts.len() > 2 {
            return Err("Invalid metadata table name".into());
        }
        let name = parts.last().unwrap();
        if name.starts_with("__") {
            return Err("Internal catalog rows are not application tables".into());
        }
        Ok(name.clone())
    }
    pub fn logical_table(&mut self, name: &str) -> Result<Option<Value>> {
        self.ensure_catalog()?;
        let name = self.table_name(&name.split('.').map(str::to_string).collect::<Vec<_>>())?;
        if let Some(cached) = self.catalog.as_ref().unwrap().models.get(&name) {
            return Ok(cached.clone());
        }
        let mut result = None;
        if self.catalog.as_ref().unwrap().present {
            let rows = rows(self.raw(
                format!(
                    "SELECT metadata,fingerprint FROM {}.{} WHERE table_name=$1",
                    qi(&self.config.schema),
                    qi("__wp_dsql_schema")
                ),
                vec![name.clone()],
            )?)?;
            if let Some(row) = rows.first() {
                let mut table: Value =
                    serde_json::from_str(&cell(row, "metadata").ok_or("Missing table metadata")?)
                        .map_err(|_| "Invalid logical metadata")?;
                if table.get("state").is_some_and(|v| v != "ready") {
                    return Err("Incomplete installation schema".into());
                }
                let schema = table["target_schema"]
                    .as_str()
                    .unwrap_or(&self.config.schema)
                    .to_string();
                let fingerprint = self.fingerprint(&name, &schema)?;
                if cell(row, "fingerprint").as_deref() != Some(&fingerprint) {
                    return Err("DSQL physical schema differs from its logical metadata".into());
                }
                table["name"] = json!(name);
                result = Some(model::normalized(table)?);
            }
        }
        if result.is_none() {
            result = self.infer_table(&name)?;
        }
        self.catalog
            .as_mut()
            .unwrap()
            .models
            .insert(name, result.clone());
        Ok(result)
    }
    pub(crate) fn names(&mut self) -> Result<Vec<String>> {
        let out = rows(
            self.raw(
                "SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname=$1 ORDER BY tablename"
                    .into(),
                vec![self.config.schema.clone()],
            )?,
        )?;
        Ok(out
            .iter()
            .filter_map(|r| cell(r, "tablename"))
            .filter(|s| !s.starts_with("__"))
            .collect())
    }
    pub(crate) fn row_count(&mut self, name: &str) -> Result<String> {
        let out = rows(self.raw(
            format!(
                "SELECT COUNT(*) AS n FROM {}.{}",
                qi(&self.config.schema),
                qi(name)
            ),
            vec![],
        )?)?;
        out.first()
            .and_then(|r| cell(r, "n"))
            .ok_or("Missing row count".into())
    }
    pub(crate) fn fingerprint(&mut self, table: &str, schema: &str) -> Result<String> {
        let columns=rows(self.raw("SELECT column_name,data_type,is_nullable,column_default,character_maximum_length,numeric_precision,numeric_scale,datetime_precision,is_identity FROM information_schema.columns WHERE table_schema=$1 AND table_name=$2 ORDER BY ordinal_position".into(),vec![schema.into(),table.into()])?)?;
        if columns.is_empty() {
            return Err("Catalogued physical table is missing".into());
        }
        let indexes=rows(self.raw("SELECT indexname,indexdef FROM pg_indexes WHERE schemaname=$1 AND tablename=$2 ORDER BY indexname".into(),vec![schema.into(),table.into()])?)?;
        Ok(format!(
            "{:x}",
            Sha256::digest(model::php_json(&json!([columns, indexes])).as_bytes())
        ))
    }
    fn infer_table(&mut self, name: &str) -> Result<Option<Value>> {
        let source=rows(self.raw("SELECT column_name,data_type,is_nullable,column_default,character_maximum_length,is_identity FROM information_schema.columns WHERE table_schema=$1 AND table_name=$2 ORDER BY ordinal_position".into(),vec![self.config.schema.clone(),name.into()])?)?;
        if source.is_empty() {
            return Ok(None);
        }
        let default_re = regex::Regex::new(r"^'((?:[^']|'')*)'::").unwrap();
        let mut columns = vec![];
        for r in source {
            let ty = cell(&r, "data_type").ok_or("Invalid physical type")?;
            let ty = match ty.as_str() {
                "character varying" => format!(
                    "varchar({})",
                    cell(&r, "character_maximum_length").unwrap_or_default()
                ),
                "timestamp without time zone" => "datetime".into(),
                "integer" => "int".into(),
                _ => ty,
            };
            let mut default = cell(&r, "column_default");
            if let Some(s) = &default
                && let Some(c) = default_re.captures(s)
            {
                default = Some(c[1].replace("''", "'"));
            }
            if default.as_deref() == Some("0001-01-01 00:00:00") {
                default = Some("0000-00-00 00:00:00".into());
            }
            columns.push(json!({"Field":r["column_name"],"Type":ty,"Null":r["is_nullable"],"Key":"","Default":default,"HasDefault":default.is_some(),"DefaultExpression":false,"Extra":if cell(&r,"is_identity").as_deref()==Some("YES"){"auto_increment"}else{""},"Collation":null}));
        }
        let indexes=rows(self.raw("SELECT t.relname AS \"Table\",CASE WHEN i.indisunique THEN 0 ELSE 1 END AS \"Non_unique\",CASE WHEN i.indisprimary THEN 'PRIMARY' ELSE substr(idx.relname,length(t.relname)+2) END AS \"Key_name\",k.ordinality AS \"Seq_in_index\",a.attname AS \"Column_name\",NULL::text AS \"Sub_part\",'BTREE'::text AS \"Index_type\",'A'::text AS \"Collation\" FROM pg_index i JOIN pg_class t ON t.oid=i.indrelid JOIN pg_namespace n ON n.oid=t.relnamespace JOIN pg_class idx ON idx.oid=i.indexrelid CROSS JOIN LATERAL unnest(i.indkey) WITH ORDINALITY AS k(attnum,ordinality) JOIN pg_attribute a ON a.attrelid=t.oid AND a.attnum=k.attnum WHERE t.relname=$1 AND n.nspname=$2 AND i.indisvalid AND k.ordinality<=i.indnkeyatts ORDER BY idx.relname,k.ordinality".into(),vec![name.into(),self.config.schema.clone()])?)?;
        Ok(Some(model::normalized(
            json!({"name":name,"schema_version":2,"columns":columns,"indexes":indexes,"mysql_ddl":"","inferred":true}),
        )?))
    }
}
