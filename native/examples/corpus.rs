//! Syntax-only coverage probe; never opens a database or prints SQL values.
use sqlparser::{dialect::MySqlDialect, parser::Parser};
fn main() {
    let input =
        std::fs::read_to_string(std::env::args().nth(1).expect("JSON array of SQL strings"))
            .unwrap();
    let queries: Vec<String> = serde_json::from_str(&input).unwrap();
    let mut accepted = 0;
    let mut rejected = vec![];
    for (i, q) in queries.iter().enumerate() {
        match Parser::parse_sql(&MySqlDialect {}, q) {
            Ok(s) if s.len() == 1 => accepted += 1,
            _ => rejected.push(i),
        }
    }
    println!(
        "{}",
        serde_json::json!({"total":queries.len(),"parsed":accepted,"rejected_indices":rejected})
    );
}
