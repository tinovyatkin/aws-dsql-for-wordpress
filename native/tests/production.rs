use wp_dsql_native::compiler::*;
fn schema() -> Schema {
    let columns = [
        ("ID", "bigint"),
        ("post_title", "text"),
        ("post_date", "timestamp without time zone"),
        ("payload", "text"),
        ("score", "integer"),
        ("name", "character varying"),
        ("option_id", "bigint"),
        ("option_name", "text"),
        ("option_value", "text"),
        ("autoload", "text"),
    ]
    .into_iter()
    .map(|(n, t)| Column {
        name: n.into(),
        ty: t.into(),
        identity: n == "ID" || n == "option_id",
        nullable: n == "payload",
        default: None,
        ordinal: 0,
    })
    .collect::<Vec<_>>();
    ["wp_posts", "wp_options"]
        .into_iter()
        .map(|name| {
            let id = if name == "wp_options" {
                "option_id"
            } else {
                "ID"
            };
            let unique = if name == "wp_options" {
                "option_name"
            } else {
                "name"
            };
            (
                name.into(),
                Table {
                    oid: 0,
                    columns: columns.clone(),
                    keys: vec![
                        Index {
                            columns: vec![id.into()],
                            primary: true,
                            unique: true,
                        },
                        Index {
                            columns: vec![unique.into()],
                            primary: false,
                            unique: true,
                        },
                    ],
                },
            )
        })
        .collect()
}
fn plan(sql: &str) -> Plan {
    let s = shape(sql).unwrap();
    let mut p = compile(&s.statement, &s, &schema()).unwrap();
    p.sql = wp_dsql_native::expression::resolve_numbers(&p.sql, &s.values).unwrap();
    p
}
#[test]
fn query_shapes() {
    for q in [
        "SELECT p.* FROM wp_posts p LEFT JOIN wp_posts q ON p.ID=q.ID WHERE p.ID IN (SELECT ID FROM wp_posts WHERE score>1)",
        "SELECT (SELECT 10 LIMIT 1),20 ORDER BY 2 LIMIT 3,4",
        "SELECT 1 AS value UNION SELECT post_title FROM wp_posts",
        "SELECT CASE WHEN score>1 THEN 'high' ELSE 'low' END AS band FROM wp_posts",
        "SELECT CONCAT('x',LOWER(IFNULL(post_title,'empty'))) FROM wp_posts",
        "SELECT GROUP_CONCAT(post_title ORDER BY ID SEPARATOR '|') FROM wp_posts",
    ] {
        let _ = plan(q);
    }
}
#[test]
fn truth_and_numeric() {
    let p = plan("SELECT ID FROM wp_posts WHERE post_title=123 OR score");
    assert!(p.sql.contains("substring"));
    assert!(p.sql.contains("<> 0"));
    let p = plan("SELECT payload+0 FROM wp_posts");
    assert!(p.sql.contains("AS numeric"));
    let p = plan("SELECT ID FROM wp_posts WHERE post_title LIKE '%hotel%'");
    assert!(p.sql.contains("ILIKE"));
}
#[test]
fn binary_and_casts() {
    for q in [
        "SELECT CAST(payload AS SIGNED) FROM wp_posts",
        "SELECT CAST(payload AS UNSIGNED) FROM wp_posts",
        "SELECT CAST(payload AS DECIMAL(10,2)) FROM wp_posts",
        "SELECT ID FROM wp_posts WHERE CAST(post_title AS BINARY)='Hotel'",
        "SELECT ID FROM wp_posts WHERE post_title REGEXP BINARY '^Hotel$'",
    ] {
        let _ = plan(q);
    }
}
#[test]
fn calendar_functions() {
    for function in [
        "YEAR",
        "MONTH",
        "DAY",
        "HOUR",
        "MINUTE",
        "SECOND",
        "DAYOFYEAR",
        "DAYOFWEEK",
        "WEEKDAY",
    ] {
        let _ = plan(&format!("SELECT {function}(post_date) FROM wp_posts"));
    }
    for mode in 0..8 {
        assert!(!plan(&format!("SELECT WEEK(post_date,{mode}) FROM wp_posts")).cacheable);
    }
    let p = plan("SELECT DATE_FORMAT(post_date,'%H.%i')=10.30 FROM wp_posts");
    assert!(p.bindings.iter().any(|b| b.format));
    assert!(!p.sql.contains("%H"));
    let p = plan(
        "SELECT DISTINCT YEAR(post_date) AS year,MONTH(post_date) AS month FROM wp_posts ORDER BY post_date DESC",
    );
    assert!(p.sql.contains("ORDER BY 1 DESC, 2 DESC"));
}
#[test]
fn aggregates_and_found_rows() {
    assert!(
        !plan("SELECT COUNT(*) FROM wp_posts ORDER BY post_date")
            .sql
            .contains("ORDER BY")
    );
    assert!(
        plan("SELECT SQL_CALC_FOUND_ROWS ID FROM wp_posts LIMIT 0,10")
            .count
            .is_some()
    );
    assert_eq!(plan("SELECT FOUND_ROWS()").operation, "FOUND_ROWS");
}
#[test]
fn writes_and_conflicts() {
    let p = plan(
        "INSERT INTO wp_options(option_name,option_value,autoload) VALUES ('probe','value','off') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value),autoload=VALUES(autoload)",
    );
    assert!(p.sql.contains("ON CONFLICT (\"option_name\")"));
    assert!(p.sql.contains("EXCLUDED.\"option_value\""));
    let p = plan("REPLACE INTO wp_posts(ID,name,score) VALUES (1,'first',5)");
    assert_eq!(p.leading.len(), 1);
    assert_eq!(p.operation, "REPLACE");
    assert!(
        plan("INSERT IGNORE INTO wp_options(option_name,option_value) VALUES ('a','b')")
            .sql
            .contains("DO NOTHING")
    );
    assert!(
        plan("UPDATE wp_posts SET score=score+1 ORDER BY ID DESC LIMIT 1")
            .sql
            .contains(" IN (SELECT")
    );
    assert!(
        plan("DELETE FROM wp_posts ORDER BY ID DESC LIMIT 1")
            .sql
            .contains(" IN (SELECT")
    );
}
#[test]
fn core_self_join_deletes() {
    let p = plan(
        "DELETE o1 FROM wp_options o1 JOIN wp_options o2 USING (option_name) WHERE o2.option_id>o1.option_id",
    );
    assert!(p.sql.contains("USING (\"option_name\")"));
    let p = plan(
        "DELETE a,b FROM wp_options a,wp_options b WHERE a.option_name LIKE '_transient_%' AND b.option_name=CONCAT('_transient_timeout_',SUBSTRING(a.option_name,12)) AND b.option_value<123",
    );
    assert!(p.sql.contains(" UNION "));
}
#[test]
fn sql_modes() {
    for (mode, sql, contains) in [
        ("PIPES_AS_CONCAT", "SELECT 'a'||'b'", " || "),
        ("", "SELECT 'a'||'b'", " OR "),
        (
            "ANSI_QUOTES",
            "SELECT \"post_title\" FROM wp_posts",
            "\"post_title\"",
        ),
        ("HIGH_NOT_PRECEDENCE", "SELECT NOT 1=2", "NOT"),
    ] {
        let s = shape_mode(sql, mode, false).unwrap();
        assert!(
            compile(&s.statement, &s, &schema())
                .unwrap()
                .sql
                .contains(contains)
        );
    }
    let s = shape_mode(r"SELECT 'a\b'", "NO_BACKSLASH_ESCAPES", false).unwrap();
    assert_eq!(s.values, vec![r"a\b"]);
}
