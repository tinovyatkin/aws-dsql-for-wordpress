"""Versioned catalog migration and interruption recovery on the synthetic upgrade lab."""
import json, os, subprocess, time
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
LAB=ROOT/'.local/upgrade-lab'
SESSION=LAB/('catalog-'+str(int(time.time())))
ENV={**os.environ,'PGSSLROOTCERT':'system'}
def call(action,*args,expected=0,fault=None):
 env=dict(ENV)
 if fault:env['DSQL_UPGRADE_FAULT']=fault
 p=subprocess.run(['php','scripts/upgrade.php',action,'--session='+str(SESSION),*args],cwd=ROOT,env=env,capture_output=True,text=True)
 with (LAB/'catalog-migration.log').open('a') as f:f.write(p.stdout+p.stderr)
 assert p.returncode==expected,(action,p.returncode)
 return p.stdout
def native(body):
 code="require 'vendor/autoload.php';require 'migration/Backup.php';require 'migration/Plan.php';require 'migration/Restore.php';$target=json_decode(file_get_contents('.local/upgrade-lab/target.json'),true);if(($target['classification']??'')!=='synthetic')throw new RuntimeException('Synthetic lab required');$p=WPDSQLMigration\\Restore::target($target);$p->exec('SET search_path TO wp_live,pg_catalog');"+body
 return subprocess.run(['php','-r',code],cwd=ROOT,env=ENV,check=True,capture_output=True,text=True).stdout
def snapshot():
 return json.loads(native("echo json_encode($p->query(\"SELECT table_name,metadata,fingerprint FROM __wp_dsql_schema WHERE table_name <> '__catalog__' ORDER BY table_name\")->fetchAll(PDO::FETCH_ASSOC));"))
call('begin','--wordpress='+str(ROOT/'.local/upgrade-wordpress'),'--target-config='+str(LAB/'upgrade-target.json'),'--writers-frozen=yes','--backup-reference=synthetic-fixture','--allow-destructive=yes')
call('exec','--','eval',"global $wpdb;$wpdb->query(\"CREATE TABLE wp_catalog_migration_probe (id bigint PRIMARY KEY,label varchar(30) DEFAULT 'literal')\");")
# Deliberately downgrade only the disposable probe row, so interruption coverage is repeatable.
native("$s=$p->query(\"SELECT metadata FROM __wp_dsql_schema WHERE table_name='wp_catalog_migration_probe'\");$m=json_decode($s->fetchColumn(),true);unset($m['schema_version'],$m['table_options']);foreach($m['columns'] as &$c){unset($c['HasDefault'],$c['DefaultExpression']);}unset($c);$s=$p->prepare(\"UPDATE __wp_dsql_schema SET metadata=? WHERE table_name='wp_catalog_migration_probe'\");$s->execute([json_encode($m)]);")
before=snapshot()
call('exec','--','eval',"global $wpdb;$wpdb->get_results('SHOW FULL COLUMNS FROM wp_catalog_migration_probe');")
assert snapshot()==before
print('PASS legacy catalog reads do not rewrite stored rows',flush=True)
call('catalog-migrate',expected=86,fault='catalog-after-write')
state=json.loads((SESSION/'session.json').read_text())
assert state['failed'] and state['catalog_migration']==2 and not state['verified']
assert list(SESSION.glob('catalog-v1-*.json'))
print('PASS interrupted catalog migration retains its guard and durable legacy journal',flush=True)
call('recover')
after=snapshot()
assert {r['table_name']:r['fingerprint'] for r in before}=={r['table_name']:r['fingerprint'] for r in after}
assert all(json.loads(r['metadata'])['schema_version']==2 for r in after)
print('PASS recovery migrates metadata without changing physical schema fingerprints',flush=True)
output=call('catalog-migrate');assert '"migrated_tables":0' in output
print('PASS repeated catalog migration is idempotent',flush=True)
call('exec','--','eval',"global $wpdb;$wpdb->query('DROP TABLE wp_catalog_migration_probe');")
call('verify');call('finish')
print('PASS WordPress dbDelta verifies the migrated catalog and the guard is removed',flush=True)
