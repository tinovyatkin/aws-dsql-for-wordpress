#!/usr/bin/env python3
"""Generate PHP from the pinned Oracle grammar; no manual generated-file edits."""
from pathlib import Path
import hashlib
import json
import re
import subprocess
from urllib.request import urlopen

ROOT = Path(__file__).resolve().parents[1]
VERSION = '4.13.2'
JAR_HASH = 'eae2dfa119a64327444672aff63e9ec35a20180dc5b8090b7a6ab85125df4d76'
jar = ROOT / '.local/tools' / f'antlr-{VERSION}-complete.jar'
if not jar.exists():
    jar.parent.mkdir(parents=True, exist_ok=True)
    jar.write_bytes(urlopen(f'https://www.antlr.org/download/antlr-{VERSION}-complete.jar', timeout=60).read())
if hashlib.sha256(jar.read_bytes()).hexdigest() != JAR_HASH:
    raise SystemExit('ANTLR generator checksum mismatch')
work = ROOT / '.local/antlr-grammar'
work.mkdir(parents=True, exist_ok=True)
manifest = json.loads((ROOT / 'grammar/oracle/source.json').read_text())
for name in ['MySQLLexer', 'MySQLParser']:
    source = (ROOT / 'grammar/oracle' / f'{name}.g4').read_bytes()
    if hashlib.sha256(source).hexdigest() != manifest['sha256'][f'{name}.g4']:
        raise SystemExit(f'Oracle grammar checksum mismatch: {name}')
    text = source.decode()
    notice = re.search(r'/\*\n \* Copyright.*?\*/', text, re.S).group(0)
    text = text.replace("I N T E R S E C T '_' S Y M B O L", 'I N T E R S E C T')
    text = text.replace('UNDERLINE_SYMBOL [a-z0-9]+', 'UNDERLINE_SYMBOL [a-zA-Z0-9]+')
    text = re.sub(r'@header\s*\{.*?\n\}', '', text, count=1, flags=re.S)
    text = text.replace('this.text', 'this.getText()')
    text = text.replace('this.', r'\$this->')
    text = text.replace('MySQLLexer.', 'MySQLLexer::').replace('SqlMode.', 'SqlMode::')
    header = ('@header {\n' + notice + '\n'
              'use WPDSQL\\MySQL\\MySQLBaseLexer;\n'
              'use WPDSQL\\MySQL\\MySQLBaseRecognizer;\n'
              'use WPDSQL\\MySQL\\SqlMode;\n}\n')
    first, rest = text.split('\n', 1)
    text = first + '\n' + header + rest
    (work / f'{name}.g4').write_text(text)
output = ROOT / 'parser/mysql/Generated'
output.mkdir(parents=True, exist_ok=True)
subprocess.run(['java', '-jar', str(jar), '-Dlanguage=PHP', '-package', 'WPDSQL\\MySQL\\Generated',
                '-visitor', '-no-listener', '-Xexact-output-dir', '-o', str(output),
                'MySQLLexer.g4', 'MySQLParser.g4'], cwd=work, check=True)
# These generator side products are only needed while generating.
for path in output.iterdir():
    if path.suffix in {'.interp', '.tokens'}:
        path.unlink()
    elif path.suffix == '.php':
        path.write_text('\n'.join(re.sub(r'^[ \t]+', lambda m: m.group(0).expandtabs(4), line.rstrip()) for line in path.read_text().splitlines()) + '\n')
print('Generated PHP lexer, parser, and visitors.')
