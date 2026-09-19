//! Small strict MySQL administrative grammar absent/incomplete in sqlparser-rs.
//! Expressions use the same native expression parser; all input tokens are consumed.
use crate::compiler::Result;
use crate::dialect::MysqlMode;
use sqlparser::{
    ast::Expr,
    parser::Parser,
    tokenizer::{Token, Word},
};
#[derive(Clone, Debug)]
pub enum Filter {
    Like(String),
    Where(Box<Expr>),
}
#[derive(Clone, Debug)]
pub struct Command {
    pub kind: String,
    pub full: bool,
    pub names: Vec<Vec<String>>,
    pub database: Option<String>,
    pub filter: Option<Filter>,
}
struct Cursor {
    tokens: Vec<Token>,
    at: usize,
}
impl Cursor {
    fn word(&mut self, s: &str) -> bool {
        if matches!(self.tokens.get(self.at),Some(Token::Word(w)) if w.quote_style.is_none()&&w.value.eq_ignore_ascii_case(s))
        {
            self.at += 1;
            true
        } else {
            false
        }
    }
    fn expect(&mut self, s: &str) -> Result<()> {
        if self.word(s) {
            Ok(())
        } else {
            Err("Invalid MySQL metadata statement".into())
        }
    }
    fn id(&mut self) -> Result<String> {
        match self.tokens.get(self.at) {
            Some(Token::Word(Word { value, .. })) => {
                self.at += 1;
                Ok(value.clone())
            }
            _ => Err("Metadata identifier required".into()),
        }
    }
    fn name(&mut self) -> Result<Vec<String>> {
        let mut parts = vec![self.id()?];
        if matches!(self.tokens.get(self.at), Some(Token::Period)) {
            self.at += 1;
            parts.push(self.id()?);
        }
        Ok(parts)
    }
    fn from(&mut self) -> bool {
        self.word("FROM") || self.word("IN")
    }
    fn filter(&mut self, d: &MysqlMode) -> Result<Option<Filter>> {
        if self.word("LIKE") {
            let s = match self.tokens.get(self.at) {
                Some(Token::SingleQuotedString(s) | Token::DoubleQuotedString(s)) => s.clone(),
                _ => return Err("Metadata LIKE requires a literal pattern".into()),
            };
            self.at += 1;
            return Ok(Some(Filter::Like(s)));
        }
        if self.word("WHERE") {
            let mut p = Parser::new(d).with_tokens(self.tokens[self.at..].to_vec());
            let e = p.parse_expr().map_err(|_| "Invalid metadata predicate")?;
            if p.peek_token().token != Token::EOF {
                return Err("Trailing metadata input".into());
            }
            self.at = self.tokens.len();
            return Ok(Some(Filter::Where(Box::new(e))));
        }
        Ok(None)
    }
}
pub fn parse(tokens: &[Token], dialect: &MysqlMode) -> Result<Option<Command>> {
    let mut tokens = tokens
        .iter()
        .filter(|t| !matches!(t, Token::Whitespace(_)))
        .cloned()
        .collect::<Vec<_>>();
    if tokens.last() == Some(&Token::SemiColon) {
        tokens.pop();
    }
    let mut p = Cursor { tokens, at: 0 };
    let mut out = Command {
        kind: String::new(),
        full: false,
        names: vec![],
        database: None,
        filter: None,
    };
    if p.word("SHOW") {
        out.full = p.word("FULL");
        let scope = p.word("GLOBAL") || p.word("SESSION") || p.word("LOCAL");
        if p.word("VARIABLES") {
            out.kind = "variables".into();
        } else {
            if scope {
                return Err("Unsupported SHOW scope".into());
            }
            if p.word("TABLES") {
                out.kind = "tables".into();
                if p.from() {
                    out.database = Some(p.id()?);
                }
            } else if p.word("TABLE") {
                p.expect("STATUS")?;
                out.kind = "status".into();
                if p.from() {
                    out.database = Some(p.id()?);
                }
            } else if p.word("CREATE") {
                p.expect("TABLE")?;
                out.kind = "create".into();
                out.names.push(p.name()?);
            } else if p.word("COLUMNS") || p.word("FIELDS") {
                out.kind = "columns".into();
                if !p.from() {
                    return Err("SHOW COLUMNS requires FROM".into());
                }
                out.names.push(p.name()?);
                if p.from() {
                    out.database = Some(p.id()?);
                }
            } else if p.word("KEYS") || p.word("INDEX") || p.word("INDEXES") {
                out.kind = "keys".into();
                if !p.from() {
                    return Err("SHOW INDEX requires FROM".into());
                }
                out.names.push(p.name()?);
                if p.from() {
                    out.database = Some(p.id()?);
                }
            } else {
                return Err("Unsupported SHOW statement".into());
            }
        }
        if out.full && !matches!(out.kind.as_str(), "columns" | "tables") {
            return Err("Unsupported FULL modifier".into());
        }
        if out.kind != "create" {
            out.filter = p.filter(dialect)?;
        }
    } else if p.word("DESCRIBE") || p.word("DESC") {
        out.kind = "describe".into();
        out.names.push(p.name()?);
        out.filter = p.filter(dialect)?;
    } else {
        for op in ["CHECK", "REPAIR", "ANALYZE", "OPTIMIZE"] {
            if p.word(op) {
                out.kind = op.to_ascii_lowercase();
                break;
            }
        }
        if out.kind.is_empty() {
            return Ok(None);
        }
        p.expect("TABLE")?;
        loop {
            out.names.push(p.name()?);
            if p.tokens.get(p.at) == Some(&Token::Comma) {
                p.at += 1;
            } else {
                break;
            }
        }
    }
    if p.at != p.tokens.len() {
        return Err("Unsupported trailing metadata syntax".into());
    }
    Ok(Some(out))
}
