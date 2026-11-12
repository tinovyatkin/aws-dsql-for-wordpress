use crate::compiler::{self, Column, Plan, Result, Schema};
use aurora_dsql_sqlx_connector::{DsqlConnectOptions, DsqlConnectOptionsBuilder, Region};
use lru::LruCache;
use sqlx::{
    Column as _, Connection as _, Row, TypeInfo, ValueRef,
    postgres::{PgConnectOptions, PgConnection, PgRow},
};
use std::{
    collections::{BTreeMap, HashMap},
    num::NonZeroUsize,
    sync::{
        Arc, Mutex, OnceLock, Weak,
        atomic::{AtomicUsize, Ordering},
    },
    time::{Duration, Instant},
};
use tokio::{runtime::Runtime, sync::Notify};

/// Prepared statements kept per connection, the same bound as the compiled-plan cache.
/// Past it SQLx closes one statement for every new one, a third round trip per query.
const STATEMENT_CACHE: usize = 256;
/// DSQL ends every connection after one hour; none is used beyond this age.
const MAX_LIFETIME: Duration = Duration::from_secs(3300);
/// From this age the worker's parked connection is replaced between requests.
const REFRESH_AGE: Duration = Duration::from_secs(3000);
const REFRESH_INTERVAL: Duration = Duration::from_secs(30);
/// A parked connection unused for this long is verified between requests.
const KEEPALIVE_IDLE: Duration = Duration::from_secs(240);
const PROBE_TIMEOUT: Duration = Duration::from_secs(5);
/// A connection that has not answered since it was parked gets this long to start
/// answering a replayable read; past it the read is replayed on a new connection.
const UNPROVEN_READ_TIMEOUT: Duration = Duration::from_secs(15);
/// Closing is a courtesy to the server; a dead socket must not hold up the worker.
const CLOSE_TIMEOUT: Duration = Duration::from_secs(2);
const CONNECT_TIMEOUT: Duration = Duration::from_secs(15);
/// A connection parked longer than this may have been dropped by the network.
const PROBE_AFTER_IDLE: Duration = Duration::from_secs(5);
/// Simultaneous clients of one worker configuration, each on its own connection.
const MAX_LEASES: usize = 2;
/// Up to this many tables a schema's physical metadata is read whole, once per generation.
const WHOLE_SCHEMA_TABLES: usize = 32;

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
    /// Directory whose modification time changes with every coordinated schema operation.
    /// With it a worker keeps its validated catalog across requests; without it each
    /// request reads the catalog generation from the database.
    pub schema_signal: Option<String>,
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
/// The worker's connection while no client holds it.
struct Parked {
    connection: PgConnection,
    created: Instant,
    since: Instant,
    /// Schema generation its prepared statements belong to; `None` when it has none yet.
    epoch: Option<String>,
}
/// Ends a connection without letting an unresponsive peer delay the caller.
async fn close_quietly(connection: PgConnection) {
    let _ = tokio::time::timeout(CLOSE_TIMEOUT, connection.close()).await;
}
fn timed_out() -> sqlx::Error {
    sqlx::Error::Io(std::io::Error::from(std::io::ErrorKind::TimedOut))
}
pub(crate) struct Backend {
    options: DsqlConnectOptions,
    search_path: String,
    /// A PHP worker runs one request at a time, so one retained connection serves it.
    /// Holding it here, not in a pool, makes the hand-over synchronous and its age known.
    parked: Mutex<Option<Parked>>,
    plans: Mutex<LruCache<String, Plan>>,
    generation: Mutex<Option<String>>,
    schema: Mutex<Schema>,
    pub(crate) catalog: Mutex<Option<crate::catalog::SharedCatalog>>,
    clients: AtomicUsize,
    leases: AtomicUsize,
    wake: Arc<Notify>,
    refresher: Mutex<Option<tokio::task::JoinHandle<()>>>,
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
        .statement_cache_capacity(STATEMENT_CACHE);
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
    let wake = Arc::new(Notify::new());
    let b = Arc::new(Backend {
        options,
        search_path: format!(
            "SET search_path TO {}, pg_catalog",
            compiler::qi(&config.schema)
        ),
        parked: Mutex::new(None),
        plans: Mutex::new(LruCache::new(NonZeroUsize::new(256).unwrap())),
        generation: Mutex::new(None),
        schema: Mutex::new(Schema::new()),
        catalog: Mutex::new(None),
        clients: AtomicUsize::new(0),
        leases: AtomicUsize::new(0),
        wake: wake.clone(),
        refresher: Mutex::new(None),
    });
    let task = runtime().spawn(keep_connection_fresh(Arc::downgrade(&b), wake));
    *b.refresher.lock().map_err(|_| "Pool registry poisoned")? = Some(task);
    all.insert(config.clone(), b.clone());
    Ok((b, false))
}
/// One authenticated connection on the application search path. The IAM token is signed
/// for each connection, so no refresh task has to outlive a request.
async fn open(options: &DsqlConnectOptions, search_path: &str) -> Result<PgConnection> {
    use aurora_dsql_sqlx_connector::DsqlError;
    let attempt = async {
        let mut connection = aurora_dsql_sqlx_connector::connection::connect_with(options)
            .await
            .map_err(|e| match e {
                DsqlError::ConnectionError(e) | DsqlError::DatabaseError(e) => db_error(e),
                DsqlError::TokenError(_) => {
                    String::from("DSQL credential resolution or signing failed")
                }
                _ => "DSQL connection configuration failed".into(),
            })?;
        sqlx::raw_sql(sqlx::AssertSqlSafe(search_path))
            .execute(&mut connection)
            .await
            .map_err(db_error)?;
        Ok(connection)
    };
    tokio::time::timeout(CONNECT_TIMEOUT, attempt)
        .await
        .unwrap_or_else(|_| Err("DSQL connection transport failed".into()))
}
/// Runs on the worker's runtime thread, which stays alive between PHP requests. It replaces
/// the parked connection before DSQL's one-hour limit and opens one after a discard, so the
/// TLS and authentication handshake rarely falls inside a request.
async fn keep_connection_fresh(backend: Weak<Backend>, wake: Arc<Notify>) {
    loop {
        tokio::select! {
            _ = tokio::time::sleep(REFRESH_INTERVAL) => {}
            _ = wake.notified() => {}
        }
        let Some(b) = backend.upgrade() else { return };
        // Decide under the lock, act outside it: the lock is never held across an await.
        let (mut replace, idle) = match b.parked.lock() {
            Ok(mut slot) => match slot.as_ref() {
                Some(parked) if parked.created.elapsed() >= REFRESH_AGE => (true, None),
                Some(parked) if parked.since.elapsed() >= KEEPALIVE_IDLE => (false, slot.take()),
                Some(_) => (false, None),
                None => (b.leases.load(Ordering::SeqCst) == 0, None),
            },
            Err(_) => return,
        };
        // A connection nobody has used for a while is checked here, not by a visitor.
        let mut candidate = None;
        if let Some(mut parked) = idle {
            let alive = tokio::time::timeout(PROBE_TIMEOUT, parked.connection.ping()).await;
            if matches!(alive, Ok(Ok(()))) {
                parked.since = Instant::now();
                // It keeps its epoch: a generation change while it was out is detected
                // when a request next takes it.
                candidate = Some(parked);
            } else {
                close_quietly(parked.connection).await;
                replace = true;
            }
        }
        // While a replacement connects, an ageing connection stays available to requests.
        if replace && let Ok(connection) = open(&b.options, &b.search_path).await {
            candidate = Some(Parked {
                connection,
                created: Instant::now(),
                since: Instant::now(),
                epoch: None,
            });
        }
        let Some(candidate) = candidate else { continue };
        let retired = match b.parked.lock() {
            Ok(mut slot) => match slot.take() {
                // A request parked a usable connection in the meantime; keep that one.
                Some(current) if current.created.elapsed() < REFRESH_AGE => {
                    *slot = Some(current);
                    Some(candidate.connection)
                }
                old => {
                    *slot = Some(candidate);
                    old.map(|parked| parked.connection)
                }
            },
            Err(_) => Some(candidate.connection),
        };
        drop(b);
        if let Some(connection) = retired {
            close_quietly(connection).await;
        }
    }
}
#[derive(Default, Clone)]
pub struct Output {
    /// Cells follow column ordinals, including duplicate names.
    pub rows: Vec<Vec<Option<Vec<u8>>>>,
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
            rows: vec![vec![value.map(String::into_bytes)]],
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
    connection: Option<PgConnection>,
    /// When the held connection was opened, by whichever client or task opened it.
    created: Instant,
    /// The held connection sat parked long enough that it may no longer be alive.
    probe: bool,
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
    connection_check_ms: f64,
    connection_acquire_ms: f64,
    connection_acquisitions: u64,
    connections_opened: u64,
    connection_health_checks: u64,
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
            created: Instant::now(),
            probe: false,
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
            connection_check_ms: 0.,
            connection_acquire_ms: 0.,
            connection_acquisitions: 0,
            connections_opened: 0,
            connection_health_checks: 0,
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
    /// Cumulative, value-free telemetry includes wpdb's pre-query connection check.
    pub fn connection_stats(&self) -> HashMap<String, f64> {
        HashMap::from([
            ("check_ms".into(), self.connection_check_ms),
            ("acquire_ms".into(), self.connection_acquire_ms),
            ("acquisitions".into(), self.connection_acquisitions as f64),
            ("opened".into(), self.connections_opened as f64),
            ("health_checks".into(), self.connection_health_checks as f64),
        ])
    }
    pub fn check_connection(&mut self) -> Result<bool> {
        let start = Instant::now();
        let result = self.check_connection_inner();
        self.connection_check_ms += start.elapsed().as_secs_f64() * 1000.;
        result
    }
    fn check_connection_inner(&mut self) -> Result<bool> {
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
            b.clients.fetch_add(1, Ordering::SeqCst);
            self.backend = Some(b);
            reused = r;
        }
        if self.connection.is_some() && !self.transaction && self.created.elapsed() >= MAX_LIFETIME
        {
            self.discard_connection();
            self.metadata.clear();
        }
        if self.connection.is_none() {
            let b = self.backend.as_ref().unwrap().clone();
            if b.leases.fetch_add(1, Ordering::SeqCst) >= MAX_LEASES {
                b.leases.fetch_sub(1, Ordering::SeqCst);
                return Err("Native worker connection limit reached".into());
            }
            let start = Instant::now();
            let held = self.take_connection(&b, &rt);
            self.connection_acquire_ms += start.elapsed().as_secs_f64() * 1000.;
            self.connection_acquisitions += 1;
            let (connection, created, probe, epoch) = held.inspect_err(|_| {
                b.leases.fetch_sub(1, Ordering::SeqCst);
            })?;
            self.connection = Some(connection);
            self.created = created;
            self.probe = probe;
            self.epoch = epoch;
        }
        Ok(reused)
    }
    /// The worker's parked connection if it is still usable, otherwise a new one. A parked
    /// connection prepared statements under an older schema generation (the background
    /// keep-alive may have held it while a request detected the change); those are cleared
    /// here, so its epoch is never simply relabelled as current.
    #[allow(clippy::type_complexity)]
    fn take_connection(
        &mut self,
        b: &Arc<Backend>,
        rt: &Runtime,
    ) -> Result<(PgConnection, Instant, bool, Option<String>)> {
        let current = b
            .generation
            .lock()
            .map_err(|_| "Schema generation lock failed")?
            .clone();
        let parked = b
            .parked
            .lock()
            .map_err(|_| "Connection slot lock failed")?
            .take();
        match parked {
            Some(mut parked) if parked.created.elapsed() < MAX_LIFETIME => {
                if parked.epoch.is_none() || parked.epoch == current {
                    return Ok((
                        parked.connection,
                        parked.created,
                        parked.since.elapsed() > PROBE_AFTER_IDLE,
                        current,
                    ));
                }
                let cleared = rt.block_on(tokio::time::timeout(
                    PROBE_TIMEOUT,
                    parked.connection.clear_cached_statements(),
                ));
                if matches!(cleared, Ok(Ok(()))) {
                    // The exchange also proved the connection alive.
                    return Ok((parked.connection, parked.created, false, current));
                }
                rt.block_on(close_quietly(parked.connection));
            }
            Some(expired) => rt.block_on(close_quietly(expired.connection)),
            None => {}
        }
        self.connections_opened += 1;
        rt.block_on(open(&b.options, &b.search_path))
            .map(|connection| (connection, Instant::now(), false, current))
    }
    /// A read outside a transaction is simply replayed on a new connection if the parked
    /// one turns out to be dead. Anything else cannot be replayed, so it is probed first.
    fn settle(&mut self, replayable: bool) -> Result<()> {
        if !self.probe || replayable || self.connection.is_none() {
            return Ok(());
        }
        self.probe = false;
        let start = Instant::now();
        self.connection_health_checks += 1;
        // Bounded: a connection that stopped answering without closing must not hold
        // the worker. It is then discarded and replaced.
        let alive = matches!(
            runtime().block_on(tokio::time::timeout(
                PROBE_TIMEOUT,
                self.connection.as_mut().unwrap().ping()
            )),
            Ok(Ok(()))
        );
        let result = if alive {
            Ok(())
        } else {
            self.discard_connection();
            self.check_connection_inner().map(|_| ())
        };
        self.connection_check_ms += start.elapsed().as_secs_f64() * 1000.;
        result
    }
    fn discard_connection(&mut self) {
        if let Some(c) = self.connection.take() {
            if let Some(b) = &self.backend {
                b.leases.fetch_sub(1, Ordering::SeqCst);
            }
            self.probe = false;
            runtime().block_on(close_quietly(c));
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
                    // A missing relation or column, a changed result type: the schema may
                    // have moved under the worker's retained catalog. Read it again next time.
                    if ["42P01", "42703", "42804", "0A000"].contains(&code) {
                        self.forget_shared_catalog();
                    }
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
            self.settle(false)?;
            // The simple protocol needs no prepared statement: one round trip, always.
            let result =
                rt.block_on(sqlx::raw_sql(command).execute(self.connection.as_mut().unwrap()));
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
        // Catalog validation is metadata work too; report it with the table lookups.
        let timer = Instant::now();
        self.ensure_catalog()?;
        stats.insert("metadata_ms".into(), timer.elapsed().as_secs_f64() * 1000.);
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
            let tables = compiler::tables(stmt)?;
            let mut missing = Vec::new();
            {
                let shared = b.schema.lock().map_err(|_| "Schema cache lock failed")?;
                for table in tables {
                    if self.metadata.contains_key(&table) {
                        continue;
                    }
                    if let Some(cached) = shared.get(&table) {
                        self.metadata.insert(table, cached.clone());
                    } else {
                        missing.push(table);
                    }
                }
            }
            if !missing.is_empty() {
                self.settle(false)?;
                let result = rt.block_on(load_tables(
                    self.connection.as_mut().unwrap(),
                    &self.config.schema,
                    &missing,
                ));
                let loaded = self.metadata_result(result)?;
                // Do not publish a partial schema if one requested relation is absent.
                if missing.iter().any(|table| !loaded.contains_key(table)) {
                    return Err("DSQL operation failed (42P01); table is missing".into());
                }
                stats.insert("metadata_tables_loaded".into(), loaded.len() as f64);
                stats.insert("metadata_round_trips".into(), 2.);
                b.schema
                    .lock()
                    .map_err(|_| "Schema cache lock failed")?
                    .extend(loaded.clone());
                self.metadata.extend(loaded);
            }
            *stats.entry("metadata_ms".into()).or_insert(0.) +=
                timer.elapsed().as_secs_f64() * 1000.;
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
        let safe_read = matches!(plan.operation, "SELECT" | "FOUND_ROWS") && !self.transaction;
        let replayable = safe_read && !owned;
        self.settle(replayable)?;
        let timer = Instant::now();
        let mut out = loop {
            // A connection not heard from since it was parked may have gone silent without
            // closing. Bound a replayable read on it, then replay it on a new connection.
            let bounded = replayable && self.probe;
            let connection = self.connection.as_mut().unwrap();
            let mut affected = 0;
            let work = async {
                if owned {
                    sqlx::raw_sql("BEGIN").execute(&mut *connection).await?;
                }
                let mut last = Output::default();
                for command in &commands {
                    let r = execute_command(connection, command).await?;
                    affected += r.affected;
                    last = r;
                }
                if owned {
                    sqlx::raw_sql("COMMIT").execute(&mut *connection).await?;
                }
                last.affected = affected;
                Ok::<_, sqlx::Error>(last)
            };
            let result = if bounded {
                rt.block_on(tokio::time::timeout(UNPROVEN_READ_TIMEOUT, work))
                    .unwrap_or_else(|_| Err(timed_out()))
            } else {
                rt.block_on(work)
            };
            self.queries += commands.len() as u64;
            // Whatever the outcome, the connection has now been exercised.
            let unproven = std::mem::take(&mut self.probe);
            match result {
                Ok(out) => break out,
                Err(e) => {
                    let conflict = aurora_dsql_sqlx_connector::is_occ_error(&e).is_some();
                    let transport = is_transport(&e);
                    if owned
                        && rt
                            .block_on(
                                sqlx::raw_sql("ROLLBACK")
                                    .execute(self.connection.as_mut().unwrap()),
                            )
                            .is_err()
                    {
                        self.discard_connection();
                    }
                    // The parked connection had gone away: replay on a new one. This stands
                    // in for the liveness check and is not counted as a retry of the read.
                    if replayable && transport && unproven {
                        self.discard_connection();
                        self.check_connection()?;
                        continue;
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
                for (i, value) in row.iter_mut().enumerate() {
                    let binary = out
                        .metadata
                        .get(i)
                        .and_then(|m| m.get("native_type"))
                        .and_then(|v| v.as_str())
                        == Some("bytea");
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
                .and_then(|r| r.first())
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
        self.settle(false)?;
        let result = runtime().block_on(execute_command(
            self.connection.as_mut().unwrap(),
            &BoundCommand { sql, values },
        ));
        self.metadata_result(result)
    }
    /// A catalog read. Outside a transaction it needs no liveness check either: if the
    /// parked connection has gone away, the read is replayed once on a new one.
    pub(crate) fn raw_read(&mut self, sql: String, values: Vec<String>) -> Result<Output> {
        self.check_connection()?;
        if self.transaction || !std::mem::take(&mut self.probe) {
            return self.raw(sql, values);
        }
        let command = BoundCommand { sql, values };
        let mut result = runtime()
            .block_on(tokio::time::timeout(
                UNPROVEN_READ_TIMEOUT,
                execute_command(self.connection.as_mut().unwrap(), &command),
            ))
            .unwrap_or_else(|_| Err(timed_out()));
        if result.as_ref().is_err_and(is_transport) {
            self.discard_connection();
            self.check_connection()?;
            result =
                runtime().block_on(execute_command(self.connection.as_mut().unwrap(), &command));
        }
        self.metadata_result(result)
    }
    pub(crate) fn backend_catalog(&self) -> Result<Option<crate::catalog::SharedCatalog>> {
        match &self.backend {
            Some(b) => Ok(b
                .catalog
                .lock()
                .map_err(|_| "Catalog cache lock failed")?
                .clone()),
            None => Ok(None),
        }
    }
    pub(crate) fn share_catalog(
        &self,
        catalog: Option<crate::catalog::SharedCatalog>,
    ) -> Result<()> {
        if let Some(b) = &self.backend {
            *b.catalog.lock().map_err(|_| "Catalog cache lock failed")? = catalog;
        }
        Ok(())
    }
    /// The next client reads the catalog from the database instead of trusting this worker's copy.
    pub(crate) fn forget_shared_catalog(&self) {
        self.share_catalog(None).ok();
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
        self.forget_shared_catalog();
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
            *b.catalog.lock().map_err(|_| "Catalog cache lock failed")? = None;
            // The parked connection's prepared statements belong to the old generation.
            // Other live clients detect their stale epoch before reuse, or when they close.
            let parked = b
                .parked
                .lock()
                .map_err(|_| "Connection slot lock failed")?
                .take();
            if let Some(mut parked) = parked {
                let cleared = runtime().block_on(tokio::time::timeout(
                    PROBE_TIMEOUT,
                    parked.connection.clear_cached_statements(),
                ));
                let surplus = if matches!(cleared, Ok(Ok(()))) {
                    parked.epoch = Some(generation.clone());
                    let mut slot = b.parked.lock().map_err(|_| "Connection slot lock failed")?;
                    if slot.is_none() {
                        *slot = Some(parked);
                        None
                    } else {
                        Some(parked.connection)
                    }
                } else {
                    Some(parked.connection)
                };
                if let Some(connection) = surplus {
                    runtime().block_on(close_quietly(connection));
                }
            }
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
            let reusable = !self.transaction
                && self.epoch_matches().unwrap_or(false)
                && self.created.elapsed() < MAX_LIFETIME;
            if let (true, Some(b)) = (reusable, self.backend.clone())
                && let Some(connection) = self.connection.take()
            {
                b.leases.fetch_sub(1, Ordering::SeqCst);
                // Park it for the worker's next request. The slot is already taken when a
                // second client or the background refresh got there first.
                let surplus = match b.parked.lock() {
                    Ok(mut slot) if slot.is_none() => {
                        *slot = Some(Parked {
                            connection,
                            created: self.created,
                            since: Instant::now(),
                            epoch: self.epoch.clone(),
                        });
                        None
                    }
                    _ => Some(connection),
                };
                if let Some(connection) = surplus {
                    rt.block_on(close_quietly(connection));
                }
            } else {
                self.discard_connection();
            }
            // Replace an ageing connection, or a discarded one, before the next request.
            if let Some(b) = &self.backend
                && (!reusable || self.created.elapsed() >= REFRESH_AGE)
            {
                b.wake.notify_one();
            }
        }
        if let Some(b) = self.backend.take() {
            b.clients.fetch_sub(1, Ordering::SeqCst);
        }
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
    use futures_util::TryStreamExt;
    use sqlx::{Either, Executor, SqlSafeStr, Statement, Type};
    // Compiler emits only validated identifiers/operators and bound values.
    let mut query = sqlx::query(sqlx::AssertSqlSafe(command.sql.as_str()));
    for value in &command.values {
        query = query.bind(value);
    }
    let mut fetched = Vec::new();
    let mut affected = 0;
    {
        let mut results = (&mut *c).fetch_many(query);
        while let Some(item) = results.try_next().await? {
            match item {
                Either::Left(done) => affected += done.rows_affected(),
                Either::Right(row) => fetched.push(row),
            }
        }
    }
    // Decode once the whole result has arrived, so a decoding failure leaves nothing unread.
    let mut rows = Vec::with_capacity(fetched.len());
    for row in fetched {
        rows.push(decode(row).map_err(|e| sqlx::Error::Decode(e.into()))?);
    }
    // Describe after executing, never before. Execution has put the statement in this
    // connection's cache, so SQLx answers from memory. Describing first makes SQLx ask
    // pg_catalog where each result column comes from, about 2 ms per column on DSQL, and
    // the engine already maps columns itself from the relation OID and attribute number.
    let types = vec![<String as Type<sqlx::Postgres>>::type_info(); command.values.len()];
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
    // A statement with a result reports its row count, as it did when fetched separately.
    out.affected = if out.columns.is_empty() {
        affected
    } else {
        rows.len() as u64
    };
    out.rows = rows;
    Ok(out)
}
fn decode(row: PgRow) -> Result<Vec<Option<Vec<u8>>>> {
    let mut out = Vec::with_capacity(row.len());
    for (i, col) in row.columns().iter().enumerate() {
        if row.try_get_raw(i).map_err(db_error)?.is_null() {
            out.push(None);
            continue;
        }
        let ty = col.type_info().name();
        if ty == "BYTEA" {
            out.push(Some(row.try_get::<Vec<u8>, _>(i).map_err(db_error)?));
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
        out.push(Some(s.into_bytes()));
    }
    Ok(out)
}

/// Benchmark/test isolation only. Refuse to reset a backend owned by a live client.
pub fn reset_worker() -> Result<()> {
    let rt = runtime();
    let _scope = rt.enter();
    if let Some(registry) = BACKENDS.get() {
        let mut backends = registry.lock().map_err(|_| "Pool registry poisoned")?;
        if backends
            .values()
            .any(|b| b.clients.load(Ordering::SeqCst) != 0)
        {
            return Err("Close all native clients before resetting the worker".into());
        }
        for (_, backend) in backends.drain() {
            if let Ok(mut task) = backend.refresher.lock()
                && let Some(task) = task.take()
            {
                task.abort();
            }
            let parked = backend.parked.lock().ok().and_then(|mut slot| slot.take());
            if let Some(parked) = parked {
                rt.block_on(close_quietly(parked.connection));
            }
        }
    }
    Ok(())
}

/// `detail` is an index's key-column count followed by all of its attribute numbers.
/// DSQL lists included columns after the keys, and an expression (attribute 0) names no column.
fn key_columns(detail: &str, columns: &[Column]) -> Vec<String> {
    let mut parts = detail.split(' ');
    let count: usize = parts.next().and_then(|n| n.parse().ok()).unwrap_or(0);
    parts
        .take(count)
        .filter_map(|n| {
            let ordinal: i16 = n.parse().ok()?;
            let column = columns.iter().find(|column| column.ordinal == ordinal)?;
            Some(column.name.clone())
        })
        .collect()
}
/// Physical metadata for the tables a statement needs, in two cheap catalog statements.
///
/// The first lists every table of the schema and every valid index: two small scans. The
/// second reads columns by relation, about 2 ms per table on DSQL however it is phrased.
/// A schema of ordinary size is therefore read whole, so a worker asks once per schema
/// generation instead of once per first-touched table; a very large one is read only for
/// the requested tables. Joining these catalogs in one statement costs DSQL far more.
async fn load_tables(
    c: &mut sqlx::PgConnection,
    schema: &str,
    tables: &[String],
) -> std::result::Result<Schema, sqlx::Error> {
    use sqlx::postgres::types::Oid;
    let mut result = Schema::new();
    if tables.is_empty() {
        return Ok(result);
    }
    let rows = sqlx::query(
        "SELECT 'table'::text AS kind,c.oid AS relation,c.relname::text AS name,\
         NULL::text AS detail,false AS is_primary,false AS is_unique \
         FROM pg_catalog.pg_class c \
         WHERE c.relnamespace=(SELECT n.oid FROM pg_catalog.pg_namespace n WHERE n.nspname=$1) \
         AND c.relkind='r' \
         UNION ALL SELECT 'index'::text,i.indrelid,i.indexrelid::text,\
         i.indnkeyatts::text||' '||i.indkey::text,i.indisprimary,i.indisunique \
         FROM pg_catalog.pg_index i WHERE i.indisvalid",
    )
    .bind(schema)
    .fetch_all(&mut *c)
    .await?;
    let mut names: BTreeMap<u32, String> = BTreeMap::new();
    let mut indexes = Vec::new();
    for row in rows {
        let relation = row.try_get::<Oid, _>("relation")?.0;
        if row.try_get::<String, _>("kind")? == "table" {
            names.insert(relation, row.try_get("name")?);
        } else {
            indexes.push((
                relation,
                row.try_get::<String, _>("name")?,
                row.try_get::<String, _>("detail")?,
                row.try_get::<bool, _>("is_primary")?,
                row.try_get::<bool, _>("is_unique")?,
            ));
        }
    }
    // An absent relation is the caller's 42P01: read no columns and publish nothing.
    if tables
        .iter()
        .any(|table| !names.values().any(|name| name == table))
    {
        return Ok(result);
    }
    let whole = names.len() <= WHOLE_SCHEMA_TABLES;
    let wanted: Vec<Oid> = names
        .iter()
        .filter(|(_, name)| whole || tables.contains(name))
        .map(|(oid, _)| Oid(*oid))
        .collect();
    let rows = sqlx::query(
        "SELECT a.attrelid AS relation,a.attname::text AS name,a.attnum::integer AS ordinal,\
         a.atttypid::regtype::text AS ty,(a.attidentity<>'') AS identity,\
         NOT a.attnotnull AS nullable FROM pg_catalog.pg_attribute a \
         WHERE a.attrelid=ANY($1) AND a.attnum>0 AND NOT a.attisdropped",
    )
    .bind(wanted)
    .fetch_all(&mut *c)
    .await?;
    let mut columns: BTreeMap<u32, Vec<Column>> = BTreeMap::new();
    for row in rows {
        columns
            .entry(row.try_get::<Oid, _>("relation")?.0)
            .or_default()
            .push(Column {
                name: row.try_get("name")?,
                ty: row.try_get("ty")?,
                identity: row.try_get("identity")?,
                nullable: row.try_get("nullable")?,
                default: None,
                ordinal: row.try_get::<i32, _>("ordinal")? as i16,
            });
    }
    for (oid, mut columns) in columns {
        let Some(name) = names.get(&oid) else {
            continue;
        };
        columns.sort_by_key(|column| column.ordinal);
        // Keys keep index-identifier order, as the former joined statement returned them.
        let mut keys: BTreeMap<&str, compiler::Index> = BTreeMap::new();
        for (table, index, detail, primary, unique) in &indexes {
            if *table != oid {
                continue;
            }
            let members = key_columns(detail, &columns);
            if !members.is_empty() {
                keys.insert(
                    index.as_str(),
                    compiler::Index {
                        columns: members,
                        primary: *primary,
                        unique: *unique,
                    },
                );
            }
        }
        result.insert(
            name.clone(),
            compiler::Table {
                columns,
                keys: keys.into_values().collect(),
                oid,
            },
        );
    }
    Ok(result)
}

#[cfg(test)]
#[path = "engine_tests.rs"]
mod tests;
