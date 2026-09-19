//! Native MySQL → DSQL compiler, connection engine and PHP boundary.
pub mod auth;
pub mod catalog;
pub mod catalog_expr;
pub mod catalog_model;
pub mod compiler;
pub mod ddl;
pub mod diagnostics;
pub mod dialect;
pub mod engine;
pub mod expression;
pub mod introspection;
pub mod metadata_syntax;
pub mod queries;
pub mod value;
pub mod writes;

#[cfg(feature = "php")]
mod php {
    use crate::engine::{Client, Config};
    use ext_php_rs::{convert::IntoZval, prelude::*, types::Zval};
    use std::collections::HashMap;
    #[php_class]
    #[php(name = "DsqlNativeEngine")]
    pub struct NativeClient {
        client: Client,
    }
    fn error(s: String) -> PhpException {
        PhpException::default(s)
    }
    #[php_impl]
    impl NativeClient {
        pub fn api_version() -> i64 {
            1
        }
        pub fn engine_version() -> String {
            env!("CARGO_PKG_VERSION").into()
        }
        #[allow(clippy::too_many_arguments)] // Stable positional PHP boundary; no intermediate config serialization.
        pub fn __construct(
            host: String,
            region: String,
            profile: String,
            user: String,
            schema: String,
            prefix: String,
            revision: String,
            credentials_file: Option<String>,
            value_codec: Option<bool>,
            sql_mode: Option<String>,
            cache_enabled: Option<bool>,
        ) -> PhpResult<Self> {
            Ok(Self {
                client: Client::new(Config {
                    host,
                    region,
                    profile,
                    user,
                    schema,
                    prefix,
                    revision,
                    credentials_file,
                    value_codec: value_codec.unwrap_or(false),
                    sql_mode: sql_mode.unwrap_or_default(),
                    cache_enabled: cache_enabled.unwrap_or(true),
                })
                .map_err(error)?,
            })
        }
        /// SQL enters once; only final rows, affected count, identity and telemetry leave Rust.
        pub fn query(
            &mut self,
            sql: ext_php_rs::binary::Binary<u8>,
        ) -> PhpResult<HashMap<String, Zval>> {
            self.client.set_error_context("translate", "");
            let sql =
                std::str::from_utf8(&sql).map_err(|_| error("SQL input must be UTF-8".into()))?;
            let out = native_call(&mut self.client, |client| client.query(sql))?;
            let mut result = HashMap::new();
            let mut php_rows = Vec::new();
            for mut row in out.rows {
                let mut table = ext_php_rs::types::ZendHashTable::new();
                for name in &out.columns {
                    if let Some(value) = row.remove(name) {
                        table.insert(name.as_str(), value.map(ext_php_rs::binary::Binary::new))?;
                    }
                }
                php_rows.push(table);
            }
            result.insert("rows".into(), php_rows.into_zval(false)?);
            result.insert("columns".into(), out.columns.into_zval(false)?);
            result.insert(
                "metadata".into(),
                json_zval(
                    serde_json::to_value(out.metadata)
                        .map_err(|_| error("Result metadata conversion failed".into()))?,
                )?,
            );
            result.insert("operation".into(), out.operation.into_zval(false)?);
            result.insert("command".into(), out.command.into_zval(false)?);
            result.insert(
                "affected_rows".into(),
                (out.affected as i64).into_zval(false)?,
            );
            result.insert("insert_id".into(), out.insert_id.into_zval(false)?);
            result.insert(
                "timing".into(),
                out.stats
                    .into_iter()
                    .collect::<HashMap<_, _>>()
                    .into_zval(false)?,
            );
            Ok(result)
        }
        pub fn logical_table(&mut self, name: &str) -> PhpResult<Zval> {
            self.client.set_error_context("metadata", "SHOW");
            json_zval(
                native_call(&mut self.client, |client| client.logical_table(name))?
                    .unwrap_or(serde_json::Value::Null),
            )
        }
        pub fn check_connection(&mut self) -> PhpResult<bool> {
            self.client.set_error_context("connect", "");
            native_call(&mut self.client, |client| client.check_connection()).map(|_| true)
        }
        pub fn is_connected(&self) -> bool {
            self.client.connected()
        }
        pub fn in_transaction(&self) -> bool {
            self.client.in_transaction()
        }
        pub fn sql_mode(&self) -> String {
            self.client.mode().into()
        }
        pub fn escape(
            &mut self,
            value: ext_php_rs::binary::Binary<u8>,
        ) -> PhpResult<ext_php_rs::binary::Binary<u8>> {
            self.client.set_error_context("escape", "");
            native_call(&mut self.client, |client| client.escape_bytes(&value))
                .map(ext_php_rs::binary::Binary::new)
        }
        pub fn remember_prepared(&mut self, sql: ext_php_rs::binary::Binary<u8>) {
            self.client.remember_prepared(&sql);
        }
        pub fn reseed_identity(&mut self, table: &str, column: &str) -> PhpResult<()> {
            native_call(&mut self.client, |client| {
                client.reseed_identity(table, column)
            })
        }
        pub fn query_count(&self) -> i64 {
            self.client.queries as i64
        }
        pub fn cache_stats(&self) -> HashMap<String, i64> {
            [
                ("hits".into(), self.client.hits as i64),
                ("misses".into(), self.client.misses as i64),
                ("compilations".into(), self.client.misses as i64),
                ("prepared_hits".into(), self.client.prepared_hits as i64),
            ]
            .into()
        }
        pub fn log_error(
            &self,
            sql: ext_php_rs::binary::Binary<u8>,
            context: &str,
            source: &str,
            stage: &str,
            elapsed_ms: &Zval,
        ) -> PhpResult<Zval> {
            let elapsed_ms = elapsed_ms
                .double()
                .or_else(|| elapsed_ms.long().map(|v| v as f64))
                .unwrap_or(0.);
            let event = crate::diagnostics::event(
                &sql,
                &self.client.error,
                context,
                source,
                stage,
                elapsed_ms,
            );
            crate::diagnostics::emit(&event);
            json_zval(event)
        }
        pub fn error_info(&self) -> PhpResult<Zval> {
            let e = &self.client.error;
            json_zval(
                serde_json::json!({"stage":e.stage,"operation":e.operation,"sqlstate":e.sqlstate,"retries":e.retries}),
            )
        }
        pub fn close(&mut self) -> PhpResult<()> {
            native_call(&mut self.client, |client| {
                client.close();
                Ok(())
            })
        }
    }
    fn native_call<T>(
        client: &mut Client,
        operation: impl FnOnce(&mut Client) -> crate::compiler::Result<T>,
    ) -> PhpResult<T> {
        match std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| operation(client))) {
            Ok(result) => result.map_err(|message| {
                client.record_error(&message);
                error(message)
            }),
            Err(_) => {
                let _ = std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| client.abort()));
                Err(error(
                    "Native DSQL operation stopped after an internal failure".into(),
                ))
            }
        }
    }
    fn json_zval(value: serde_json::Value) -> PhpResult<Zval> {
        use serde_json::Value;
        Ok(match value {
            Value::Null => Option::<String>::None.into_zval(false)?,
            Value::Bool(b) => b.into_zval(false)?,
            Value::Number(n) => {
                if let Some(i) = n.as_i64() {
                    i.into_zval(false)?
                } else {
                    n.as_f64().unwrap_or(0.).into_zval(false)?
                }
            }
            Value::String(s) => s.into_zval(false)?,
            Value::Array(a) => a
                .into_iter()
                .map(json_zval)
                .collect::<PhpResult<Vec<_>>>()?
                .into_zval(false)?,
            Value::Object(o) => o
                .into_iter()
                .map(|(k, v)| Ok((k, json_zval(v)?)))
                .collect::<PhpResult<HashMap<_, _>>>()?
                .into_zval(false)?,
        })
    }
    #[php_function]
    pub fn dsql_native_reset_worker() -> PhpResult<()> {
        crate::engine::reset_worker().map_err(error)
    }
    unsafe extern "C" fn shutdown(_type: i32, _module_number: i32) -> i32 {
        if std::panic::catch_unwind(crate::engine::shutdown).is_ok() {
            0
        } else {
            -1
        }
    }
    #[php_module]
    pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
        module
            .class::<NativeClient>()
            .shutdown_function(shutdown)
            .function(wrap_function!(dsql_native_reset_worker))
    }
}
