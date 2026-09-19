//! Version-2 logical MySQL schema presentation shared by native metadata operations.
use crate::{
    catalog_expr::{Row, string},
    compiler::{Result, check},
};
use serde_json::{Value, json};
pub fn identifier(s: &str) -> String {
    format!("`{}`", s.replace('`', "``"))
}
pub fn literal(s: &str) -> String {
    let mut out = String::from("'");
    for c in s.chars() {
        match c {
            '\\' => out.push_str("\\\\"),
            '\'' => out.push_str("\\'"),
            '\0' => out.push_str("\\0"),
            '\n' => out.push_str("\\n"),
            '\r' => out.push_str("\\r"),
            _ => out.push(c),
        }
    }
    out.push('\'');
    out
}
pub fn text_type(s: &str) -> bool {
    let s = s.to_ascii_lowercase();
    [
        "char",
        "varchar",
        "tinytext",
        "text",
        "mediumtext",
        "longtext",
        "enum(",
        "set(",
    ]
    .iter()
    .any(|t| s.starts_with(t))
}
pub fn normalized(mut table: Value) -> Result<Value> {
    let version = table
        .get("schema_version")
        .and_then(Value::as_u64)
        .unwrap_or(2);
    check(version == 2)?;
    if table.get("table_options").is_none() {
        table["table_options"] =
            json!({"engine":"InnoDB","charset":"utf8mb4","collation":null,"comment":""});
    }
    table["schema_version"] = json!(2);
    let indexes = table
        .get("indexes")
        .and_then(Value::as_array)
        .cloned()
        .unwrap_or_default();
    table["indexes"] = json!(indexes);
    let options = table["table_options"].clone();
    let columns = table
        .get_mut("columns")
        .and_then(Value::as_array_mut)
        .ok_or("Invalid logical columns")?;
    for column in columns {
        let c = column.as_object_mut().ok_or("Invalid logical column")?;
        for (k, v) in [
            ("Comment", json!("")),
            ("Privileges", json!("select,insert,update,references")),
            ("Extra", json!("")),
            ("Key", json!("")),
            ("HasDefault", json!(false)),
            ("DefaultExpression", json!(false)),
        ] {
            c.entry(k).or_insert(v);
        }
        let ty = c
            .get("Type")
            .and_then(Value::as_str)
            .ok_or("Missing logical type")?;
        let collation = if text_type(ty) {
            c.get("Collation")
                .filter(|v| !v.is_null())
                .cloned()
                .or_else(|| options.get("collation").filter(|v| !v.is_null()).cloned())
                .unwrap_or(json!("utf8mb4_unicode_ci"))
        } else {
            Value::Null
        };
        c.insert("Collation".into(), collation);
        let field = c
            .get("Field")
            .and_then(Value::as_str)
            .ok_or("Missing logical field")?;
        let mut rank = 0;
        for i in &indexes {
            if i["Column_name"].as_str() != Some(field) {
                continue;
            }
            let key = i["Key_name"].as_str().unwrap_or("");
            let first = string(&i["Seq_in_index"]).as_deref() == Some("1");
            let unique = string(&i["Non_unique"]).as_deref() == Some("0");
            rank = rank.max(if key == "PRIMARY" {
                3
            } else if first {
                if unique { 2 } else { 1 }
            } else {
                0
            });
        }
        c.insert("Key".into(), json!(["", "MUL", "UNI", "PRI"][rank]));
        if rank == 3 {
            c.insert("Null".into(), json!("NO"));
        }
    }
    Ok(table)
}
pub fn columns(table: &Value, full: bool) -> Result<Vec<Row>> {
    let keys = if full {
        vec![
            "Field",
            "Type",
            "Collation",
            "Null",
            "Key",
            "Default",
            "Extra",
            "Privileges",
            "Comment",
        ]
    } else {
        vec!["Field", "Type", "Null", "Key", "Default", "Extra"]
    };
    table["columns"]
        .as_array()
        .ok_or("Invalid logical table")?
        .iter()
        .map(|c| {
            Ok(c.as_object()
                .ok_or("Invalid logical column")?
                .iter()
                .filter(|(k, _)| keys.contains(&k.as_str()))
                .map(|(k, v)| (k.clone(), v.clone()))
                .collect())
        })
        .collect()
}
pub fn mysql(table: &Value) -> Result<String> {
    let mut parts = vec![];
    for c in table["columns"].as_array().ok_or("Invalid logical table")? {
        let mut s = format!(
            "{} {}",
            identifier(c["Field"].as_str().ok_or("Invalid field")?),
            c["Type"].as_str().ok_or("Invalid type")?
        );
        if let Some(collation) = c["Collation"].as_str() {
            s.push_str(&format!(" COLLATE {}", identifier(collation)));
        }
        s.push_str(if c["Null"] == "NO" {
            " NOT NULL"
        } else {
            " NULL"
        });
        if c["HasDefault"].as_bool() == Some(true) {
            let value = &c["Default"];
            let value = if value.is_null() {
                "NULL".into()
            } else if c["DefaultExpression"].as_bool() == Some(true) {
                let text = string(value).unwrap();
                if text.to_ascii_uppercase().starts_with("CURRENT_TIMESTAMP") {
                    text
                } else {
                    format!("({text})")
                }
            } else {
                literal(&string(value).unwrap())
            };
            s.push_str(&format!(" DEFAULT {value}"));
        }
        if c["Extra"].as_str().unwrap_or("").contains("auto_increment") {
            s.push_str(" AUTO_INCREMENT");
        }
        if let Some(comment) = c["Comment"].as_str().filter(|s| !s.is_empty()) {
            s.push_str(&format!(" COMMENT {}", literal(comment)));
        }
        parts.push(s);
    }
    let mut groups: std::collections::BTreeMap<String, Vec<&Value>> =
        std::collections::BTreeMap::new();
    for index in table["indexes"]
        .as_array()
        .ok_or("Invalid logical indexes")?
    {
        groups
            .entry(
                index["Key_name"]
                    .as_str()
                    .ok_or("Invalid index name")?
                    .into(),
            )
            .or_default()
            .push(index);
    }
    for (name, mut group) in groups {
        group.sort_by_key(|i| {
            string(&i["Seq_in_index"])
                .and_then(|s| s.parse::<u64>().ok())
                .unwrap_or(0)
        });
        let kind = if name == "PRIMARY" {
            "PRIMARY KEY".into()
        } else {
            format!(
                "{} {}",
                if group[0]["Index_type"] == "FULLTEXT" {
                    "FULLTEXT KEY"
                } else if string(&group[0]["Non_unique"]).as_deref() == Some("0") {
                    "UNIQUE KEY"
                } else {
                    "KEY"
                },
                identifier(&name)
            )
        };
        let cols = group
            .iter()
            .map(|i| {
                let name = identifier(i["Column_name"].as_str().unwrap_or(""));
                if let Some(size) = string(&i["Sub_part"]) {
                    format!("{name}({size})")
                } else {
                    name
                }
            })
            .collect::<Vec<_>>()
            .join(",");
        let mut s = format!("{kind} ({cols})");
        if let Some(comment) = group[0]["Index_comment"].as_str().filter(|s| !s.is_empty()) {
            s.push_str(&format!(" COMMENT {}", literal(comment)));
        }
        parts.push(s);
    }
    let o = &table["table_options"];
    let mut sql = format!(
        "CREATE TABLE {} (\n{}\n) ENGINE={} DEFAULT CHARSET={}",
        identifier(table["name"].as_str().ok_or("Invalid table name")?),
        parts.join(",\n"),
        o["engine"].as_str().unwrap_or("InnoDB"),
        o["charset"].as_str().unwrap_or("utf8mb4")
    );
    if let Some(collation) = o["collation"].as_str() {
        sql.push_str(&format!(" COLLATE={}", identifier(collation)));
    }
    if let Some(comment) = o["comment"].as_str().filter(|s| !s.is_empty()) {
        sql.push_str(&format!(" COMMENT={}", literal(comment)));
    }
    Ok(sql)
}
pub fn information_columns(table: &Value, database: &str) -> Result<Vec<Row>> {
    let mut rows = vec![];
    let re = regex::Regex::new(r"(?i)^([a-z]+)(?:\((\d+)(?:,(\d+))?\))?").unwrap();
    for (i, c) in table["columns"]
        .as_array()
        .ok_or("Invalid logical columns")?
        .iter()
        .enumerate()
    {
        let ty = c["Type"].as_str().ok_or("Invalid logical type")?;
        let captures = re.captures(ty).ok_or("Invalid logical type")?;
        let base = captures[1].to_ascii_lowercase();
        let charset = c["Collation"]
            .as_str()
            .map(|s| s.split('_').next().unwrap());
        let width = match charset {
            Some("utf8mb4") => 4,
            Some("utf8" | "utf8mb3") => 3,
            Some("ucs2" | "big5" | "gbk" | "sjis") => 2,
            _ => 1,
        };
        let mut length = None;
        let mut bytes = None;
        if ["varchar", "char", "varbinary"].contains(&base.as_str()) {
            let n = captures
                .get(2)
                .and_then(|n| n.as_str().parse::<u64>().ok())
                .ok_or("Invalid character length")?;
            length = Some(n.to_string());
            bytes = Some((n * if base == "varbinary" { 1 } else { width }).to_string());
        }
        if base.ends_with("text") || base.ends_with("blob") {
            let n = if base.starts_with("tiny") {
                255
            } else if base.starts_with("medium") {
                16777215
            } else if base.starts_with("long") {
                4294967295u64
            } else {
                65535
            };
            length = Some(n.to_string());
            bytes = length.clone();
        }
        let numeric = ["decimal", "numeric"].contains(&base.as_str());
        let temporal = ["datetime", "timestamp"].contains(&base.as_str());
        let row = json!({"TABLE_CATALOG":"def","TABLE_SCHEMA":database,"TABLE_NAME":table["name"],"COLUMN_NAME":c["Field"],"ORDINAL_POSITION":(i+1).to_string(),"COLUMN_DEFAULT":c["Default"],"IS_NULLABLE":c["Null"],"DATA_TYPE":base,"CHARACTER_MAXIMUM_LENGTH":length,"CHARACTER_OCTET_LENGTH":bytes,"NUMERIC_PRECISION":if numeric{captures.get(2).map(|m|m.as_str())}else{None},"NUMERIC_SCALE":if numeric{Some(captures.get(3).map_or("0",|m|m.as_str()))}else{None},"DATETIME_PRECISION":if temporal{Some(captures.get(2).map_or("0",|m|m.as_str()))}else{None},"CHARACTER_SET_NAME":charset,"COLLATION_NAME":c["Collation"],"COLUMN_TYPE":c["Type"],"COLUMN_KEY":c["Key"],"EXTRA":c["Extra"],"PRIVILEGES":c["Privileges"],"COLUMN_COMMENT":c["Comment"]});
        rows.push(row.as_object().unwrap().clone());
    }
    Ok(rows)
}
/// PHP json_encode defaults used by existing physical-schema fingerprints.
pub fn php_json(v: &Value) -> String {
    fn quoted(s: &str) -> String {
        let mut o = String::from("\"");
        for c in s.chars() {
            match c {
                '"' => o.push_str("\\\""),
                '\\' => o.push_str("\\\\"),
                '/' => o.push_str("\\/"),
                '\n' => o.push_str("\\n"),
                '\r' => o.push_str("\\r"),
                '\t' => o.push_str("\\t"),
                '\u{8}' => o.push_str("\\b"),
                '\u{c}' => o.push_str("\\f"),
                c if c < ' ' || !c.is_ascii() => {
                    let mut buffer = [0; 2];
                    for u in c.encode_utf16(&mut buffer) {
                        o.push_str(&format!("\\u{u:04x}"));
                    }
                }
                _ => o.push(c),
            }
        }
        o.push('"');
        o
    }
    match v {
        Value::String(s) => quoted(s),
        Value::Array(a) => format!("[{}]", a.iter().map(php_json).collect::<Vec<_>>().join(",")),
        Value::Object(o) => format!(
            "{{{}}}",
            o.iter()
                .map(|(k, v)| format!("{}:{}", quoted(k), php_json(v)))
                .collect::<Vec<_>>()
                .join(",")
        ),
        _ => v.to_string(),
    }
}
#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn php_encoding() {
        assert_eq!(
            php_json(&json!(["🌍/é", "a\0b"])),
            r#"["\ud83c\udf0d\/\u00e9","a\u0000b"]"#
        );
    }
}
