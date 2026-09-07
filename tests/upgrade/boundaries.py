"""Production-shaped rejection tests on the synthetic upgrade cluster."""
import json, os, subprocess, time
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2];LAB=ROOT/'.local/upgrade-lab';SESSION=LAB/('boundaries-'+str(int(time.time())));ENV={**os.environ,'PGSSLROOTCERT':'system'}
def call(action,*args,expected=0,fault=None):
 env=dict(ENV)
 if fault:env['DSQL_UPGRADE_FAULT']=fault
 p=subprocess.run(['php','scripts/upgrade.php',action,'--session='+str(SESSION),*args],cwd=ROOT,env=env,capture_output=True,text=True)
 with (LAB/'boundaries.log').open('a') as f:f.write(p.stdout+p.stderr)
 assert p.returncode==expected,(action,p.returncode)
 return p.stdout
def sql(statement,**kwargs):return call('exec','--','eval','global $wpdb;$wpdb->query('+json.dumps(statement)+');',**kwargs)
call('begin','--wordpress='+str(ROOT/'.local/upgrade-wordpress'),'--target-config='+str(LAB/'upgrade-target.json'),'--writers-frozen=yes','--backup-reference=synthetic-fixture','--allow-destructive=yes')
sql("CREATE TABLE wp_upgrade_boundary (id bigint PRIMARY KEY, label varchar(30) NOT NULL)")
sql("INSERT INTO wp_upgrade_boundary VALUES (1,'original')")
call('exec','--','eval',"global $wpdb;try{$wpdb->query('ALTER TABLE wp_upgrade_boundary ADD UNIQUE KEY unsupported(label(4))');}catch(Throwable $e){}echo 'swallowed';",expected=1)
assert json.loads((SESSION/'session.json').read_text())['failed']
call('recover')
print('PASS swallowed schema exception still fails the overall command',flush=True)
sql("ALTER TABLE wp_upgrade_boundary ADD COLUMN extra varchar(20) DEFAULT 'new'",fault='after-copy',expected=1)
pending=json.loads((SESSION/'pending.json').read_text())
code="require 'vendor/autoload.php';require 'migration/Backup.php';require 'migration/Plan.php';require 'migration/Restore.php';$p=WPDSQLMigration\\Restore::target(json_decode(file_get_contents('.local/upgrade-lab/target.json'),true));$p->exec(\"UPDATE wp_live.\\\""+pending['temp']+"\\\" SET label='tampered' WHERE id=1\");"
subprocess.run(['php','-r',code],cwd=ROOT,env=ENV,check=True,capture_output=True)
call('recover',expected=1)
call('cancel')
print('PASS changed replacement data cannot be published on recovery',flush=True)
assert 'original' in call('exec','--','eval',"global $wpdb;echo $wpdb->get_var('SELECT label FROM wp_upgrade_boundary WHERE id=1');")
sql('DROP TABLE wp_upgrade_boundary')
call('verify');call('finish')
p=subprocess.run(['php','-d','error_reporting=8191','/opt/homebrew/bin/wp','--path='+str(ROOT/'.local/upgrade-wordpress'),'eval',"global $wpdb;$wpdb->suppress_errors(true);echo $wpdb->query('CREATE TABLE wp_uncontrolled (id bigint)')===false?'rejected':'unsafe';"],cwd=ROOT,env=ENV,capture_output=True,text=True)
assert p.returncode==0 and 'rejected' in p.stdout
print('PASS ordinary runtime cannot perform managed DDL',flush=True)
