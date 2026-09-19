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
        },
        fixture["posts"].as_str().unwrap().into(),
    )
}
fn first(out: Output) -> Vec<u8> {
    out.rows[0].values().next().unwrap().clone().unwrap()
}
#[test]
#[ignore = "requires the explicitly selected synthetic AWS fixture"]
fn connection_lifetime_transaction_and_conflict_contracts() {
    let (cfg, table) = fixture_config();
    let mut a = Client::new(cfg.clone()).unwrap();
    let sql = format!("SELECT post_title FROM `{table}` WHERE ID=1");
    let original = first(a.query(&sql).unwrap());
    a.acquired = Instant::now() - Duration::from_secs(3600);
    assert_eq!(first(a.query(&sql).unwrap()), original);
    assert!(a.acquired.elapsed() < Duration::from_secs(60));
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
