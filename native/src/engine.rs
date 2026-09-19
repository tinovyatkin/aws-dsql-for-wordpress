use crate::compiler::{self, Column, Plan, Result, Schema};
use aurora_dsql_sqlx_connector::{DsqlConnectOptionsBuilder, Region, pool};
use lru::LruCache;
use sqlx::{
    Column as _, Row, TypeInfo, ValueRef,
    postgres::{PgConnectOptions, PgPool, PgPoolOptions, PgRow},
};
use std::{
    collections::{BTreeMap, HashMap},
    num::NonZeroUsize,
    sync::{Arc, Mutex, OnceLock},
    time::{Duration, Instant},
};
use tokio::runtime::Runtime;

#[derive(Clone, Debug, Eq, Hash, PartialEq)]
pub struct Config {
    pub host: String,
    pub region: String,
    pub profile: String,
    pub user: String,
    pub schema: String,
    pub prefix: String,
    pub revision: String,
    pub credentials_file: Option<String>,
    pub value_codec: bool,
    pub sql_mode: String,
    pub cache_enabled: bool,
}
impl Config {
    pub fn validate(&self) -> Result<()> {
        if !self
            .host
            .ends_with(&format!(".dsql.{}.on.aws", self.region))
            || !["public", "wp_live"].contains(&self.schema.as_str())
            || self.prefix.is_empty()
            || self.revision.is_empty()
        {
            return Err(
                "Explicit DSQL endpoint, schema, prefix and schema revision required".into(),
            );
        }
        Ok(())
    }
}
pub(crate) struct Backend {
    pool: PgPool,
    plans: Mutex<LruCache<String, Plan>>,
    generation: Mutex<Option<String>>,
    schema: Mutex<Schema>,
    leases: std::sync::atomic::AtomicUsize,
}
static RUNTIME: OnceLock<Mutex<Option<Arc<Runtime>>>> = OnceLock::new();
static BACKENDS: OnceLock<Mutex<HashMap<Config, Arc<Backend>>>> = OnceLock::new();
// Created on the first operation in an FPM worker, never in the pre-fork master.
fn runtime() -> Arc<Runtime> {
    #[cfg(not(test))]
    static PANIC_HOOK: std::sync::Once = std::sync::Once::new();
    #[cfg(not(test))]
    PANIC_HOOK.call_once(|| {
        std::panic::set_hook(Box::new(|_| {
            eprintln!("Native DSQL internal failure (details suppressed)")
        }))
    });
    let mut slot = RUNTIME
        .get_or_init(|| Mutex::new(None))
        .lock()
        .expect("Runtime registry");
    slot.get_or_insert_with(|| {
        Arc::new(
            tokio::runtime::Builder::new_multi_thread()
                .worker_threads(1)
                .enable_all()
                .build()
                .expect("Tokio runtime"),
        )
    })
    .clone()
}
/// PHP module shutdown stops token refresh and runtime threads before unloading.
pub fn shutdown() {
    if RUNTIME.get().is_none() {
        return;
    }
    let _ = reset_worker();
    if let Some(rt) = RUNTIME.get().and_then(|slot| slot.lock().ok()?.take())
        && let Ok(rt) = Arc::try_unwrap(rt)
    {
        rt.shutdown_timeout(Duration::from_secs(2));
    }
}
fn is_transport(error: &sqlx::Error) -> bool {
    matches!(
        error,
        sqlx::Error::Io(_)
            | sqlx::Error::Tls(_)
            | sqlx::Error::Protocol(_)
            | sqlx::Error::PoolTimedOut
            | sqlx::Error::PoolClosed
            | sqlx::Error::WorkerCrashed
    ) || error
        .as_database_error()
        .and_then(|d| d.code())
        .is_some_and(|c| c.starts_with("08") || c == "57P01")
}
fn db_error(e: sqlx::Error) -> String {
    if let Some(code) = e
        .as_database_error()
        .and_then(|d| d.code())
        .filter(|c| c.len() == 5 && c.bytes().all(|b| b.is_ascii_alphanumeric()))
    {
        return format!("DSQL operation failed ({code}); not replayed");
    }
    if is_transport(&e) {
        "DSQL connection transport failed".into()
    } else {
        "DSQL result or protocol handling failed".into()
    }
}
fn backend(config: &Config) -> Result<(Arc<Backend>, bool)> {
    let mut all = BACKENDS
        .get_or_init(|| Mutex::new(HashMap::new()))
        .lock()
        .map_err(|_| "Pool registry poisoned")?;
    if let Some(b) = all.get(config) {
        return Ok((b.clone(), true));
    }
    if all.len() >= 8 {
        return Err("Native worker configuration limit reached".into());
    }
    let pg = PgConnectOptions::new()
        .host(&config.host)
        .username(&config.user)
        .database("postgres")
        .statement_cache_capacity(32);
    let mut builder = DsqlConnectOptionsBuilder::default();
    builder
        .pg_connect_options(pg)
        .region(Some(Region::new(config.region.clone())))
        .profile(Some(config.profile.clone()));
    if let Some(file) = &config.credentials_file {
        builder.credentials_provider(Some(
            aws_credential_types::provider::SharedCredentialsProvider::new(
                crate::auth::RotatingProfile {
                    file: file.clone(),
                    profile: config.profile.clone(),
                },
            ),
        ));
    }
    let options = builder.build().map_err(|_| "Invalid DSQL options")?;
    let schema = config.schema.clone();
    let opts = PgPoolOptions::new()
        .max_connections(2)
        .test_before_acquire(false)
        .min_connections(0)
        .before_acquire(|connection, metadata| {
            Box::pin(async move {
                if metadata.idle_for > Duration::from_secs(5) {
                    use sqlx::Connection;
                    connection.ping().await?;
                }
                Ok(true)
            })
        })
        .acquire_timeout(Duration::from_secs(15))
        .idle_timeout(Duration::from_secs(60))
        .max_lifetime(Duration::from_secs(3300))
        .after_connect(move |conn, _| {
            let sql = format!("SET search_path TO {}, pg_catalog", compiler::qi(&schema));
            Box::pin(async move {
                sqlx::query(sqlx::AssertSqlSafe(sql.as_str()))
                    .execute(conn)
                    .await?;
                Ok(())
            })
        });
    let p = runtime()
        .block_on(pool::connect_with(&options, opts))
        .map_err(|e| match e {
            aurora_dsql_sqlx_connector::DsqlError::ConnectionError(e)
            | aurora_dsql_sqlx_connector::DsqlError::DatabaseError(e) => db_error(e),
            aurora_dsql_sqlx_connector::DsqlError::TokenError(_) => {
                "DSQL credential resolution or signing failed".into()
            }
            _ => "DSQL connection configuration failed".into(),
        })?;
    let b = Arc::new(Backend {
        pool: p,
        plans: Mutex::new(LruCache::new(NonZeroUsize::new(256).unwrap())),
        generation: Mutex::new(None),
        schema: Mutex::new(Schema::new()),
        leases: std::sync::atomic::AtomicUsize::new(0),
    });
    all.insert(config.clone(), b.clone());
    Ok((b, false))
}
#[derive(Default, Clone)]
pub struct Output {
    pub rows: Vec<BTreeMap<String, Option<Vec<u8>>>>,
    pub columns: Vec<String>,
    pub metadata: Vec<BTreeMap<String, serde_json::Value>>,
    pub affected: u64,
    pub insert_id: String,
    pub operation: String,
    pub command: bool,
    pub stats: BTreeMap<String, f64>,
}
impl Output {
    pub fn scalar(name: &str, value: Option<String>) -> Self {
        Self {
            rows: vec![[(name.into(), value.map(String::into_bytes))].into()],
            columns: vec![name.into()],
            affected: 1,
            operation: "SELECT".into(),
            ..Self::default()
        }
    }
    pub fn command(op: &str) -> Self {
        Self {
            operation: op.into(),
            command: true,
            ..Self::default()
        }
    }
}
#[derive(Default, Clone)]
pub struct ErrorInfo {
    pub stage: String,
    pub operation: String,
    pub sqlstate: Option<String>,
    pub retries: u32,
    pub database: bool,
}
pub struct Client {
    pub(crate) config: Config,
    backend: Option<Arc<Backend>>,
    pub(crate) metadata: Schema,
    pub(crate) catalog: Option<crate::catalog::Catalog>,
    connection: Option<sqlx::pool::PoolConnection<sqlx::Postgres>>,
    acquired: Instant,
    epoch: Option<String>,
    transaction: bool,
    closed: bool,
    mode: String,
    found: Option<(compiler::Command, Vec<String>)>,
    pub error: ErrorInfo,
    pub queries: u64,
    pub hits: u64,
    pub misses: u64,
    pub prepared_hits: u64,
    prepared: std::collections::BTreeSet<[u8; 32]>,
}
impl Client {
    pub fn new(config: Config) -> Result<Self> {
        config.validate()?;
        let mode = crate::dialect::MysqlMode::new(&config.sql_mode)?
            .modes
            .join(",");
        Ok(Self {
            config,
            backend: None,
            metadata: Schema::new(),
            catalog: None,
            connection: None,
            acquired: Instant::now(),
            epoch: None,
            transaction: false,
            closed: false,
            mode,
            found: None,
            error: ErrorInfo::default(),
            queries: 0,
            hits: 0,
            misses: 0,
            prepared_hits: 0,
            prepared: Default::default(),
        })
    }
    pub fn remember_prepared(&mut self, sql: &[u8]) {
        use sha2::Digest;
        if sql.len() > 65536 {
            return;
        }
        self.prepared.insert(sha2::Sha256::digest(sql).into());
        if self.prepared.len() > 64 {
            self.prepared.pop_first();
        }
    }
    pub fn mode(&self) -> &str {
        &self.mode
    }
    pub fn connected(&self) -> bool {
        self.connection.is_some()
    }
    pub fn in_transaction(&self) -> bool {
        self.transaction
    }
    pub fn set_error_context(&mut self, stage: &str, operation: &str) {
        self.error = ErrorInfo {
            stage: stage.into(),
            operation: operation.into(),
            ..ErrorInfo::default()
        };
    }
    pub fn record_error(&mut self, message: &str) {
        if let Some(code) = message
            .strip_prefix("DSQL operation failed (")
            .and_then(|s| s.split(')').next())
            .filter(|s| s.len() == 5 && s.bytes().all(|b| b.is_ascii_alphanumeric()))
        {
            self.error.sqlstate = Some(code.into());
            self.error.database = true;
        }
        if message == "DSQL connection transport failed" {
            self.error.database = true;
        }
    }
    pub fn escape_bytes(&self, value: &[u8]) -> Result<Vec<u8>> {
        if self.closed {
            return Err("DSQL client is closed".into());
        }
        let no_backslash = self.mode.split(',').any(|m| m == "NO_BACKSLASH_ESCAPES");
        let mut out = Vec::new();
        for byte in value {
            if no_backslash {
                if *byte == b'\'' {
                    out.extend_from_slice(b"''");
                } else {
                    out.push(*byte);
                }
            } else {
                match byte {
                    b'\\' => out.extend_from_slice(b"\\\\"),
                    b'\'' => out.extend_from_slice(b"\\'"),
                    b'"' => out.extend_from_slice(b"\\\""),
                    0 => out.extend_from_slice(b"\\0"),
                    b'\n' => out.extend_from_slice(b"\\n"),
                    b'\r' => out.extend_from_slice(b"\\r"),
                    0x1a => out.extend_from_slice(b"\\Z"),
                    _ => out.push(*byte),
                }
            }
        }
        Ok(out)
    }
    pub fn escape(&self, value: &str) -> Result<String> {
        String::from_utf8(self.escape_bytes(value.as_bytes())?)
            .map_err(|_| "Escaping produced invalid UTF-8".into())
    }
    pub fn check_connection(&mut self) -> Result<bool> {
        if self.closed {
            return Err("DSQL client is closed".into());
        }
        if self.transaction && self.connection.is_none() {
            return Err("Transaction connection was lost; rollback is required".into());
        }
        let rt = runtime();
        let _scope = rt.enter();
        let mut reused = true;
        if self.backend.is_none() {
            let (b, r) = backend(&self.config)?;
            self.backend = Some(b);
            reused = r;
        }
        if self.connection.is_some()
            && !self.transaction
            && self.acquired.elapsed() >= Duration::from_secs(3300)
        {
            self.discard_connection();
            self.metadata.clear();
        }
        if self.connection.is_none() {
            self.connection = Some(
                rt.block_on(self.backend.as_ref().unwrap().pool.acquire())
                    .map_err(db_error)?,
            );
            self.backend
                .as_ref()
                .unwrap()
                .leases
                .fetch_add(1, std::sync::atomic::Ordering::SeqCst);
            self.epoch = self
                .backend
                .as_ref()
                .unwrap()
                .generation
                .lock()
                .map_err(|_| "Schema generation lock failed")?
                .clone();
            self.acquired = Instant::now();
        }
        Ok(reused)
    }
    fn discard_connection(&mut self) {
        if let Some(c) = self.connection.take() {
            if let Some(b) = &self.backend {
                b.leases.fetch_sub(1, std::sync::atomic::Ordering::SeqCst);
            }
            runtime().block_on(c.close()).ok();
        }
    }
    pub fn query(&mut self, sql: &str) -> Result<Output> {
        let start = Instant::now();
        self.error = ErrorInfo {
            stage: "translate".into(),
            ..ErrorInfo::default()
        };
        let result = self.query_inner(sql);
        match result {
            Ok(mut out) => {
                out.stats.insert(
                    "native_total_ms".into(),
                    start.elapsed().as_secs_f64() * 1000.,
                );
                Ok(out)
            }
            Err(e) => {
                if let Some(code) = e
                    .strip_prefix("DSQL operation failed (")
                    .and_then(|s| s.split(')').next())
                    .filter(|s| s.len() == 5 && s.bytes().all(|b| b.is_ascii_alphanumeric()))
                {
                    self.error.sqlstate = Some(code.into());
                    self.error.database = true;
                }
                if e == "DSQL connection transport failed" {
                    self.error.database = true;
                }
                Err(e)
            }
        }
    }
    fn query_inner(&mut self, sql: &str) -> Result<Output> {
        use sqlparser::ast::*;
        if self.closed {
            return Err("DSQL client is closed".into());
        }
        let rt = runtime();
        let _scope = rt.enter();
        let timer = Instant::now();
        if !self.prepared.is_empty() && sql.len() <= 65536 {
            use sha2::Digest;
            let key: [u8; 32] = sha2::Sha256::digest(sql.as_bytes()).into();
            if self.prepared.contains(&key) {
                self.prepared_hits += 1;
            }
        }
        let shape = compiler::shape_mode(sql, &self.mode, self.config.value_codec)?;
        let mut stats = BTreeMap::new();
        stats.insert("tokenize_ms".into(), timer.elapsed().as_secs_f64() * 1000.);
        let stmt = &shape.statement;
        // SET never changes server credentials, search_path or transaction settings.
        if let Statement::Set(set) = stmt {
            match set {
                Set::SetNames { .. } | Set::SetNamesDefault {} => {}
                Set::SingleAssignment {
                    variable,
                    values,
                    hivevar,
                    scope,
                } => {
                    if *hivevar
                        || scope
                            .as_ref()
                            .is_some_and(|s| !matches!(s, ContextModifier::Session))
                        || !variable.to_string().eq_ignore_ascii_case("sql_mode")
                        || values.len() != 1
                    {
                        return Err("Unsupported session setting".into());
                    }
                    let i = compiler::Compiler::slot(&values[0])
                        .ok_or("SQL mode requires a literal string")?;
                    if shape.numeric[i] {
                        return Err("SQL mode requires a literal string".into());
                    }
                    self.mode = crate::dialect::MysqlMode::new(&shape.values[i])?
                        .modes
                        .join(",");
                }
                _ => return Err("Unsupported session setting".into()),
            }
            self.prepared.clear();
            self.queries += 1;
            return Ok(Output::command("SET"));
        }
        let transaction = match stmt {
            Statement::StartTransaction {
                modes,
                modifier,
                statements,
                exception,
                has_end_keyword,
                ..
            } => {
                compiler::check(
                    modes.is_empty()
                        && modifier.is_none()
                        && statements.is_empty()
                        && exception.is_none()
                        && !*has_end_keyword,
                )?;
                Some("BEGIN")
            }
            Statement::Commit {
                chain,
                end,
                modifier,
            } => {
                compiler::check(!*chain && !*end && modifier.is_none())?;
                Some("COMMIT")
            }
            Statement::Rollback { chain, savepoint } => {
                compiler::check(!*chain && savepoint.is_none())?;
                Some("ROLLBACK")
            }
            _ => None,
        };
        if transaction == Some("ROLLBACK") && self.transaction && self.connection.is_none() {
            self.transaction = false;
            return Ok(Output::command("ROLLBACK"));
        }
        self.error.stage = "connect".into();
        let timer = Instant::now();
        let reused = self.check_connection()?;
        stats.insert("backend_reused".into(), if reused { 1. } else { 0. });
        stats.insert(
            "backend_init_ms".into(),
            timer.elapsed().as_secs_f64() * 1000.,
        );
        if let Some(command) = transaction {
            if command == "BEGIN" {
                self.ensure_catalog()?;
            }
            self.error.operation = command.into();
            self.error.stage = "execute".into();
            if command == "BEGIN" && self.transaction {
                return Err("Nested transaction rejected".into());
            }
            if command != "BEGIN" && !self.transaction {
                self.queries += 1;
                return Ok(Output::command(command));
            }
            let result =
                rt.block_on(sqlx::query(command).execute(&mut **self.connection.as_mut().unwrap()));
            self.queries += 1;
            if command == "BEGIN" {
                result.map_err(db_error)?;
                self.transaction = true;
            } else {
                self.transaction = false;
                if let Err(e) = result {
                    self.discard_connection();
                    return Err(db_error(e));
                }
            }
            return Ok(Output::command(command));
        }
        let compact = shape.key.replace(' ', "").to_ascii_uppercase();
        if ["SELECT@@AUTOCOMMIT", "SELECT@@SESSION.AUTOCOMMIT"].contains(&compact.as_str()) {
            self.queries += 1;
            return Ok(Output::scalar(
                "autocommit",
                Some(if self.transaction { "0" } else { "1" }.into()),
            ));
        }
        if ["SELECT@@SQL_MODE", "SELECT@@SESSION.SQL_MODE"].contains(&compact.as_str()) {
            self.queries += 1;
            return Ok(Output::scalar("sql_mode", Some(self.mode.clone())));
        }
        self.error.stage = "metadata".into();
        self.error.operation = match stmt {
            Statement::Query(_) => "SELECT",
            Statement::Insert(i) if i.replace_into => "REPLACE",
            Statement::Insert(_) => "INSERT",
            Statement::Update(_) => "UPDATE",
            Statement::Delete(_) => "DELETE",
            Statement::CreateTable(_) | Statement::CreateIndex(_) => "CREATE",
            Statement::AlterTable { .. } => "ALTER",
            Statement::Drop { .. } => "DROP",
            Statement::Truncate { .. } => "TRUNCATE",
            _ => "SHOW",
        }
        .into();
        self.ensure_catalog()?;
        if crate::ddl::is_ddl(&shape.statement) {
            self.error.stage = "schema".into();
        }
        if let Some(out) = crate::ddl::execute(self, &shape)? {
            return Ok(out);
        }
        if self
            .catalog
            .as_ref()
            .unwrap()
            .pending
            .iter()
            .any(|name| name.starts_with(&self.config.prefix))
        {
            return Err(
                "Incomplete installation schema; inspect the controlled upgrade journal".into(),
            );
        }
        if let Some(out) = crate::introspection::query(self, &shape)? {
            self.queries += 1;
            return Ok(out);
        }
        let key = format!("{}:{:?}:{}", self.mode, shape.numeric, shape.key);
        let b = self.backend.as_ref().unwrap().clone();
        let cached = if self.config.cache_enabled {
            b.plans
                .lock()
                .map_err(|_| "Plan cache lock failed")?
                .get(&key)
                .cloned()
        } else {
            None
        };
        let plan = if let Some(p) = cached {
            self.hits += 1;
            stats.insert("plan_hit".into(), 1.);
            p
        } else {
            self.misses += 1;
            self.error.stage = "metadata".into();
            let timer = Instant::now();
            for table in compiler::tables(stmt)? {
                if !self.metadata.contains_key(&table)
                    && let Some(cached) = b
                        .schema
                        .lock()
                        .map_err(|_| "Schema cache lock failed")?
                        .get(&table)
                        .cloned()
                {
                    self.metadata.insert(table.clone(), cached);
                }
                if !self.metadata.contains_key(&table) {
                    let result = rt.block_on(load_table(
                        &mut *self.connection.as_mut().unwrap(),
                        &self.config.schema,
                        &table,
                    ));
                    let table_meta = self
                        .metadata_result(result)?
                        .ok_or("DSQL operation failed (42P01); table is missing")?;
                    b.schema
                        .lock()
                        .map_err(|_| "Schema cache lock failed")?
                        .insert(table.clone(), table_meta.clone());
                    self.metadata.insert(table, table_meta);
                }
            }
            stats.insert("metadata_ms".into(), timer.elapsed().as_secs_f64() * 1000.);
            self.error.stage = "translate".into();
            let timer = Instant::now();
            let p = compiler::compile(stmt, &shape, &self.metadata)?;
            stats.insert("compile_ms".into(), timer.elapsed().as_secs_f64() * 1000.);
            stats.insert("plan_hit".into(), 0.);
            if self.config.cache_enabled && p.cacheable && key.len() + p.sql.len() < 32768 {
                b.plans
                    .lock()
                    .map_err(|_| "Plan cache poisoned")?
                    .put(key, p.clone());
            }
            p
        };
        self.error.operation = plan.operation.into();
        let (commands, owned) = if plan.operation == "FOUND_ROWS" {
            let (command, values) = self
                .found
                .as_ref()
                .ok_or("FOUND_ROWS called without SQL_CALC_FOUND_ROWS")?;
            (vec![bind_command(command, values)?], false)
        } else {
            if let Some(count) = &plan.count {
                self.found = Some((count.clone(), shape.values.clone()));
            }
            let mut commands = plan
                .leading
                .iter()
                .map(|c| bind_command(c, &shape.values))
                .collect::<Result<Vec<_>>>()?;
            commands.push(bind_command(
                &compiler::Command {
                    sql: plan.sql.clone(),
                    bindings: plan.bindings.clone(),
                },
                &shape.values,
            )?);
            (commands, !plan.leading.is_empty() && !self.transaction)
        };
        self.error.stage = "execute".into();
        let timer = Instant::now();
        let mut out = loop {
            let connection = self.connection.as_mut().unwrap();
            let mut affected = 0;
            let result = rt.block_on(async {
                if owned {
                    sqlx::query("BEGIN").execute(&mut **connection).await?;
                }
                let mut last = Output::default();
                for command in &commands {
                    let r = execute_command(connection, command).await?;
                    affected += r.affected;
                    last = r;
                }
                if owned {
                    sqlx::query("COMMIT").execute(&mut **connection).await?;
                }
                last.affected = affected;
                Ok::<_, sqlx::Error>(last)
            });
            self.queries += commands.len() as u64;
            match result {
                Ok(out) => break out,
                Err(e) => {
                    let conflict = aurora_dsql_sqlx_connector::is_occ_error(&e).is_some();
                    let transport = is_transport(&e);
                    let safe_read =
                        matches!(plan.operation, "SELECT" | "FOUND_ROWS") && !self.transaction;
                    if owned
                        && rt
                            .block_on(
                                sqlx::query("ROLLBACK")
                                    .execute(&mut **self.connection.as_mut().unwrap()),
                            )
                            .is_err()
                    {
                        self.discard_connection();
                    }
                    if !self.transaction
                        && ((conflict && self.error.retries < 3)
                            || (safe_read && transport && self.error.retries < 1))
                    {
                        self.error.retries += 1;
                        if transport {
                            self.discard_connection();
                        }
                        self.check_connection()?;
                        std::thread::sleep(Duration::from_millis(
                            10 * (1 << self.error.retries) + u64::from(std::process::id() % 17),
                        ));
                        continue;
                    }
                    if transport {
                        self.discard_connection();
                    }
                    return Err(db_error(e));
                }
            }
        };
        stats.insert("execute_ms".into(), timer.elapsed().as_secs_f64() * 1000.);
        self.error.stage = "decode".into();
        let timer = Instant::now();
        if self.config.value_codec {
            for row in &mut out.rows {
                for (name, value) in row {
                    let binary = out.metadata.iter().any(|m| {
                        m.get("name").and_then(|v| v.as_str()) == Some(name.as_str())
                            && m.get("native_type").and_then(|v| v.as_str()) == Some("bytea")
                    });
                    if !binary && let Some(value) = value {
                        let text =
                            std::str::from_utf8(value).map_err(|_| "Non-UTF8 textual result")?;
                        *value = crate::value::decode(text)?.into_bytes();
                    }
                }
            }
        }
        let shared_schema = b.schema.lock().map_err(|_| "Schema cache lock failed")?;
        for meta in &mut out.metadata {
            if let Some(oid) = meta.get("relation_oid").and_then(|v| v.as_u64())
                && let Some((table, physical)) = self
                    .metadata
                    .iter()
                    .chain(shared_schema.iter())
                    .find(|(_, t)| u64::from(t.oid) == oid)
            {
                meta.insert("table".into(), serde_json::json!(table));
                if let Some(attr) = meta.get("relation_attribute").and_then(|v| v.as_i64())
                    && let Some(col) = physical.iter().find(|c| i64::from(c.ordinal) == attr)
                {
                    meta.insert("orgname".into(), serde_json::json!(col.name));
                }
            }
        }
        if plan.identity {
            out.insert_id = out
                .rows
                .first()
                .and_then(|r| out.columns.first().and_then(|n| r.get(n)))
                .and_then(|v| v.as_ref())
                .and_then(|v| std::str::from_utf8(v).ok())
                .unwrap_or("0")
                .to_string();
        }
        out.operation = plan.operation.into();
        stats.insert("decode_ms".into(), timer.elapsed().as_secs_f64() * 1000.);
        out.stats = stats;
        Ok(out)
    }
    pub(crate) fn raw(&mut self, sql: String, values: Vec<String>) -> Result<Output> {
        self.check_connection()?;
        let result = runtime().block_on(execute_command(
            &mut *self.connection.as_mut().unwrap(),
            &BoundCommand { sql, values },
        ));
        self.metadata_result(result)
    }
    fn metadata_result<T>(&mut self, result: std::result::Result<T, sqlx::Error>) -> Result<T> {
        #[cfg(test)]
        let result = tests::inject_metadata_fault(result);
        result.map_err(|error| {
            if is_transport(&error) {
                self.discard_connection();
                self.catalog = None;
                self.metadata.clear();
                self.found = None;
                // Preserve caller transaction ownership: only ROLLBACK unlocks reconnect.
            }
            db_error(error)
        })
    }
    pub(crate) fn invalidate_schema(&mut self) -> Result<()> {
        self.catalog = None;
        self.metadata.clear();
        self.found = None;
        if let Some(b) = &self.backend {
            b.plans
                .lock()
                .map_err(|_| "Plan cache lock failed")?
                .clear();
            b.schema
                .lock()
                .map_err(|_| "Schema cache lock failed")?
                .clear();
            *b.generation.lock().map_err(|_| "Generation lock failed")? = None;
        }
        Ok(())
    }
    pub(crate) fn epoch_matches(&self) -> Result<bool> {
        if let Some(b) = &self.backend {
            Ok(self.epoch
                == *b
                    .generation
                    .lock()
                    .map_err(|_| "Schema generation lock failed")?)
        } else {
            Ok(false)
        }
    }
    pub(crate) fn generation(&mut self, generation: String) -> Result<()> {
        use sqlx::Connection;
        let b = self
            .backend
            .as_ref()
            .ok_or("Missing native backend")?
            .clone();
        let changed = {
            let mut old = b
                .generation
                .lock()
                .map_err(|_| "Schema generation lock failed")?;
            let changed = old.as_ref() != Some(&generation);
            if changed {
                *old = Some(generation.clone());
            }
            changed
        };
        if changed {
            b.plans
                .lock()
                .map_err(|_| "Plan cache lock failed")?
                .clear();
            b.schema
                .lock()
                .map_err(|_| "Schema cache lock failed")?
                .clear();
            self.metadata.clear();
            // Drain each currently idle/returning connection once. Other live clients
            // detect their stale epoch before reuse, or discard it when they close.
            let idle = (b.pool.size() as usize)
                .saturating_sub(b.leases.load(std::sync::atomic::Ordering::SeqCst));
            runtime().block_on(async {
                let mut idle_connections = Vec::new();
                for _ in 0..idle {
                    idle_connections.push(b.pool.acquire().await.map_err(db_error)?);
                }
                for connection in &mut idle_connections {
                    connection
                        .clear_cached_statements()
                        .await
                        .map_err(db_error)?;
                }
                Ok::<(), String>(())
            })?;
        }
        if changed || self.epoch.as_ref() != Some(&generation) {
            if self.transaction {
                return Err(
                    "Schema changed during a caller-owned transaction; rollback is required".into(),
                );
            }
            if let Some(c) = self.connection.as_mut() {
                runtime()
                    .block_on(c.clear_cached_statements())
                    .map_err(db_error)?;
            }
            self.metadata.clear();
            self.epoch = Some(generation);
        }
        Ok(())
    }
    pub fn reseed_identity(&mut self, table: &str, column: &str) -> Result<()> {
        if !table.starts_with(&self.config.prefix) {
            return Err("Identity table outside configured prefix".into());
        }
        self.ensure_catalog()?;
        let sql = format!(
            "SELECT setval(pg_get_serial_sequence($1,$2),(SELECT MAX({}) FROM {}.{}))",
            compiler::qi(column),
            compiler::qi(&self.config.schema),
            compiler::qi(table)
        );
        self.raw(
            sql,
            vec![
                format!(
                    "{}.{}",
                    compiler::qi(&self.config.schema),
                    compiler::qi(table)
                ),
                column.into(),
            ],
        )?;
        Ok(())
    }
    pub fn abort(&mut self) {
        self.error.stage = "internal".into();
        self.discard_connection();
        self.transaction = false;
        self.close();
    }
    pub fn close(&mut self) {
        if self.connection.is_some() {
            let rt = runtime();
            let _scope = rt.enter();
            if self.transaction || !self.epoch_matches().unwrap_or(false) {
                self.discard_connection();
            } else {
                if self.connection.is_some()
                    && let Some(b) = &self.backend
                {
                    b.leases.fetch_sub(1, std::sync::atomic::Ordering::SeqCst);
                }
                drop(self.connection.take());
            }
        }
        self.backend = None;
        self.metadata.clear();
        self.found = None;
        self.closed = true;
        self.transaction = false;
    }
}
impl Drop for Client {
    fn drop(&mut self) {
        self.close();
    }
}
struct BoundCommand {
    sql: String,
    values: Vec<String>,
}
fn bind_command(command: &compiler::Command, values: &[String]) -> Result<BoundCommand> {
    let mut params = vec![];
    for bind in &command.bindings {
        let mut v = values.get(bind.slot).ok_or("Binding mismatch")?.clone();
        if bind.binary {
            // BYTEA is transported as bound hex, never PostgreSQL backslash syntax
            // and never the reversible TEXT envelope.
            params.push(crate::value::hex(v.as_bytes()));
            continue;
        }
        if bind.temporal && v.starts_with("0000-00-00") {
            v.replace_range(..10, "0001-01-01");
        }
        if bind.format {
            v = crate::expression::date_format(&v)?;
        }
        if bind.numeric_prefix {
            v = crate::expression::numeric_prefix(&v);
        }
        if v.contains('\0') && !(bind.codec && bind.allow_nul) {
            return Err("NUL encoding requires direct assignment to unindexed TEXT".into());
        }
        if bind.codec {
            v = crate::value::encode(&v);
        }
        params.push(v);
    }
    Ok(BoundCommand {
        sql: crate::expression::resolve_numbers(&command.sql, values)?,
        values: params,
    })
}
async fn execute_command(
    c: &mut sqlx::PgConnection,
    command: &BoundCommand,
) -> std::result::Result<Output, sqlx::Error> {
    use sqlx::{Executor, SqlSafeStr, Statement, Type};
    let types = vec![<String as Type<sqlx::Postgres>>::type_info(); command.values.len()];
    // Compiler emits only validated identifiers/operators and bound values.
    let stmt = c
        .prepare_with(
            sqlx::AssertSqlSafe(command.sql.as_str()).into_sql_str(),
            &types,
        )
        .await?;
    let columns = stmt
        .columns()
        .iter()
        .map(|col| col.name().to_string())
        .collect();
    let metadata = stmt
        .columns()
        .iter()
        .map(|col| {
            [
                ("name".into(), serde_json::json!(col.name())),
                (
                    "native_type".into(),
                    serde_json::json!(col.type_info().name().to_ascii_lowercase()),
                ),
                (
                    "relation_oid".into(),
                    serde_json::json!(col.relation_id().map(|v| v.0)),
                ),
                (
                    "relation_attribute".into(),
                    serde_json::json!(col.relation_attribute_no()),
                ),
            ]
            .into()
        })
        .collect();
    let mut out = Output {
        columns,
        metadata,
        ..Output::default()
    };
    let mut query = stmt.query();
    for value in &command.values {
        query = query.bind(value);
    }
    if out.columns.is_empty() {
        out.affected = query.execute(c).await?.rows_affected();
    } else {
        let rows = query.fetch_all(c).await?;
        out.affected = rows.len() as u64;
        for row in rows {
            out.rows
                .push(decode(row).map_err(|e| sqlx::Error::Decode(e.into()))?);
        }
    }
    Ok(out)
}
fn decode(row: PgRow) -> Result<BTreeMap<String, Option<Vec<u8>>>> {
    let mut out = BTreeMap::new();
    for (i, col) in row.columns().iter().enumerate() {
        if row.try_get_raw(i).map_err(db_error)?.is_null() {
            out.insert(col.name().into(), None);
            continue;
        }
        let ty = col.type_info().name();
        if ty == "BYTEA" {
            out.insert(
                col.name().into(),
                Some(row.try_get::<Vec<u8>, _>(i).map_err(db_error)?),
            );
            continue;
        }
        let s = match ty {
            "INT8" => row.try_get::<i64, _>(i).map_err(db_error)?.to_string(),
            "INT4" => row.try_get::<i32, _>(i).map_err(db_error)?.to_string(),
            "INT2" => row.try_get::<i16, _>(i).map_err(db_error)?.to_string(),
            "FLOAT4" => row.try_get::<f32, _>(i).map_err(db_error)?.to_string(),
            "FLOAT8" => row.try_get::<f64, _>(i).map_err(db_error)?.to_string(),
            "NUMERIC" => {
                // SQLx's BigDecimal decoder derives scale from base-10000 digits.
                // Preserve the server's display scale, including declared DECIMAL zeros.
                let raw = row.try_get_raw(i).map_err(db_error)?;
                let decimal = row
                    .try_get::<sqlx::types::BigDecimal, _>(i)
                    .map_err(db_error)?;
                if raw.format() == sqlx::postgres::PgValueFormat::Binary {
                    let bytes = raw.as_bytes().map_err(|_| "Invalid numeric result")?;
                    let scale = bytes.get(6..8).ok_or("Invalid numeric header")?;
                    format!(
                        "{:.*}",
                        usize::from(u16::from_be_bytes([scale[0], scale[1]])),
                        decimal
                    )
                } else {
                    decimal.to_string()
                }
            }
            "BOOL" => if row.try_get::<bool, _>(i).map_err(db_error)? {
                "1"
            } else {
                "0"
            }
            .into(),
            "TIMESTAMP" => row
                .try_get::<chrono::NaiveDateTime, _>(i)
                .map_err(db_error)?
                .to_string(),
            "TIMESTAMPTZ" => row
                .try_get::<chrono::DateTime<chrono::Utc>, _>(i)
                .map_err(db_error)?
                .format("%Y-%m-%d %H:%M:%S%.f+00")
                .to_string(),
            "TIME" => row
                .try_get::<chrono::NaiveTime, _>(i)
                .map_err(db_error)?
                .to_string(),
            "TIMETZ" => {
                let time = row.try_get::<sqlx::postgres::types::PgTimeTz<chrono::NaiveTime,chrono::FixedOffset>, _>(i).map_err(db_error)?;
                format!("{}{}", time.time, time.offset)
            }
            "OID" => row
                .try_get::<sqlx::postgres::types::Oid, _>(i)
                .map_err(db_error)?
                .0
                .to_string(),
            "DATE" => row
                .try_get::<chrono::NaiveDate, _>(i)
                .map_err(db_error)?
                .to_string(),
            "JSON" | "JSONB" => {
                let raw = row.try_get_raw(i).map_err(db_error)?;
                crate::value::json_text(
                    raw.as_bytes().map_err(|_| "Invalid JSON result")?,
                    ty == "JSONB" && raw.format() == sqlx::postgres::PgValueFormat::Binary,
                )?
            }
            "TEXT" | "VARCHAR" | "BPCHAR" | "NAME" => {
                row.try_get::<String, _>(i).map_err(db_error)?
            }
            _ => return Err("Unsupported native result type".into()),
        };
        let s = if (ty == "DATE" || ty == "TIMESTAMP") && s.starts_with("0001-01-01") {
            s.replacen("0001-01-01", "0000-00-00", 1)
        } else {
            s
        };
        out.insert(col.name().into(), Some(s.into_bytes()));
    }
    Ok(out)
}

/// Benchmark/test isolation only. Refuse to reset a backend owned by a live client.
pub fn reset_worker() -> Result<()> {
    let rt = runtime();
    let _scope = rt.enter();
    if let Some(registry) = BACKENDS.get() {
        let mut backends = registry.lock().map_err(|_| "Pool registry poisoned")?;
        if backends.values().any(|b| Arc::strong_count(b) != 1) {
            return Err("Close all native clients before resetting the worker".into());
        }
        for (_, backend) in backends.drain() {
            runtime().block_on(backend.pool.close());
        }
    }
    Ok(())
}

async fn load_table(
    c: &mut sqlx::PgConnection,
    schema: &str,
    table: &str,
) -> std::result::Result<Option<compiler::Table>, sqlx::Error> {
    let relation = format!("{}.{}", compiler::qi(schema), compiler::qi(table));
    let rows=sqlx::query("SELECT attname,attnum,attrelid::text AS relation_id,atttypid::regtype::text AS ty,attidentity::text AS identity,NOT attnotnull AS nullable FROM pg_catalog.pg_attribute WHERE attrelid=to_regclass($1) AND attnum>0 AND NOT attisdropped ORDER BY attnum").bind(&relation).fetch_all(&mut *c).await?;
    let oid = rows
        .first()
        .and_then(|r| r.try_get::<String, _>("relation_id").ok())
        .and_then(|s| s.parse::<u32>().ok())
        .unwrap_or(0);
    let columns = rows
        .into_iter()
        .map(|r| {
            Ok(Column {
                name: r.try_get("attname")?,
                ty: r.try_get("ty")?,
                identity: !r.try_get::<String, _>("identity")?.is_empty(),
                nullable: r.try_get("nullable")?,
                default: None,
                ordinal: r.try_get("attnum")?,
            })
        })
        .collect::<std::result::Result<Vec<_>, sqlx::Error>>()?;
    if columns.is_empty() {
        return Ok(None);
    }
    let rows=sqlx::query("SELECT i.indexrelid::text AS id,i.indisprimary,i.indisunique,a.attname FROM pg_index i JOIN pg_class t ON t.oid=i.indrelid JOIN pg_namespace n ON n.oid=t.relnamespace CROSS JOIN LATERAL unnest(i.indkey) WITH ORDINALITY AS k(attnum,ordinality) JOIN pg_attribute a ON a.attrelid=t.oid AND a.attnum=k.attnum WHERE t.relname=$1 AND n.nspname=$2 AND i.indisvalid AND k.ordinality<=i.indnkeyatts ORDER BY i.indisprimary DESC,i.indexrelid,k.ordinality").bind(table).bind(schema).fetch_all(c).await?;
    let mut keys: BTreeMap<String, compiler::Index> = BTreeMap::new();
    for r in rows {
        let id: String = r.try_get("id")?;
        let key = keys.entry(id).or_default();
        key.primary = r.try_get("indisprimary")?;
        key.unique = r.try_get("indisunique")?;
        key.columns.push(r.try_get("attname")?);
    }
    Ok(Some(compiler::Table {
        columns,
        keys: keys.into_values().collect(),
        oid,
    }))
}

#[cfg(test)]
#[path = "engine_tests.rs"]
mod tests;
