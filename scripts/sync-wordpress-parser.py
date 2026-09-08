#!/usr/bin/env python3
"""Vendor a pinned standalone WordPress MySQL parser under a private namespace."""
from pathlib import Path
from urllib.request import urlopen
import hashlib,json
ROOT=Path(__file__).resolve().parents[1]
COMMIT='bf181a286470be4de550112ce1f1d05322502c0e'
BASE=f'https://raw.githubusercontent.com/WordPress/sqlite-database-integration/{COMMIT}/packages/mysql-parser/'
FILES=['LICENSE','README.md','src/class-wp-mysql-lexer.php','src/class-wp-mysql-token.php','src/class-wp-mysql-parser-factory.php','src/mysql-parse-table.php','src/parser/class-wp-parser.php','src/parser/class-wp-parser-grammar.php','src/parser/class-wp-parser-node.php','src/parser/class-wp-parser-token.php']
manifest=ROOT/'parser/mysql/WordPress/source.json'
previous=json.loads(manifest.read_text()) if manifest.exists() else None
hashes={}
for name in FILES:
 data=urlopen(BASE+name,timeout=30).read();digest=hashlib.sha256(data).hexdigest();hashes[name]=digest
 if previous and previous['sha256'][name]!=digest:raise SystemExit('Upstream checksum mismatch: '+name)
 path=manifest.parent/name;path.parent.mkdir(parents=True,exist_ok=True)
 if name.endswith('.php') and name!='src/mysql-parse-table.php':
  data=data.replace(b'<?php',b'<?php\nnamespace WPDSQL\\MySQL\\WordPress;\nuse \\ReflectionClass;',1)
 if name=='src/class-wp-mysql-lexer.php':
  # Preserve strict rejection of unterminated comments in both lexer APIs.
  patches=[
   (b'private $in_mysql_comment = false;',b'private $in_mysql_comment = false;\n\tprivate $invalid_comment = false;'),
   (b'$type = self::EOF;',b'$type = ( $this->in_mysql_comment || $this->invalid_comment ) ? null : self::EOF;'),
   (b'if ( false === $comment_end ) {',b'if ( false === $comment_end ) {\n\t\t\t$this->invalid_comment = true;'),
  ]
  for old,new in patches:
   if data.count(old)!=1:raise SystemExit('Lexer patch no longer matches upstream')
   data=data.replace(old,new)
 path.write_bytes(data)
manifest.write_text(json.dumps({'repository':'https://github.com/WordPress/sqlite-database-integration','commit':COMMIT,'package':'packages/mysql-parser','namespace':'WPDSQL\\MySQL\\WordPress','patches':['namespace-imports','strict-comment-termination-v1'],'sha256':hashes},indent=2)+'\n')
print('Pinned WordPress parser synchronized; namespace/import adaptation and strict-comment fix applied.')
