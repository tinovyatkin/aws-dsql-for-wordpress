//! Byte-for-byte compatibility with the released adapter's versioned text envelope.
use base64::{Engine, engine::general_purpose::STANDARD};
use sha2::{Digest, Sha256};
const PREFIX: &str = "~dsqlb64:v1:";
pub fn encode(value:&str)->String {
    if !value.contains('\0') && !value.starts_with(PREFIX) {return value.into();}
    format!("{PREFIX}{:x}:{}",Sha256::digest(value.as_bytes()),STANDARD.encode(value.as_bytes()))
}
pub fn decode(value:&str)->Result<String,String> {
    let Some(frame)=value.strip_prefix(PREFIX) else{return Ok(value.into())};
    let (digest,payload)=frame.split_once(':').ok_or("Malformed DSQL value envelope")?;
    if digest.len()!=64{return Err("Malformed DSQL value envelope".into());}
    let bytes=STANDARD.decode(payload).map_err(|_|"Malformed DSQL value envelope")?;
    if STANDARD.encode(&bytes)!=payload || format!("{:x}",Sha256::digest(&bytes))!=digest{return Err("DSQL value envelope checksum mismatch".into());}
    String::from_utf8(bytes).map_err(|_|"DSQL value envelope is not UTF-8".into())
}
#[cfg(test)]mod tests {
    use super::*;
    #[test]fn roundtrip(){for v in ["normal 🌍","a\0b", "~dsqlb64:v1:literal"]{assert_eq!(decode(&encode(v)).unwrap(),v)}}
    #[test]fn corruption(){for v in ["~dsqlb64:v1:bad","~dsqlb64:v1:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa:YQ=="]{assert!(decode(v).is_err());}}
}
