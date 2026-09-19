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
struct Backend {
    pool: PgPool,
    plans: Mutex<LruCache<String, Plan>>,
}
static RUNTIME: OnceLock<Mutex<Option<Arc<Runtime>>>> = OnceLock::new();
static BACKENDS: OnceLock<Mutex<HashMap<Config, Arc<Backend>>>> = OnceLock::new();
// Created on the first operation in an FPM worker, never in the pre-fork master.
fn runtime() -> Arc<Runtime> {
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
fn db_error(e: sqlx::Error) -> String {
    let code = e
        .as_database_error()
        .and_then(|d| d.code())
        .map(|c| c.into_owned())
        .unwrap_or_else(|| "transport".into());
    format!("DSQL operation failed ({code}); not replayed")
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
    let options = DsqlConnectOptionsBuilder::default()
        .pg_connect_options(pg)
        .region(Some(Region::new(config.region.clone())))
        .profile(Some(config.profile.clone()))
        .build()
        .map_err(|_| "Invalid DSQL options")?;
    let schema = config.schema.clone();
    let opts = PgPoolOptions::new()
        .max_connections(1)
        .test_before_acquire(false)
        .min_connections(0)
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
        .map_err(|_| "DSQL connection initialization failed")?;
    let b = Arc::new(Backend {
        pool: p,
        plans: Mutex::new(LruCache::new(NonZeroUsize::new(256).unwrap())),
    });
    all.insert(config.clone(), b.clone());
    Ok((b, false))
}
#[derive(Default, Clone)]
pub struct Output {
    pub rows: Vec<BTreeMap<String, Option<String>>>,
    pub columns: Vec<String>,
    pub affected: u64,
    pub insert_id: String,
    pub stats: BTreeMap<String, f64>,
}
pub struct Client {
    config: Config,
    backend: Option<Arc<Backend>>,
    metadata: Schema,
    transaction: Option<sqlx::pool::PoolConnection<sqlx::Postgres>>,
    closed: bool,
}
impl Client {
    pub fn new(config: Config) -> Result<Self> {
        config.validate()?;
        Ok(Self {
            config,
            backend: None,
            metadata: Schema::new(),
            transaction: None,
            closed: false,
        })
    }
    fn ensure_backend(&mut self) -> Result<bool> {
        if self.backend.is_none() {
            let (b, reused) = backend(&self.config)?;
            self.backend = Some(b);
            Ok(reused)
        } else {
            Ok(true)
        }
    }
    pub fn query(&mut self, sql: &str) -> Result<Output> {
        let rt = runtime();
        let _runtime_scope = rt.enter();
        if self.closed {
            return Err("Native client is closed".into());
        }
        let start = Instant::now();
        let mut out = Output::default();
        let shape = compiler::shape(sql)?;
        let key = format!("{:?}:{:?}", shape.numeric, shape.tokens);
        out.stats
            .insert("tokenize_ms".into(), start.elapsed().as_secs_f64() * 1000.);
        // Transaction commands are a strict lexical subset; no SQL is passed through.
        let command = shape
            .key
            .trim()
            .trim_end_matches(';')
            .trim()
            .to_ascii_uppercase();
        if ["BEGIN", "START TRANSACTION", "COMMIT", "ROLLBACK"].contains(&command.as_str()) {
            let t = Instant::now();
            self.ensure_backend()?;
            match command.as_str() {
                "BEGIN" | "START TRANSACTION" => {
                    if self.transaction.is_some() {
                        return Err("Nested transaction rejected".into());
                    }
                    let mut c = runtime()
                        .block_on(self.backend.as_ref().unwrap().pool.acquire())
                        .map_err(db_error)?;
                    runtime()
                        .block_on(sqlx::query("BEGIN").execute(&mut *c))
                        .map_err(db_error)?;
                    self.transaction = Some(c);
                }
                "COMMIT" | "ROLLBACK" => {
                    let mut c = self.transaction.take().ok_or("No active transaction")?;
                    if let Err(e) = runtime().block_on(
                        sqlx::query(if command == "COMMIT" {
                            "COMMIT"
                        } else {
                            "ROLLBACK"
                        })
                        .execute(&mut *c),
                    ) {
                        c.close_on_drop();
                        return Err(db_error(e));
                    }
                }
                _ => unreachable!(),
            }
            out.stats
                .insert("execute_ms".into(), t.elapsed().as_secs_f64() * 1000.);
            return Ok(out);
        }
        // Parse unsupported statements before opening a connection on a cold client.
        let parsed = if self.backend.is_none() {
            Some(compiler::parse(&shape)?)
        } else {
            None
        };
        let t = Instant::now();
        let reused = self.ensure_backend()?;
        out.stats
            .insert("backend_reused".into(), if reused { 1. } else { 0. });
        out.stats
            .insert("backend_init_ms".into(), t.elapsed().as_secs_f64() * 1000.);
        let b = self.backend.as_ref().unwrap().clone();
        let cached = b
            .plans
            .lock()
            .map_err(|_| "Plan cache poisoned")?
            .get(&key)
            .cloned();
        let plan = if let Some(p) = cached {
            out.stats.insert("plan_hit".into(), 1.);
            p
        } else {
            let t = Instant::now();
            let statement = if let Some(s) = parsed {
                s
            } else {
                compiler::parse(&shape)?
            };
            let tables = compiler::tables(&statement)?;
            for table in &tables {
                if !table.starts_with(&self.config.prefix) {
                    return Err("Table outside configured prototype prefix".into());
                }
            }
            out.stats
                .insert("parse_ms".into(), t.elapsed().as_secs_f64() * 1000.);
            let t = Instant::now();
            for table in tables {
                if !self.metadata.contains_key(&table) {
                    let relation = format!(
                        "{}.{}",
                        compiler::qi(&self.config.schema),
                        compiler::qi(&table)
                    );
                    let q = "SELECT attname,atttypid::regtype::text AS ty,attidentity::text AS identity FROM pg_catalog.pg_attribute WHERE attrelid=to_regclass($1) AND attnum>0 AND NOT attisdropped ORDER BY attnum";
                    let rows = if let Some(c) = self.transaction.as_mut() {
                        runtime().block_on(
                            sqlx::query(q)
                                .bind(&relation)
                                .persistent(true)
                                .fetch_all(&mut **c),
                        )
                    } else {
                        runtime().block_on(
                            sqlx::query(q)
                                .bind(&relation)
                                .persistent(true)
                                .fetch_all(&b.pool),
                        )
                    }
                    .map_err(db_error)?;
                    let cols = rows
                        .into_iter()
                        .map(|r| {
                            Ok(Column {
                                name: r.try_get("attname").map_err(db_error)?,
                                ty: r.try_get("ty").map_err(db_error)?,
                                identity: !r
                                    .try_get::<String, _>("identity")
                                    .map_err(db_error)?
                                    .is_empty(),
                            })
                        })
                        .collect::<Result<Vec<_>>>()?;
                    if cols.is_empty() {
                        return Err("Missing prototype table".into());
                    }
                    self.metadata.insert(table, cols);
                }
            }
            out.stats
                .insert("metadata_ms".into(), t.elapsed().as_secs_f64() * 1000.);
            let t = Instant::now();
            let p = compiler::compile(&statement, &shape, &self.metadata)?;
            out.stats
                .insert("compile_ms".into(), t.elapsed().as_secs_f64() * 1000.);
            out.stats.insert("plan_hit".into(), 0.);
            // Keys and plans contain placeholders only, never query literal values.
            if key.len() + p.sql.len() < 32768 {
                b.plans
                    .lock()
                    .map_err(|_| "Plan cache poisoned")?
                    .put(key, p.clone());
            }
            p
        };
        let t = Instant::now();
        let mut q = sqlx::query(sqlx::AssertSqlSafe(plan.sql.as_str())).persistent(true);
        for bind in &plan.bindings {
            let mut value = shape
                .values
                .get(bind.slot)
                .ok_or("Binding mismatch")?
                .clone();
            if bind.temporal && value.starts_with("0000-00-00") {
                value.replace_range(..10, "0001-01-01");
            }
            q = q.bind(value);
        }
        let read = plan.operation == "SELECT" || plan.identity;
        let rows = if read {
            let rows = if let Some(c) = self.transaction.as_mut() {
                runtime().block_on(q.fetch_all(&mut **c))
            } else {
                runtime().block_on(q.fetch_all(&b.pool))
            }
            .map_err(db_error)?;
            out.affected = rows.len() as u64;
            rows
        } else {
            let r = if let Some(c) = self.transaction.as_mut() {
                runtime().block_on(q.execute(&mut **c))
            } else {
                runtime().block_on(q.execute(&b.pool))
            }
            .map_err(db_error)?;
            out.affected = r.rows_affected();
            vec![]
        };
        out.stats
            .insert("execute_ms".into(), t.elapsed().as_secs_f64() * 1000.);
        let t = Instant::now();
        if let Some(row) = rows.first() {
            out.columns = row.columns().iter().map(|c| c.name().to_string()).collect();
        }
        for row in rows {
            out.rows.push(decode(row)?);
        }
        if plan.identity {
            out.insert_id = out
                .rows
                .first()
                .and_then(|r| r.values().next())
                .and_then(|v| v.clone())
                .unwrap_or_default();
            out.rows.clear();
            out.columns.clear();
        }
        out.stats
            .insert("decode_ms".into(), t.elapsed().as_secs_f64() * 1000.);
        out.stats.insert(
            "native_total_ms".into(),
            start.elapsed().as_secs_f64() * 1000.,
        );
        Ok(out)
    }
    pub fn close(&mut self) {
        if let Some(c) = self.transaction.take() {
            // Never return an open/failed transaction to another request.
            runtime().block_on(c.close()).ok();
        }
        self.backend = None;
        self.metadata.clear();
        self.closed = true;
    }
}
impl Drop for Client {
    fn drop(&mut self) {
        self.close();
    }
}
fn decode(row: PgRow) -> Result<BTreeMap<String, Option<String>>> {
    let mut out = BTreeMap::new();
    for (i, col) in row.columns().iter().enumerate() {
        if row.try_get_raw(i).map_err(db_error)?.is_null() {
            out.insert(col.name().into(), None);
            continue;
        }
        let ty = col.type_info().name();
        let s = match ty {
            "INT8" => row.try_get::<i64, _>(i).map_err(db_error)?.to_string(),
            "INT4" => row.try_get::<i32, _>(i).map_err(db_error)?.to_string(),
            "INT2" => row.try_get::<i16, _>(i).map_err(db_error)?.to_string(),
            "FLOAT4" => row.try_get::<f32, _>(i).map_err(db_error)?.to_string(),
            "FLOAT8" => row.try_get::<f64, _>(i).map_err(db_error)?.to_string(),
            "NUMERIC" => row
                .try_get::<sqlx::types::BigDecimal, _>(i)
                .map_err(db_error)?
                .to_string(),
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
            "DATE" => row
                .try_get::<chrono::NaiveDate, _>(i)
                .map_err(db_error)?
                .to_string(),
            "TEXT" | "VARCHAR" | "BPCHAR" | "NAME" => {
                row.try_get::<String, _>(i).map_err(db_error)?
            }
            _ => return Err("Unsupported result type in native prototype".into()),
        };
        let s = if (ty == "DATE" || ty == "TIMESTAMP") && s.starts_with("0001-01-01") {
            s.replacen("0001-01-01", "0000-00-00", 1)
        } else {
            s
        };
        out.insert(col.name().into(), Some(s));
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
