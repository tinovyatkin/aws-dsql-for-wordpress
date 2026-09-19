use wp_dsql_native::{compiler::*, value};
#[test]
fn json_and_binary_column_bindings_compile() {
    for ty in ["json", "jsonb", "bytea"] {
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
        for sql in [
            "INSERT INTO wp_probe(payload) VALUES ('{}')",
            "UPDATE wp_probe SET payload='{}'",
        ] {
            let shape = shape_mode(sql, "", true).unwrap();
            let plan = compile(&shape.statement, &shape, &schema).unwrap();
            assert_eq!(plan.bindings.len(), 1);
            assert!(!plan.bindings[0].codec);
            assert_eq!(plan.bindings[0].binary, ty == "bytea");
        }
    }
}
#[test]
fn json_wire_text_preserves_formatting_and_version() {
    let json = r#"{ "n":123456789012345678901234567890, "n":1.2300, "array":[true,null,"🌍"] }"#
        .as_bytes();
    assert_eq!(value::json_text(json, false).unwrap().as_bytes(), json);
    let mut binary = vec![1];
    binary.extend_from_slice(json);
    assert_eq!(value::json_text(&binary, true).unwrap().as_bytes(), json);
    assert_eq!(value::json_text(b"null", false).unwrap(), "null");
    assert!(value::json_text(b"\x02{}", true).is_err());
    assert!(value::json_text(b"", true).is_err());
    assert!(value::json_text(b"\xff", false).is_err());
}
#[test]
fn binary_hex_preserves_all_byte_values() {
    let bytes = (0..=255).collect::<Vec<u8>>();
    let encoded = value::hex(&bytes);
    let decoded = encoded
        .as_bytes()
        .as_chunks::<2>()
        .0
        .iter()
        .map(|p| u8::from_str_radix(std::str::from_utf8(p).unwrap(), 16).unwrap())
        .collect::<Vec<_>>();
    assert_eq!(decoded, bytes);
    assert_eq!(value::hex(b""), "");
}
