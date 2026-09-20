"""Crash/recovery integration through the separately tagged synthetic upgrade fixture."""
from pathlib import Path
import os,subprocess,json
root=Path(__file__).resolve().parents[2]
lib=root/'native/target/release'/('libwp_dsql_native.dylib' if os.uname().sysname=='Darwin' else 'libwp_dsql_native.so')
ca='/etc/ssl/cert.pem' if os.uname().sysname=='Darwin' else '/etc/ssl/certs/ca-certificates.crt'
env=dict(os.environ,SSL_CERT_FILE=ca,PGSSLROOTCERT=ca)
checks=[]
def call(*args,expected=0):
 p=subprocess.run(['php','-d','extension='+str(lib),'tests/automatic/recovery-worker.php',*args],cwd=root,env=env,capture_output=True,text=True)
 with (root/'.local/automatic-recovery-detail.log').open('a') as f:f.write(' '.join(args)+'\n'+p.stdout+p.stderr)
 if p.returncode!=expected:raise RuntimeError('Unexpected recovery worker exit: '+args[0]+' '+str(p.returncode))
for point in ['planned','column-added','backfill-batch','validation-submitted','after-step','catalog-published','journal-completed']:
 call('setup');call('fault',point,expected=86);call('verify');call('cleanup');checks.append(point);print('PASS interrupted '+point,flush=True)
call('setup');call('known-failure');call('verify','original');call('cleanup');checks.append('known failure rollback');print('PASS known failure restores original schema and rows',flush=True)
(root/'.local/automatic-recovery-report.json').write_text(json.dumps({'passed':checks},indent=2))
