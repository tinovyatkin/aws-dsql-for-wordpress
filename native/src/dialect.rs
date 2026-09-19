//! MySQL SQL modes are part of the native parser/cache contract.
use sqlparser::{
    ast::{Expr, Statement, UnaryOperator},
    dialect::{Dialect, MySqlDialect, Precedence},
    keywords::Keyword,
    parser::{Parser, ParserError},
    tokenizer::Token,
};
#[derive(Debug)]
pub struct MysqlMode {
    pub modes: Vec<String>,
}
impl MysqlMode {
    pub fn new(mode: &str) -> Result<Self, String> {
        let mut modes: Vec<String> = mode
            .split(',')
            .map(|s| s.trim().to_ascii_uppercase())
            .filter(|s| !s.is_empty())
            .collect();
        if modes.iter().any(|s| {
            ![
                "ANSI_QUOTES",
                "NO_BACKSLASH_ESCAPES",
                "IGNORE_SPACE",
                "PIPES_AS_CONCAT",
                "HIGH_NOT_PRECEDENCE",
            ]
            .contains(&s.as_str())
        }) {
            return Err("Unsupported SQL execution mode".into());
        }
        modes.sort();
        modes.dedup();
        Ok(Self { modes })
    }
    pub fn has(&self, mode: &str) -> bool {
        self.modes.iter().any(|s| s == mode)
    }
}
impl Dialect for MysqlMode {
    fn dialect(&self) -> std::any::TypeId {
        MySqlDialect {}.dialect()
    }
    fn is_identifier_start(&self, c: char) -> bool {
        MySqlDialect {}.is_identifier_start(c)
    }
    fn is_identifier_part(&self, c: char) -> bool {
        MySqlDialect {}.is_identifier_part(c)
    }
    fn is_delimited_identifier_start(&self, c: char) -> bool {
        c == '`' || (c == '"' && self.has("ANSI_QUOTES"))
    }
    fn identifier_quote_style(&self, _: &str) -> Option<char> {
        Some('`')
    }
    fn supports_string_literal_backslash_escape(&self) -> bool {
        !self.has("NO_BACKSLASH_ESCAPES")
    }
    fn parse_infix(
        &self,
        p: &mut Parser,
        e: &Expr,
        precedence: u8,
    ) -> Option<Result<Expr, ParserError>> {
        MySqlDialect {}.parse_infix(p, e, precedence)
    }
    fn parse_statement(&self, p: &mut Parser) -> Option<Result<Statement, ParserError>> {
        MySqlDialect {}.parse_statement(p)
    }
    fn is_table_factor_alias(&self, explicit: bool, kw: &Keyword, p: &mut Parser) -> bool {
        MySqlDialect {}.is_table_factor_alias(explicit, kw, p)
    }
    fn parse_prefix(&self, p: &mut Parser) -> Option<Result<Expr, ParserError>> {
        if self.has("HIGH_NOT_PRECEDENCE")
            && matches!(p.peek_token().token,Token::Word(ref w) if w.keyword==Keyword::NOT)
        {
            p.next_token();
            return Some(
                p.parse_subexpr(self.prec_value(Precedence::MulDivModOp) + 1)
                    .map(|e| Expr::UnaryOp {
                        op: UnaryOperator::Not,
                        expr: Box::new(e),
                    }),
            );
        }
        None
    }
    fn supports_string_literal_concatenation(&self) -> bool {
        MySqlDialect {}.supports_string_literal_concatenation()
    }
    fn ignores_wildcard_escapes(&self) -> bool {
        MySqlDialect {}.ignores_wildcard_escapes()
    }
    fn supports_numeric_prefix(&self) -> bool {
        MySqlDialect {}.supports_numeric_prefix()
    }
    fn supports_bitwise_shift_operators(&self) -> bool {
        MySqlDialect {}.supports_bitwise_shift_operators()
    }
    fn supports_multiline_comment_hints(&self) -> bool {
        MySqlDialect {}.supports_multiline_comment_hints()
    }
    fn require_interval_qualifier(&self) -> bool {
        MySqlDialect {}.require_interval_qualifier()
    }
    fn supports_limit_comma(&self) -> bool {
        MySqlDialect {}.supports_limit_comma()
    }
    fn supports_create_table_select(&self) -> bool {
        MySqlDialect {}.supports_create_table_select()
    }
    fn supports_insert_set(&self) -> bool {
        MySqlDialect {}.supports_insert_set()
    }
    fn supports_user_host_grantee(&self) -> bool {
        MySqlDialect {}.supports_user_host_grantee()
    }
    fn supports_table_hints(&self) -> bool {
        MySqlDialect {}.supports_table_hints()
    }
    fn requires_single_line_comment_whitespace(&self) -> bool {
        MySqlDialect {}.requires_single_line_comment_whitespace()
    }
    fn supports_match_against(&self) -> bool {
        MySqlDialect {}.supports_match_against()
    }
    fn supports_select_modifiers(&self) -> bool {
        MySqlDialect {}.supports_select_modifiers()
    }
    fn supports_set_names(&self) -> bool {
        MySqlDialect {}.supports_set_names()
    }
    fn supports_comma_separated_set_assignments(&self) -> bool {
        MySqlDialect {}.supports_comma_separated_set_assignments()
    }
    fn supports_update_order_by(&self) -> bool {
        MySqlDialect {}.supports_update_order_by()
    }
    fn supports_data_type_signed_suffix(&self) -> bool {
        MySqlDialect {}.supports_data_type_signed_suffix()
    }
    fn supports_cross_join_constraint(&self) -> bool {
        MySqlDialect {}.supports_cross_join_constraint()
    }
    fn supports_double_ampersand_operator(&self) -> bool {
        MySqlDialect {}.supports_double_ampersand_operator()
    }
    fn supports_binary_kw_as_cast(&self) -> bool {
        MySqlDialect {}.supports_binary_kw_as_cast()
    }
    fn supports_comment_optimizer_hint(&self) -> bool {
        MySqlDialect {}.supports_comment_optimizer_hint()
    }
    fn supports_constraint_keyword_without_name(&self) -> bool {
        MySqlDialect {}.supports_constraint_keyword_without_name()
    }
    fn supports_key_column_option(&self) -> bool {
        MySqlDialect {}.supports_key_column_option()
    }
    fn supports_group_by_with_modifier(&self) -> bool {
        MySqlDialect {}.supports_group_by_with_modifier()
    }
    fn supports_left_associative_joins_without_parens(&self) -> bool {
        MySqlDialect {}.supports_left_associative_joins_without_parens()
    }
}
