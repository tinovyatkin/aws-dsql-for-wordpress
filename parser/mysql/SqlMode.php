<?php
namespace WPDSQL\MySQL;

/** SQL modes that affect Oracle's grammar, rather than execution semantics. */
enum SqlMode {
    case AnsiQuotes;
    case HighNotPrecedence;
    case PipesAsConcat;
    case IgnoreSpace;
    case NoBackslashEscapes;
}
