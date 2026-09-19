#!/usr/bin/env python3
"""Start a disposable local FPM worker, benchmark, and stop it without global configuration."""
import os,pathlib,shutil,socket,subprocess,signal,time
root=pathlib.Path(__file__).resolve().parents[2]
local=root/'.local';local.mkdir(exist_ok=True)
lib=root/'native/target/release'/('libwp_dsql_native.dylib' if os.uname().sysname=='Darwin' else 'libwp_dsql_native.so')
fpm=shutil.which('php-fpm') or '/opt/homebrew/sbin/php-fpm'
ca=os.environ.get('NATIVE_CA_BUNDLE','/etc/ssl/cert.pem' if os.uname().sysname=='Darwin' else '/etc/ssl/certs/ca-certificates.crt')
if not pathlib.Path(ca).is_file():raise SystemExit('Set NATIVE_CA_BUNDLE to your CA certificate bundle')
env=dict(os.environ,PGSSLROOTCERT=ca,SSL_CERT_FILE=ca)
with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
conf=local/'native-benchmark-fpm.conf'
conf.write_text(f'''[global]
daemonize = no
error_log = {local}/native-benchmark-fpm.log
[prototype]
listen = 127.0.0.1:{port}
pm = static
pm.max_children = 1
pm.max_requests = 0
clear_env = no
catch_workers_output = yes
security.limit_extensions = .php
php_admin_value[memory_limit] = 256M
php_admin_value[opcache.enable] = 1
''')
created=False;process=None
try:
    if not (local/'native-fixture.json').exists():
        subprocess.run(['php','native/tests/fixture.php'],cwd=root,env=env,check=True);created=True
    subprocess.run(['php','-d',f'extension={lib}','native/tests/integration.php'],cwd=root,env=env,check=True)
    process=subprocess.Popen([fpm,'-F','-y',str(conf),'-d',f'extension={lib}'],cwd=root,env=env)
    for _ in range(100):
        if process.poll() is not None:raise RuntimeError('FPM failed to start')
        try:
            with socket.create_connection(('127.0.0.1',port),timeout=.1):break
        except OSError:time.sleep(.1)
    else:raise RuntimeError('FPM startup timed out')
    for fresh in [False,True]:
        output=local/('native-fpm-fresh.json' if fresh else 'native-fpm-warm.json')
        args=['python3','native/tests/fpm-benchmark.py','--port',str(port),'--samples','6' if fresh else '12','--output',str(output)]
        if fresh:args+=['--fresh']
        subprocess.run(args,cwd=root,env=env,check=True)
finally:
    if process is not None and process.poll() is None:
        process.send_signal(signal.SIGQUIT)
        try:process.wait(timeout=15)
        except subprocess.TimeoutExpired:process.kill();process.wait()
    conf.unlink(missing_ok=True)
    if created:subprocess.run(['php','native/tests/fixture.php','cleanup'],cwd=root,env=env,check=True)
