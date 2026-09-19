//! Experimental complete MySQL → DSQL operation. Never loaded by the production drop-in.
pub mod compiler;
pub mod engine;
pub mod value;

#[cfg(feature = "php")]
mod php {
    use crate::engine::{Client, Config};
    use ext_php_rs::{convert::IntoZval, prelude::*, types::Zval};
    use std::collections::HashMap;
    #[php_class]
    #[php(name = "DsqlNativePrototype")]
    pub struct NativeClient {
        client: Client,
    }
    fn error(s: String) -> PhpException {
        PhpException::default(s)
    }
    #[php_impl]
    impl NativeClient {
        pub fn __construct(
            host: String,
            region: String,
            profile: String,
            user: String,
            schema: String,
            prefix: String,
            revision: String,
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
                })
                .map_err(error)?,
            })
        }
        /// SQL enters once; only final rows, affected count, identity and telemetry leave Rust.
        pub fn query(&mut self, sql: &str) -> PhpResult<HashMap<String, Zval>> {
            let out = self.client.query(sql).map_err(error)?;
            let mut result = HashMap::new();
            result.insert(
                "rows".into(),
                out.rows
                    .into_iter()
                    .map(|r| r.into_iter().collect::<HashMap<_, _>>())
                    .collect::<Vec<_>>()
                    .into_zval(false)?,
            );
            result.insert("columns".into(), out.columns.into_zval(false)?);
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
        pub fn close(&mut self) {
            self.client.close();
        }
    }
    #[php_function]
    pub fn dsql_native_reset_worker() -> PhpResult<()> {
        crate::engine::reset_worker().map_err(error)
    }
    unsafe extern "C" fn shutdown(_type: i32, _module_number: i32) -> i32 {
        crate::engine::shutdown();
        0
    }
    #[php_module]
    pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
        module
            .class::<NativeClient>()
            .shutdown_function(shutdown)
            .function(wrap_function!(dsql_native_reset_worker))
    }
}
