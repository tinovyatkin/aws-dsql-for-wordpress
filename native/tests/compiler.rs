use wp_dsql_native::compiler::*;
fn schema() -> Schema {
    [(
        "native_poc_posts".into(),
        vec![
            Column {
                name: "ID".into(),
                ty: "bigint".into(),
                identity: true,
            },
            Column {
                name: "post_title".into(),
                ty: "text".into(),
                identity: false,
            },
            Column {
                name: "post_status".into(),
                ty: "character varying".into(),
                identity: false,
            },
            Column {
                name: "stamp".into(),
                ty: "timestamp without time zone".into(),
                identity: false,
            },
        ],
    )]
    .into()
}
fn plan(sql: &str) -> Result<Plan> {
    let s = shape(sql)?;
    compile(&parse(&s)?, &s, &schema())
}
#[test]
fn complete_select() {
    let p=plan("SELECT p.id, IFNULL(p.post_title,'none') AS title FROM `native_poc_posts` p WHERE p.post_status='publish' AND p.ID IN (1,2) ORDER BY p.ID DESC LIMIT 20,10").unwrap();
    assert!(p.sql.contains("\"p\".\"ID\""));
    assert!(p.sql.contains("COALESCE"));
    assert!(
        p.sql
            .contains("LIMIT CAST($5 AS bigint) OFFSET CAST($6 AS bigint)")
    );
    assert_eq!(
        p.bindings.iter().map(|b| b.slot).collect::<Vec<_>>(),
        vec![0, 1, 2, 3, 5, 4]
    );
}
#[test]
fn value_free_shape() {
    let a = shape("SELECT 'secret one', 123").unwrap();
    let b = shape("SELECT 'secret two', 456").unwrap();
    assert_eq!(a.key, b.key);
    assert!(!a.key.contains("secret"));
    assert_eq!(b.values, vec!["secret two", "456"]);
}
#[test]
fn bound_injection() {
    let s = shape(r#"SELECT 'x\'; DROP TABLE native_poc_posts; --' AS value"#).unwrap();
    assert_eq!(s.values.len(), 1);
    let p = compile(&parse(&s).unwrap(), &s, &Schema::new()).unwrap();
    assert!(!p.sql.contains("DROP"));
}
#[test]
fn mutation_plans() {
    let p=plan("INSERT INTO native_poc_posts (post_title,stamp) VALUES ('hello','0000-00-00 00:00:00'),('other','2026-09-19 12:00:00')").unwrap();
    assert!(p.identity);
    assert!(p.sql.ends_with("RETURNING \"ID\""));
    assert!(p.bindings[1].temporal);
    assert!(plan("UPDATE native_poc_posts SET post_title='changed' WHERE ID=1").is_ok());
    assert!(plan("DELETE FROM native_poc_posts WHERE ID=1").is_ok());
}
#[test]
fn unsupported_rejected() {
    for sql in [
        "SELECT 1; SELECT 2",
        "SELECT * INTO OUTFILE 'x' FROM native_poc_posts",
        "SELECT * FROM public.native_poc_posts",
        "SELECT SLEEP(10)",
        "SELECT /*!50000 1 */ 2",
        "SELECT * FROM native_poc_posts FOR UPDATE",
        "REPLACE INTO native_poc_posts(post_title) VALUES('x')",
        "INSERT IGNORE INTO native_poc_posts(post_title) VALUES('x')",
        "UPDATE native_poc_posts SET ID=1,post_title='x'",
        "DELETE FROM native_poc_posts LIMIT 1",
        "SELECT post_title FROM native_poc_posts ORDER BY 1",
        "SELECT post_title FROM native_poc_posts UNION SELECT post_title FROM native_poc_posts",
    ] {
        assert!(plan(sql).is_err(), "{sql}");
    }
}
#[test]
fn null_and_unicode() {
    let s = shape("SELECT NULL AS n, '🌍' AS s").unwrap();
    let p = compile(&parse(&s).unwrap(), &s, &Schema::new()).unwrap();
    assert!(p.sql.contains("NULL AS \"n\""));
    assert_eq!(s.values, vec!["🌍"]);
}

#[test]
fn comments_and_coercions() {
    let s = shape("SELECT /* private-note */ 'secret' AS value").unwrap();
    assert!(!format!("{:?}", s.tokens).contains("private-note"));
    assert!(plan("SELECT ID FROM native_poc_posts WHERE post_title=123").is_err());
    assert!(plan("SELECT ID FROM native_poc_posts WHERE ID='123tail'").is_err());
    assert!(plan("SELECT post_title FROM native_poc_posts GROUP BY 1").is_err());
    assert!(
        plan("SELECT LENGTH('🌍') AS bytes")
            .unwrap()
            .sql
            .contains("OCTET_LENGTH")
    );
}
