use wp_dsql_native::compiler::shape;
#[test]
fn native_metadata_grammar_consumes_complete_statements() {
    for sql in [
        "SHOW FULL COLUMNS FROM wp_posts LIKE 'post_%'",
        "SHOW KEYS FROM wp_posts WHERE Key_name='PRIMARY'",
        "SHOW FULL TABLES FROM postgres",
        "SHOW CREATE TABLE wp_posts",
        "DESCRIBE wp_posts",
        "SHOW TABLE STATUS LIKE 'wp_posts'",
        "SHOW VARIABLES LIKE 'max_connections'",
        "CHECK TABLE wp_posts",
        "REPAIR TABLE wp_posts",
        "ANALYZE TABLE wp_posts",
        "OPTIMIZE TABLE wp_posts",
    ] {
        assert!(shape(sql).unwrap().metadata.is_some(), "{sql}");
    }
    for sql in [
        "SHOW TABLE STATUS LIKE 'x' garbage",
        "SHOW INDEX FROM wp_posts; DROP TABLE wp_posts",
        "CHECK TABLE wp_posts QUICK",
        "SHOW FANCY TABLES",
        "SHOW FULL VARIABLES",
        "SHOW TABLE STATUS LIKE 'x' 'trailing'",
    ] {
        assert!(shape(sql).is_err(), "{sql}");
    }
}
