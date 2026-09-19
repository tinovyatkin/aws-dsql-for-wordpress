//! Value-free error events compatible with the existing PHP/FPM log collector.
use crate::engine::ErrorInfo;
use regex::bytes::Regex;
use serde_json::{Value, json};
use sha2::{Digest, Sha256};
use std::sync::OnceLock;
pub fn normalize(sql: &[u8]) -> Vec<u8> {
    static TOKENS: OnceLock<Regex> = OnceLock::new();
    let regex=TOKENS.get_or_init(||Regex::new(r##"(?is-u)(/\*.*?(?:\*/|$)|--[^\r\n]*|\#[^\r\n]*)|(?:\$(?:[a-zA-Z_][a-zA-Z_0-9]*)?\$)|(?:'(?:\\.|''|[^'\\])*(?:'|$)|"(?:\\.|""|[^"\\])*(?:"|$)|`(?:``|[^`])*(?:`|$))|(?:\b(?:0x[0-9a-f]+|\d+(?:\.\d*)?(?:e[+-]?\d+)?)\b)|(?:[a-zA-Z_][a-zA-Z_0-9$]*)|(?:\s+)|."##).expect("fixed diagnostic tokenizer"));
    let mut tokens: Vec<Vec<u8>> = vec![];
    let mut skip_until = 0;
    for m in regex.find_iter(sql) {
        if m.start() < skip_until {
            continue;
        }
        let token = m.as_bytes();
        if token.starts_with(b"$") && token.ends_with(b"$") {
            let rest = &sql[m.end()..];
            skip_until = rest
                .windows(token.len())
                .position(|w| w == token)
                .map(|i| m.end() + i + token.len())
                .unwrap_or(sql.len());
            tokens.push(b"?".to_vec());
        } else if token.starts_with(b"/*")
            || token.starts_with(b"--")
            || token.starts_with(b"#")
            || token[0].is_ascii_whitespace()
        {
            continue;
        } else if b"'\"`".contains(&token[0]) || token[0].is_ascii_digit() {
            tokens.push(b"?".to_vec());
        } else {
            tokens.push(token.to_ascii_uppercase());
        }
    }
    tokens.join(&b' ')
}
pub fn event(
    sql: &[u8],
    info: &ErrorInfo,
    context: &str,
    source: &str,
    stage: &str,
    elapsed_ms: f64,
) -> Value {
    let normalized = normalize(sql);
    let word = normalized.split(|b| *b == b' ').next().unwrap_or_default();
    let operation = std::str::from_utf8(word)
        .ok()
        .filter(|s| {
            [
                "SELECT", "INSERT", "UPDATE", "DELETE", "REPLACE", "CREATE", "ALTER", "DROP",
                "SHOW", "DESCRIBE", "SET", "BEGIN", "START", "COMMIT", "ROLLBACK", "WITH",
                "EXPLAIN", "TRUNCATE",
            ]
            .contains(s)
        })
        .unwrap_or("OTHER");
    static SOURCE: OnceLock<regex::Regex> = OnceLock::new();
    let source=if SOURCE.get_or_init(||regex::Regex::new(r"^(?:wp-content/(?:mu-plugins|plugins|themes)/|wp-includes/|wp-admin/)[a-zA-Z0-9_./-]+\.php:[0-9]+$").unwrap()).is_match(source){source}else{"unclassified"};
    let context = if ["cli", "cron", "rest", "admin", "frontend"].contains(&context) {
        context
    } else {
        "frontend"
    };
    let stage = if [
        "connect",
        "translate",
        "metadata",
        "execute",
        "decode",
        "schema",
        "close",
        "escape",
        "internal",
        "native",
        "reported",
    ]
    .contains(&stage)
    {
        stage
    } else {
        "native"
    };
    let reported = stage == "reported";
    let state = if reported {
        None
    } else {
        info.sqlstate.as_deref()
    };
    let elapsed = if elapsed_ms.is_finite() {
        (elapsed_ms.max(0.) * 100.).round() / 100.
    } else {
        0.
    };
    json!({"event":"wordpress_dsql_error","version":1,"timestamp":chrono::Utc::now().format("%Y-%m-%dT%H:%M:%SZ").to_string(),"stage":stage,"kind":if !reported&&info.database{"database"}else{"adapter"},"sqlstate":state,"operation":operation,"fingerprint":format!("{:x}",Sha256::digest(&normalized)),"source":source,"context":context,"elapsed_ms":elapsed,"retries":if reported{0}else{info.retries}})
}
#[cfg(feature = "php")]
pub fn emit(event: &Value) {
    // This is PHP's native C logging API, not a PHP userland callback. It respects
    // the active pool's error_log configuration and existing rotation/collection.
    unsafe extern "C" {
        fn php_log_err_with_severity(message: *const std::ffi::c_char, severity: std::ffi::c_int);
    }
    if let Ok(json) = serde_json::to_string(event)
        && let Ok(message) = std::ffi::CString::new(json)
    {
        unsafe {
            php_log_err_with_severity(message.as_ptr(), 5);
        }
    }
}
#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn values_and_malformed_tails_are_redacted() {
        assert_eq!(
            normalize(b"SELECT * FROM `wp_posts` WHERE id=123 AND title='private'"),
            normalize(b"select * from `other` where id=456 and title='different'")
        );
        for sql in [
            b"SELECT 'private unterminated".as_slice(),
            b"SELECT $$private$tag$still private$$",
            b"SELECT 1 /* private unfinished",
        ] {
            let s = normalize(sql);
            assert!(!String::from_utf8_lossy(&s).contains("private"));
        }
    }
    #[test]
    fn event_never_exports_query_or_message() {
        let event = event(
            b"SELECT 'private-content'",
            &ErrorInfo::default(),
            "bad-private-context",
            "/private/path/private.php:1",
            "unknown-private-stage",
            f64::NAN,
        );
        let text = event.to_string();
        assert!(!text.contains("private"));
        assert_eq!(event["event"], "wordpress_dsql_error");
        assert_eq!(event["fingerprint"].as_str().unwrap().len(), 64);
    }
}
