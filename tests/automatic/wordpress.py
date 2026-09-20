"""Exercise WordPress install/activation, background update, dbDelta, and public capability gates."""
from pathlib import Path
import functools,http.server,json,os,shutil,socket,subprocess,threading,time,zipfile
root=Path(__file__).resolve().parents[2];wp=root/'.local/upgrade-wordpress';out=root/'.local/automatic-wordpress';out.mkdir(exist_ok=True)
settings=json.loads((root/'.local/upgrade-lab/runtime-target.json').read_text());assert settings['classification']=='synthetic'
live=json.loads(subprocess.check_output(['aws','--profile',settings['profile'],'--region',settings['region'],'dsql','get-cluster','--identifier',settings['endpoint'].split('.')[0]]));assert live['tags']['Purpose']=='synthetic-wordpress-migration'
state=out/'state';state.mkdir(exist_ok=True);ini=out/'ini';ini.mkdir(exist_ok=True)
lib=root/'native/target/release'/('libwp_dsql_native.dylib' if os.uname().sysname=='Darwin' else 'libwp_dsql_native.so')
with socket.socket() as sock:sock.bind(('127.0.0.1',0));wpport=sock.getsockname()[1]
wpurl='http://127.0.0.1:'+str(wpport)
pre=out/'prepend.php';pre.write_text("<?php define('DSQL_ENGINE','native');define('DSQL_AUTOMATIC_SCHEMA',true);define('DSQL_SCHEMA_USER','wp_upgrader');define('DSQL_SCHEMA_STATE_DIRECTORY',"+json.dumps(str(state))+');')
(ini/'native.ini').write_text('extension='+str(lib)+'\nauto_prepend_file='+str(pre)+'\n')
ca='/etc/ssl/cert.pem' if os.uname().sysname=='Darwin' else '/etc/ssl/certs/ca-certificates.crt'
env=dict(os.environ,PHP_INI_SCAN_DIR=':'+str(ini),PGSSLROOTCERT=ca,SSL_CERT_FILE=ca)
base=['php','-d','error_reporting=8191','-d','zend.exception_ignore_args=1',shutil.which('wp'),'--path='+str(wp)]
def command(*args):
 p=subprocess.run(base+list(args),cwd=root,env=env,capture_output=True,text=True)
 with (out/'output.log').open('a') as f:f.write(' '.join(args[:2])+'\n'+p.stdout+p.stderr)
 if p.returncode:raise RuntimeError('WordPress command failed: '+' '.join(args[:2]))
 return p.stdout
class Quiet(http.server.SimpleHTTPRequestHandler):
 def log_message(self,*args):pass
server=http.server.ThreadingHTTPServer(('127.0.0.1',0),functools.partial(Quiet,directory=str(out)));thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
url='http://127.0.0.1:'+str(server.server_port)+'/probe-1.1.0.zip'
mu=wp/'wp-content/mu-plugins/000-dsql-automatic-test.php';assert not mu.exists()
mu.write_text("""<?php
if(wp_get_environment_type()!=='local')throw new RuntimeException('Local only');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
add_filter('option_home',static fn()=>'__WPURL__',PHP_INT_MAX);
add_filter('option_siteurl',static fn()=>'__WPURL__',PHP_INT_MAX);
add_filter('pre_http_request',static function($pre,$args,$url){return str_starts_with($url,'http://127.0.0.1:')?false:new WP_Error('offline_fixture','External HTTP blocked');},PHP_INT_MAX,3);
add_filter('pre_wp_mail','__return_false');
add_action('wp_loaded',static function(){if(isset($_GET['wp_scrape_key']))exit('Synthetic upgrade loopback');},PHP_INT_MAX);
add_filter('http_request_args',static function($args,$url){if(str_starts_with($url,'http://127.0.0.1:'))$args['reject_unsafe_urls']=false;return $args;},PHP_INT_MAX,2);
add_filter('file_mod_allowed','__return_true',PHP_INT_MAX);
add_filter('automatic_updater_disabled','__return_false',PHP_INT_MAX);
add_filter('automatic_updates_is_vcs_checkout','__return_false',PHP_INT_MAX);
add_filter('auto_update_plugin',static fn($update,$item)=>($item->plugin??'')==='dsql-automatic-probe/plugin.php',PHP_INT_MAX,2);
""".replace('__WPURL__',wpurl))
started_redis=False;wpserver=None;serverlog=None;verified=False
try:
 running=subprocess.check_output(['docker','inspect','-f','{{.State.Running}}','dsql-full-demo-redis'],text=True).strip()=='true'
 if not running:subprocess.run(['docker','start','dsql-full-demo-redis'],check=True,stdout=subprocess.DEVNULL);started_redis=True
 serverlog=(out/'wordpress-server.log').open('w');wpserver=subprocess.Popen(['php','-d','error_reporting=8191','-S','127.0.0.1:'+str(wpport),'-t',str(wp)],cwd=root,env=env,stdout=serverlog,stderr=subprocess.STDOUT)
 for _ in range(100):
  try:
   with socket.create_connection(('127.0.0.1',wpport),timeout=.1):break
  except OSError:time.sleep(.1)
 else:raise RuntimeError('Loopback WordPress did not start')
 # Verify the real configured target before invoking the plugin lifecycle.
 inspected=json.loads(subprocess.check_output(['php','-d','error_reporting=8191','upgrade/inspect-site.php',str(wp)],cwd=root,env=env,text=True));assert inspected['endpoint']==settings['endpoint'] and inspected['prefix']=='wp_';verified=True
 for version in ['1.0.0','1.1.0']:
  source=(root/'tests/automatic/fixtures/plugin.php.template').read_text().replace('__VERSION__',version).replace('__COLUMNS__',"flag tinyint(1) NOT NULL DEFAULT 1," if version=='1.1.0' else '').replace('__INDEXES__',",\n        KEY label_key (label(20))" if version=='1.1.0' else '')
  source='\n'.join(line for line in source.splitlines() if line.strip())+'\n'
  with zipfile.ZipFile(out/('probe-'+version+'.zip'),'w') as z:z.writestr('dsql-automatic-probe/plugin.php',source)
 command('plugin','install',str(out/'probe-1.0.0.zip'),'--activate');print('PASS ordinary plugin install and activation',flush=True)
 command('eval',"global $wpdb;$wpdb->query('DELETE FROM '.$wpdb->prefix.'automatic_plugin_probe');$wpdb->insert($wpdb->prefix.'automatic_plugin_probe',['label'=>'preserved']);if($wpdb->dsql_error_count)throw new RuntimeException('Write failed');")
 # Feed the real WordPress background updater one loopback package, without updating other plugins.
 code="add_filter('wp_doing_cron','__return_true');require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';$item=(object)['id'=>'dsql-automatic-probe','slug'=>'dsql-automatic-probe','plugin'=>'dsql-automatic-probe/plugin.php','new_version'=>'1.1.0','package'=>"+json.dumps(url)+",'url'=>'http://127.0.0.1','tested'=>$GLOBALS['wp_version'],'requires_php'=>'8.2'];add_filter('pre_site_transient_update_plugins',static fn()=>(object)['last_checked'=>time(),'checked'=>['dsql-automatic-probe/plugin.php'=>'1.0.0'],'response'=>['dsql-automatic-probe/plugin.php'=>$item]],PHP_INT_MAX);$updater=new WP_Automatic_Updater();if(!$updater->should_update('plugin',$item,WP_PLUGIN_DIR))throw new RuntimeException(json_encode(['disabled'=>$updater->is_disabled(),'filesystem'=>(new Automatic_Upgrader_Skin())->request_filesystem_credentials(false,WP_PLUGIN_DIR,false),'vcs'=>$updater->is_vcs_checkout(WP_PLUGIN_DIR),'optin'=>apply_filters('auto_update_plugin',false,$item)]));$result=$updater->update('plugin',$item);if(is_wp_error($result))throw new RuntimeException('Background update failed: '.implode(',',$result->get_error_codes()));if(!$result)throw new RuntimeException('Background update did not run: '.json_encode([$result,(new ReflectionProperty(WP_Automatic_Updater::class,'update_results'))->getValue($updater)]));"
 command('eval',code);print('PASS WordPress background plugin updater',flush=True)
 result=command('eval',"global $wpdb;$rows=$wpdb->get_results('SELECT label,flag FROM '.$wpdb->prefix.'automatic_plugin_probe',ARRAY_A);if(get_option('dsql_automatic_probe_version')!=='1.1.0'||$rows!==[['label'=>'preserved','flag'=>'1']]||$wpdb->dsql_error_count)throw new RuntimeException('Updated schema/data mismatch: '.json_encode([get_option('dsql_automatic_probe_version'),$rows,$wpdb->dsql_error_count]));require_once ABSPATH.'wp-admin/includes/upgrade.php';if(dbDelta(wp_get_db_schema(),false))throw new RuntimeException('Core schema changed unexpectedly');$users=get_users(['role'=>'administrator','fields'=>'ID','number'=>1]);wp_set_current_user((int)$users[0]);echo json_encode(['native'=>$wpdb->get_driver() instanceof WPDSQL\\Engine\\NativeDriver,'install_plugins'=>current_user_can('install_plugins'),'update_plugins'=>current_user_can('update_plugins'),'errors'=>$wpdb->dsql_error_count]);")
 report=json.loads(result);assert report=={'native':True,'install_plugins':True,'update_plugins':True,'errors':0};(out/'report.json').write_text(json.dumps(report,indent=2));print('PASS dbDelta, preserved rows/defaults and administrator update capabilities',flush=True)
finally:
 if verified:
  for args in [('plugin','deactivate','dsql-automatic-probe'),('plugin','delete','dsql-automatic-probe'),('option','delete','dsql_automatic_probe_version')]:
   try:command(*args)
   except Exception:pass
  if not (state/'pending.json').exists():
   command('eval',"$p=WPDSQL\\Engine\\AwsConnection::connect(new WPDSQL\\Engine\\Config(host:DB_HOST,user:'wp_upgrader',region:DSQL_REGION,profile:DSQL_PROFILE,schema:'wp_live'));$p->exec('DROP TABLE IF EXISTS wp_live.wp_automatic_plugin_probe');$p->exec(\"DELETE FROM wp_live.__wp_dsql_schema WHERE table_name='wp_automatic_plugin_probe'\");")
 mu.unlink(missing_ok=True);server.shutdown();server.server_close()
 if wpserver is not None:wpserver.terminate();wpserver.wait(timeout=15)
 if serverlog is not None:serverlog.close()
 if started_redis:subprocess.run(['docker','stop','dsql-full-demo-redis'],stdout=subprocess.DEVNULL)
