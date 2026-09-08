"""Compare completed synthetic MySQL and DSQL core-query runs."""
import json
from pathlib import Path
root = Path(__file__).resolve().parents[2]
mysql = json.loads((root / '.local/core-sql-mysql-results.json').read_text())
dsql = json.loads((root / '.local/core-sql-dsql-results.json').read_text())
assert 'failure' not in mysql and 'failure' not in dsql, 'An execution run failed'
assert dsql.pop('_dsql_metadata') == 'passed'
assert mysql.keys() == dsql.keys(), 'Different query coverage'
for case, expected in mysql.items():
    assert dsql[case] == expected, f'Result difference: {case}'
print(f'PASS {len(mysql)} MySQL/DSQL result groups; {len(mysql["week_0"])} date values across each of eight WEEK modes; DSQL metadata contracts')
