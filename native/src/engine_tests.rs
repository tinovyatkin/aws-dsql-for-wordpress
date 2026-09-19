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
