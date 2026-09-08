"""Check a persistent plan against changed schema metadata on the synthetic lab."""
import json,os,subprocess,time
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
LAB=ROOT/'.local/upgrade-lab'
SESSION=LAB/('translation-cache-'+str(int(time.time())))
ENV={**os.environ,'PGSSLROOTCERT':'system'}
def run(action,*args,expected=0):
 result=subprocess.run(['php','scripts/upgrade.php',action,'--session='+str(SESSION),*args],cwd=ROOT,env=ENV,capture_output=True,text=True)
 with (LAB/'translation-cache.log').open('a') as log:log.write(result.stdout+result.stderr)
 assert result.returncode==expected,(action,result.returncode)
 return result.stdout
run('begin','--wordpress='+str(ROOT/'.local/upgrade-wordpress'),'--target-config='+str(LAB/'upgrade-target.json'),'--writers-frozen=yes','--backup-reference=synthetic-fixture','--allow-destructive=yes')
run('exec','--','eval',"global $wpdb;$wpdb->query('CREATE TABLE wp_translation_schema_cache (id bigint PRIMARY KEY, payload longtext NULL)');$wpdb->insert('wp_translation_schema_cache',['id'=>1,'payload'=>'clean']);")
run('exec','--','eval',"global $wpdb;$wpdb->query('ALTER TABLE wp_translation_schema_cache ADD KEY payload_key(payload)');")
run('exec','--','eval',"global $wpdb;$wpdb->insert('wp_translation_schema_cache',['id'=>2,'payload'=>\"new\\0value\"]);",expected=1)
state=json.loads((SESSION/'session.json').read_text())
assert state['failed'] and not (SESSION/'pending.json').exists()
assert 'indexed' in (SESSION/'failure.txt').read_text()
run('recover')
out=run('exec','--','eval',"global $wpdb;if($wpdb->get_var('SELECT COUNT(*) FROM wp_translation_schema_cache')!=='1')throw new RuntimeException('Rejected write changed data');$wpdb->query('DROP TABLE wp_translation_schema_cache');echo 'unchanged';")
assert 'unchanged' in out
run('verify');run('finish')
print('PASS cached INSERT rechecks a newly added index and rejects NUL without changing rows')
