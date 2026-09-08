"""Check differential results, with two explicit existing backend differences."""
import json
from pathlib import Path
root=Path(__file__).resolve().parents[2]/'.local/translation-results'
mysql=json.loads((root/'differential-mysql.json').read_text())
dsql=json.loads((root/'differential-dsql.json').read_text())
assert 'failure' not in mysql and 'failure' not in dsql, 'A backend collection failed'
dsql.pop('cache_stats',None)
expected=json.loads(json.dumps(mysql))
# DSQL LOWER does not perform MySQL's Unicode case mapping under its collation.
expected['nested'][1]['label']='gamma:Русский 🌍'
# Native DSQL reports one affected row for an upsert update; MySQL reports two.
expected['upsert']=1
assert mysql['upsert']==2
assert dsql==expected, {key:(mysql.get(key),dsql.get(key)) for key in expected if dsql.get(key)!=expected[key]}
print(f'PASS {len(expected)} result/write-effect comparisons; two explicit backend differences')
