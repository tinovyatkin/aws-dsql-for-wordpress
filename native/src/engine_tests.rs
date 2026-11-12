use super::*;
fn fixture_config() -> (Config, String) {
    assert_eq!(
        std::env::var("WPDSQL_NATIVE_TESTS").as_deref(),
        Ok("synthetic")
    );
    let root = std::path::Path::new(env!("CARGO_MANIFEST_DIR"))
        .parent()
        .unwrap()
        .join(".local");
    let read = |name: &str| -> serde_json::Value {
        serde_json::from_slice(&std::fs::read(root.join(name)).unwrap()).unwrap()
    };
    let settings = read("settings.json");
    let cluster = read("cluster.json");
    let fixture = read("native-fixture.json");
    assert_eq!(
        cluster["tags"]["Purpose"],
        "synthetic-wordpress-compatibility"
    );
    assert_eq!(
        settings["endpoint"].as_str().unwrap(),
        format!(
            "{}.dsql.{}.on.aws",
            cluster["identifier"].as_str().unwrap(),
            settings["region"].as_str().unwrap()
        )
    );
    let prefix = fixture["prefix"].as_str().unwrap().to_string();
    assert!(prefix.starts_with("native_poc_"));
    (
        Config {
            host: settings["endpoint"].as_str().unwrap().into(),
            region: settings["region"].as_str().unwrap().into(),
            profile: settings["profile"].as_str().unwrap().into(),
            user: "admin".into(),
            schema: "public".into(),
            prefix,
            revision: fixture["revision"].as_str().unwrap().into(),
            credentials_file: None,
            value_codec: false,
            sql_mode: String::new(),
            cache_enabled: true,
            schema_signal: None,
        },
        fixture["posts"].as_str().unwrap().into(),
    )
}
fn first(out: Output) -> Vec<u8> {
    out.rows[0][0].clone().unwrap()
}
#[test]
#[ignore = "requires the explicitly selected synthetic AWS fixture"]
fn connection_lifetime_transaction_and_conflict_contracts() {
    let (cfg, table) = fixture_config();
    let mut a = Client::new(cfg.clone()).unwrap();
    let sql = format!("SELECT post_title FROM `{table}` WHERE ID=1");
    let original = first(a.query(&sql).unwrap());
    a.created = Instant::now() - Duration::from_secs(3600);
    assert_eq!(first(a.query(&sql).unwrap()), original);
    assert!(a.created.elapsed() < Duration::from_secs(60));
    a.query("BEGIN").unwrap();
    a.query(&format!(
        "UPDATE `{table}` SET post_title='synthetic-uncommitted' WHERE ID=1"
    ))
    .unwrap();
    a.discard_connection();
    assert!(a.in_transaction());
    assert!(
        a.query(&sql)
            .err()
            .expect("Lost transaction must fail")
            .contains("rollback is required")
    );
    assert!(a.connection.is_none());
    a.query("ROLLBACK").unwrap();
    assert_eq!(first(a.query(&sql).unwrap()), original);
    let mut b = Client::new(cfg).unwrap();
    a.query("BEGIN").unwrap();
    b.query("BEGIN").unwrap();
    a.query(&sql).unwrap();
    b.query(&sql).unwrap();
    a.query(&format!(
        "UPDATE `{table}` SET post_title=post_title WHERE ID=1"
    ))
    .unwrap();
    b.query(&format!(
        "UPDATE `{table}` SET post_title=post_title WHERE ID=1"
    ))
    .unwrap();
    a.query("COMMIT").unwrap();
    let error = b
        .query("COMMIT")
        .err()
        .expect("Concurrent update must conflict");
    assert!(error.contains("40001"), "Unexpected safe error: {error}");
    assert_eq!(b.error.retries, 0);
    assert!(!b.in_transaction());
    assert_eq!(first(b.query(&sql).unwrap()), original);
    // A schema generation change invalidates live clients and pooled prepared statements.
    a.generation("synthetic-next-epoch".into()).unwrap();
    assert!(!b.epoch_matches().unwrap());
    assert_eq!(first(b.query(&sql).unwrap()), original);
    assert!(b.epoch_matches().unwrap());
    a.close();
    b.close();
    reset_worker().unwrap();
}

thread_local! {
    static FAIL_METADATA_AFTER: std::cell::Cell<Option<usize>> = const { std::cell::Cell::new(None) };
}
// Only compiled into Rust tests. Inject a connection reset at a metadata result
// boundary, keeping network-failure controls out of the PHP extension.
pub(super) fn inject_metadata_fault<T>(
    result: std::result::Result<T, sqlx::Error>,
) -> std::result::Result<T, sqlx::Error> {
    let value = result?;
    FAIL_METADATA_AFTER.with(|remaining| match remaining.get() {
        Some(0) => {
            remaining.set(None);
            Err(sqlx::Error::Io(std::io::Error::from(
                std::io::ErrorKind::ConnectionReset,
            )))
        }
        Some(n) => {
            remaining.set(Some(n - 1));
            Ok(value)
        }
        None => Ok(value),
    })
}
#[test]
fn failed_metadata_keeps_transaction_ownership() {
    let cfg = Config {
        host: "test.dsql.eu-central-1.on.aws".into(),
        region: "eu-central-1".into(),
        profile: "default".into(),
        user: "admin".into(),
        schema: "public".into(),
        prefix: "wp_".into(),
        revision: "unit".into(),
        credentials_file: None,
        value_codec: false,
        sql_mode: String::new(),
        cache_enabled: true,
        schema_signal: None,
    };
    let mut client = Client::new(cfg).unwrap();
    client.transaction = true;
    client.catalog = Some(crate::catalog::Catalog::default());
    let error = client
        .metadata_result::<()>(Err(sqlx::Error::Io(std::io::Error::from(
            std::io::ErrorKind::ConnectionReset,
        ))))
        .unwrap_err();
    assert!(error.contains("transport"));
    assert!(client.catalog.is_none());
    assert!(client.in_transaction());
    assert!(
        client
            .check_connection()
            .unwrap_err()
            .contains("rollback is required")
    );
    assert_eq!(client.error.retries, 0);
    client.transaction = false;
}
#[test]
#[ignore = "requires the explicitly selected synthetic AWS fixture"]
fn metadata_transport_failure_recovers_without_replaying_a_transaction() {
    let (cfg, table) = fixture_config();
    // Cold catalog lookup, then the separate column/index loader.
    for fail_after in [0, 2] {
        let mut client = Client::new(cfg.clone()).unwrap();
        FAIL_METADATA_AFTER.with(|remaining| remaining.set(Some(fail_after)));
        let query = format!("SELECT post_title FROM `{table}` WHERE ID=1");
        assert!(client.query(&query).err().unwrap().contains("transport"));
        assert!(client.connection.is_none());
        assert_eq!(client.error.retries, 0);
        assert_eq!(first(client.query(&query).unwrap()), b"Synthetic story 1");
        client.close();
        reset_worker().unwrap();
    }
    let mut client = Client::new(cfg).unwrap();
    client.query("BEGIN").unwrap();
    FAIL_METADATA_AFTER.with(|remaining| remaining.set(Some(0)));
    let query = format!("SELECT post_title FROM `{table}` WHERE ID=1");
    assert!(client.query(&query).err().unwrap().contains("transport"));
    assert!(client.connection.is_none());
    assert!(client.in_transaction());
    assert!(
        client
            .query(&query)
            .err()
            .unwrap()
            .contains("rollback is required")
    );
    client.query("ROLLBACK").unwrap();
    assert_eq!(first(client.query(&query).unwrap()), b"Synthetic story 1");
    client.close();
    reset_worker().unwrap();
}
#[test]
fn json_and_binary_bindings_preserve_value_bytes() {
    use crate::compiler::*;
    for (ty, literal, expected) in [
        ("bytea", r"'a\0b\\x41'", "6100625c783431"),
        (
            "json",
            r#"'{"n":123456789012345678901234567890,"x":"~dsqlb64:v1:literal"}'"#,
            r#"{"n":123456789012345678901234567890,"x":"~dsqlb64:v1:literal"}"#,
        ),
    ] {
        let schema: Schema = [(
            "wp_probe".into(),
            Table::from(vec![Column {
                name: "payload".into(),
                ty: ty.into(),
                identity: false,
                nullable: true,
                default: None,
                ordinal: 1,
            }]),
        )]
        .into();
        let shape = shape_mode(
            &format!("INSERT INTO wp_probe(payload) VALUES ({literal})"),
            "",
            true,
        )
        .unwrap();
        let plan = compile(&shape.statement, &shape, &schema).unwrap();
        let bound = bind_command(
            &Command {
                sql: plan.sql,
                bindings: plan.bindings,
            },
            &shape.values,
        )
        .unwrap();
        assert_eq!(bound.values, [expected]);
    }
}
#[test]
fn index_keys_exclude_included_columns_and_expressions() {
    let columns: Vec<Column> = [(1, "ID"), (2, "post_name"), (3, "post_date")]
        .into_iter()
        .map(|(ordinal, name)| Column {
            name: name.into(),
            ty: "text".into(),
            identity: false,
            nullable: false,
            default: None,
            ordinal,
        })
        .collect();
    // DSQL stores every column in a primary-key index; only the first is a key.
    assert_eq!(key_columns("1 1 2 3", &columns), ["ID"]);
    assert_eq!(key_columns("2 3 2 1", &columns), ["post_date", "post_name"]);
    // An expression key has no attribute number, and a dropped column has no name.
    assert_eq!(key_columns("2 0 2", &columns), ["post_name"]);
    assert_eq!(key_columns("1 9", &columns), Vec::<String>::new());
    assert_eq!(key_columns("", &columns), Vec::<String>::new());
}
#[test]
fn worker_catalog_is_trusted_only_while_its_signal_is_unchanged() {
    use crate::catalog::schema_signal;
    let directory = std::env::temp_dir().join(format!("wp-dsql-signal-{}", std::process::id()));
    std::fs::create_dir_all(&directory).unwrap();
    let path = directory.to_str().unwrap();
    assert_eq!(schema_signal(None), None);
    assert_eq!(schema_signal(Some("/nonexistent/wp-dsql-signal")), None);
    let before = schema_signal(Some(path)).expect("directory has a modification time");
    assert_eq!(schema_signal(Some(path)), Some(before));
    // The schema coordinator publishes each journal by creating and renaming a file.
    std::thread::sleep(Duration::from_millis(30));
    std::fs::write(directory.join("pending.json.tmp"), b"{}").unwrap();
    std::fs::rename(
        directory.join("pending.json.tmp"),
        directory.join("pending.json"),
    )
    .unwrap();
    let after = schema_signal(Some(path)).unwrap();
    assert_ne!(before, after);
    let shared = crate::catalog::SharedCatalog::validated("generation".into(), before);
    assert!(shared.current(Some(before)));
    assert!(!shared.current(Some(after)));
    assert!(!shared.current(None));
    std::fs::remove_dir_all(&directory).unwrap();
}
/// A stand-in for the schema coordinator's state directory, last touched a while ago.
fn signal_directory(name: &str) -> std::path::PathBuf {
    let directory = std::env::temp_dir().join(format!("wp-dsql-{name}-{}", std::process::id()));
    std::fs::create_dir_all(&directory).unwrap();
    settle_signal(&directory);
    directory
}
fn settle_signal(directory: &std::path::Path) {
    std::fs::File::open(directory)
        .unwrap()
        .set_modified(std::time::SystemTime::now() - Duration::from_secs(10))
        .unwrap();
}
fn parked_times(b: &Backend) -> Option<(Instant, Instant)> {
    let slot = b.parked.lock().unwrap();
    slot.as_ref().map(|parked| (parked.created, parked.since))
}
fn wait_for(mut done: impl FnMut() -> bool) -> bool {
    for _ in 0..100 {
        if done() {
            return true;
        }
        std::thread::sleep(Duration::from_millis(100));
    }
    false
}
#[test]
#[ignore = "requires the explicitly selected synthetic AWS fixture"]
fn worker_connection_is_refreshed_verified_and_probed_between_requests() {
    // With a schema signal a request's first statement is its own, not a catalog read.
    let (mut cfg, table) = fixture_config();
    let directory = signal_directory("probe");
    cfg.schema_signal = Some(directory.to_str().unwrap().into());
    let read = format!("SELECT post_title FROM `{table}` WHERE ID=1");
    let write = format!("UPDATE `{table}` SET post_title=post_title WHERE ID=1");
    let mut a = Client::new(cfg.clone()).unwrap();
    let original = first(a.query(&read).unwrap());
    assert_eq!(a.connections_opened, 1);
    a.close();
    let (b, _) = backend(&cfg).unwrap();
    let (opened, _) = parked_times(&b).expect("a finished request parks its connection");

    // An ageing connection is replaced without any request asking for it.
    b.parked.lock().unwrap().as_mut().unwrap().created = Instant::now() - REFRESH_AGE;
    b.wake.notify_one();
    assert!(wait_for(
        || parked_times(&b).is_some_and(|(created, _)| created > opened)
    ));
    let mut c = Client::new(cfg.clone()).unwrap();
    assert_eq!(first(c.query(&read).unwrap()), original);
    assert_eq!(c.connections_opened, 0, "the request found a connection");
    c.close();

    // A long-idle connection is verified in the background and keeps its identity.
    let (replaced, _) = parked_times(&b).unwrap();
    let stale = Instant::now() - KEEPALIVE_IDLE;
    b.parked.lock().unwrap().as_mut().unwrap().since = stale;
    b.wake.notify_one();
    assert!(wait_for(
        || parked_times(&b).is_some_and(|(_, since)| since > stale)
    ));
    assert_eq!(parked_times(&b).unwrap().0, replaced);

    // After a short idle a read goes straight out; only a write is probed first.
    let idle = Instant::now() - PROBE_AFTER_IDLE - Duration::from_secs(1);
    b.parked.lock().unwrap().as_mut().unwrap().since = idle;
    let mut d = Client::new(cfg.clone()).unwrap();
    assert_eq!(first(d.query(&read).unwrap()), original);
    assert_eq!((d.connection_health_checks, d.connections_opened), (0, 0));
    d.close();
    b.parked.lock().unwrap().as_mut().unwrap().since = idle;
    let mut e = Client::new(cfg.clone()).unwrap();
    e.query(&write).unwrap();
    assert_eq!((e.connection_health_checks, e.connections_opened), (1, 0));

    // A connection discarded with an open transaction is replaced before the next request.
    e.query("BEGIN").unwrap();
    e.query(&write).unwrap();
    e.close();
    assert!(wait_for(|| parked_times(&b).is_some()));
    let mut f = Client::new(cfg).unwrap();
    assert_eq!(first(f.query(&read).unwrap()), original);
    assert_eq!(f.connections_opened, 0);
    f.close();
    drop(b);
    reset_worker().unwrap();
    std::fs::remove_dir_all(&directory).unwrap();
}
#[test]
#[ignore = "requires the explicitly selected synthetic AWS fixture"]
fn signalled_worker_reuses_its_catalog_until_the_signal_moves() {
    let (mut cfg, table) = fixture_config();
    let directory = signal_directory("live");
    cfg.schema_signal = Some(directory.to_str().unwrap().into());
    let read = format!("SELECT post_title FROM `{table}` WHERE ID=1");
    let mut a = Client::new(cfg.clone()).unwrap();
    let original = first(a.query(&read).unwrap());
    a.close();

    // A later request reads no catalog at all: an armed metadata fault is never reached.
    FAIL_METADATA_AFTER.with(|remaining| remaining.set(Some(0)));
    let mut b = Client::new(cfg.clone()).unwrap();
    assert_eq!(first(b.query(&read).unwrap()), original);
    b.close();
    assert_eq!(
        FAIL_METADATA_AFTER.with(|remaining| remaining.get()),
        Some(0)
    );

    // Any journal written by a schema coordinator moves the signal; the catalog is read again.
    std::thread::sleep(Duration::from_millis(30));
    std::fs::write(directory.join("last-operation.json"), b"{}").unwrap();
    let mut c = Client::new(cfg.clone()).unwrap();
    assert!(c.query(&read).err().unwrap().contains("transport"));
    assert_eq!(FAIL_METADATA_AFTER.with(|remaining| remaining.get()), None);
    assert_eq!(first(c.query(&read).unwrap()), original);

    // A signal that moved moments ago is not relied on; once settled it is again.
    assert!(c.backend_catalog().unwrap().is_none());
    c.close();
    settle_signal(&directory);

    // An error that looks like schema drift also ends the trust in the retained copy.
    let mut d = Client::new(cfg.clone()).unwrap();
    assert!(d.backend_catalog().unwrap().is_none());
    assert_eq!(first(d.query(&read).unwrap()), original);
    assert!(d.backend_catalog().unwrap().is_some());
    let missing = format!("SELECT 1 FROM `{}absent`", cfg.prefix);
    assert!(d.query(&missing).err().unwrap().contains("42P01"));
    assert!(d.backend_catalog().unwrap().is_none());
    d.close();
    reset_worker().unwrap();
    std::fs::remove_dir_all(&directory).unwrap();
}
#[test]
#[ignore = "requires the explicitly selected synthetic AWS fixture"]
fn parked_connection_from_an_older_generation_is_cleared_before_reuse() {
    let (cfg, table) = fixture_config();
    let read = format!("SELECT post_title FROM `{table}` WHERE ID=1");
    let mut a = Client::new(cfg.clone()).unwrap();
    let original = first(a.query(&read).unwrap());
    a.close();
    let (b, _) = backend(&cfg).unwrap();
    // As if the keep-alive held the connection while a request moved the generation.
    {
        let mut slot = b.parked.lock().unwrap();
        let parked = slot.as_mut().unwrap();
        assert!(parked.connection.cached_statements_size() > 0);
        assert_eq!(&parked.epoch, &*b.generation.lock().unwrap());
        parked.epoch = Some("an-older-generation".into());
    }
    let mut c = Client::new(cfg.clone()).unwrap();
    c.check_connection().unwrap();
    assert_eq!(c.connection.as_ref().unwrap().cached_statements_size(), 0);
    assert_eq!(c.connections_opened, 0, "cleared, not replaced");
    assert_eq!(first(c.query(&read).unwrap()), original);
    c.close();
    // A connection from the current generation keeps its statements.
    let mut d = Client::new(cfg).unwrap();
    d.check_connection().unwrap();
    assert!(d.connection.as_ref().unwrap().cached_statements_size() > 0);
    d.close();
    drop(b);
    reset_worker().unwrap();
}
#[test]
#[ignore = "requires the explicitly selected synthetic AWS fixture"]
fn metadata_entry_points_work_on_an_idle_parked_connection() {
    let (cfg, table) = fixture_config();
    let mut a = Client::new(cfg.clone()).unwrap();
    a.query(&format!("SELECT post_title FROM `{table}` WHERE ID=1"))
        .unwrap();
    a.close();
    let (b, _) = backend(&cfg).unwrap();
    let idle = Instant::now() - PROBE_AFTER_IDLE - Duration::from_secs(1);
    // PHP calls these without entering the runtime first; the probe's timer must still work.
    b.parked.lock().unwrap().as_mut().unwrap().since = idle;
    // A catalog read is replayable: bounded by a timer instead of a liveness check.
    let mut c = Client::new(cfg.clone()).unwrap();
    let model = c.logical_table(&table).unwrap().expect("fixture table");
    assert!(model["columns"].as_array().is_some_and(|c| !c.is_empty()));
    assert_eq!(c.connection_health_checks, 0);
    c.close();
    // Other direct statements (identity reseeding uses this path) are probed first.
    b.parked.lock().unwrap().as_mut().unwrap().since = idle;
    let mut d = Client::new(cfg).unwrap();
    let one = d.raw("SELECT 1 AS one".into(), vec![]).unwrap();
    assert_eq!(one.rows[0][0].as_deref(), Some(&b"1"[..]));
    assert_eq!(d.connection_health_checks, 1);
    d.close();
    drop(b);
    reset_worker().unwrap();
}
