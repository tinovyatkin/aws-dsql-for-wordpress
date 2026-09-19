use sqlparser::ast::Statement;
use wp_dsql_native::{compiler::shape, ddl::create_model};
#[test]
fn native_create_contract() {
    let s=shape("CREATE TABLE wp_items (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,name varchar(80) DEFAULT '',payload longtext,stamp datetime DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY name_key(name),KEY payload_prefix(payload(100))) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci").unwrap();
    let Statement::CreateTable(c) = &s.statement else {
        panic!()
    };
    let m = create_model(c, &s, "wp_", "public", true).unwrap();
    assert_eq!(m["columns"][0]["Extra"], "auto_increment");
    assert_eq!(m["columns"][3]["DefaultExpression"], true);
    assert_eq!(m["indexes"].as_array().unwrap().len(), 3);
}
#[test]
fn unsupported_schema_rejected_before_mutation() {
    for sql in [
        "CREATE TABLE other_t(id int)",
        "CREATE TEMPORARY TABLE wp_t(id int)",
        "CREATE TABLE wp_t(id int,FOREIGN KEY(id) REFERENCES wp_x(id))",
        "CREATE TABLE wp_t(x varchar(30),UNIQUE KEY x(x(10)))",
        "CREATE TABLE wp_t(x text,FULLTEXT KEY x(x))",
        "CREATE TABLE wp_t(x int) ENGINE=MyISAM",
        "CREATE TABLE wp_t(x int) DEFAULT CHARSET=latin1",
        "CREATE TABLE wp_t(x int CHECK(x>0))",
    ] {
        let s = shape(sql).unwrap();
        let Statement::CreateTable(c) = &s.statement else {
            panic!()
        };
        assert!(
            create_model(c, &s, "wp_", "public", false).is_err(),
            "{sql}"
        );
    }
}
