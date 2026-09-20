"""A waiting loopback can acquire DDL ownership; later DB access reacquires the lease."""
from pathlib import Path
import fcntl,os,select,subprocess,tempfile
root=Path(__file__).resolve().parents[2]
lib=root/'native/target/release'/('libwp_dsql_native.dylib' if os.uname().sysname=='Darwin' else 'libwp_dsql_native.so')
with tempfile.TemporaryDirectory(prefix='wp-dsql-gate-') as directory:
 code='''require 'vendor/autoload.php';
$d=new WPDSQL\\Engine\\NativeDriver(new WPDSQL\\Engine\\Config(host:'not-real.dsql.eu-central-1.on.aws',region:'eu-central-1',profile:'nonexistent',automaticSchema:true,schemaUser:'wp_upgrader',schemaStateDirectory:$argv[1]));
echo "ready\\n";fflush(STDOUT);fgets(STDIN);
if(!WPDSQL\\Engine\\NativeDriver::suspendSchemaForHttp())exit(2);
echo "suspended\\n";fflush(STDOUT);fgets(STDIN);
$d->escape('resume');echo "resumed\\n";fflush(STDOUT);fgets(STDIN);'''
 p=subprocess.Popen(['php','-d','extension='+str(lib),'-r',code,directory],cwd=root,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
 try:
  assert p.stdout.readline().strip()=='ready'
  with open(Path(directory)/'access.lock','r+') as lock:
   try:fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
   except BlockingIOError:pass
   else:raise AssertionError('Normal request did not hold a shared lease')
   p.stdin.write('http\n');p.stdin.flush();assert p.stdout.readline().strip()=='suspended'
   fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
   p.stdin.write('database\n');p.stdin.flush();assert not select.select([p.stdout],[],[],.3)[0], 'Request escaped exclusive migration gate'
   fcntl.flock(lock,fcntl.LOCK_UN);assert p.stdout.readline().strip()=='resumed'
   try:fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
   except BlockingIOError:pass
   else:raise AssertionError('Database access did not resume shared lease')
  p.stdin.write('exit\n');p.stdin.flush();assert p.wait(timeout=5)==0
 finally:
  if p.poll() is None:p.kill();p.wait()
print('PASS request isolation, HTTP lease suspension and database reacquisition')
