<?php
$root=dirname(__DIR__,2);require $root.'/.local/full-target/wp-load.php';require_once ABSPATH.'wp-admin/includes/plugin.php';require_once ABSPATH.'wp-admin/includes/upgrade.php';
if(wp_get_environment_type()!=='local')throw new RuntimeException('Local synthetic demo required');
$checks=[];
function demo_check(bool $ok,string $name):void{global $checks;$checks[]=['check'=>$name,'passed'=>$ok];if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
class DsqlFullDemoObject {private string $private='Unicode 🌍';protected string $protected="A\0B";}
try{
 demo_check($wpdb instanceof DSQL_WPDB,'DSQL driver loaded');
 demo_check($wpdb->get_var('SELECT current_user')===DB_USER && DB_USER!=='admin','Restricted runtime database role');
 $expected=json_decode(file_get_contents($root.'/.local/full-demo/production-schema.json'),true);
 $active=array_values(get_option('active_plugins'));$expectedActive=array_values($expected['active_plugins']);sort($active);sort($expectedActive);
 demo_check($active===$expectedActive,'All production-active plugins enabled');
 $installed=get_plugins();foreach($expected['plugins'] as $plugin)demo_check(($installed[$plugin['file']]['Version']??'')===$plugin['version'],'Plugin version: '.$plugin['file']);
 demo_check(get_option('stylesheet')==='olga','Olga theme enabled');
 demo_check(wp_using_ext_object_cache() && $GLOBALS['wp_object_cache']->redis_status(),'Isolated persistent Redis cache connected');
 $user=wp_authenticate('demo',trim(file_get_contents($root.'/.local/full-demo/admin-password')));
 demo_check($user instanceof WP_User,'Restored administrator authentication');wp_set_current_user($user->ID);
 demo_check(get_user_by('login','DEMO')?->ID===$user->ID,'Case-insensitive WordPress login lookup');
 demo_check(get_user_by('email',strtoupper($user->user_email))?->ID===$user->ID,'Case-insensitive WordPress email lookup');
 demo_check(get_option('dsql_full_demo_nul')==="binary\0payload 🌍",'Migrated NUL value recovered exactly');
 demo_check(get_option('dsql_full_demo_frame')==='~dsqlb64:v1:literal','Literal envelope prefix is collision-safe');
 $value=new DsqlFullDemoObject();update_option('dsql_full_object',$value,false);wp_cache_delete('dsql_full_object','options');
 demo_check(serialize(get_option('dsql_full_object'))===serialize($value),'New protected/private PHP object round trip');
 $frame=DSQL_Value_Codec::encode("inner\0bytes");update_option('dsql_full_literal_frame',$frame,false);wp_cache_delete('dsql_full_literal_frame','options');
 demo_check(get_option('dsql_full_literal_frame')===$frame,'Nested envelope-looking literal decoded exactly once');
 $changes=dbDelta(wp_get_db_schema(),false);demo_check(!$changes,'No false core schema upgrades');
 $id=wp_insert_post(['post_title'=>'Full DSQL demo — new story','post_content'=>'<!-- wp:paragraph --><p>A synthetic full-site migration check.</p><!-- /wp:paragraph -->','post_status'=>'draft'],true);
 demo_check(is_int($id)&&$id>500000,'MySQL AUTO_INCREMENT preserved beyond deleted IDs');
 acf_add_local_field_group(['key'=>'group_dsql_demo','title'=>'DSQL demo','fields'=>[['key'=>'field_dsql_demo_note','name'=>'dsql_demo_note','type'=>'text']],'location'=>[[['param'=>'post_type','operator'=>'==','value'=>'post']]]]);
 update_field('field_dsql_demo_note','ACF on DSQL 🌍',$id);demo_check(get_field('field_dsql_demo_note',$id)==='ACF on DSQL 🌍','ACF field write/read');
 demo_check(wp_update_post(['ID'=>$id,'post_status'=>'publish'],true)===$id,'Publish with site-owned hooks');
 $request=new WP_REST_Request('GET','/wp/v2/posts/'.$id);$response=rest_get_server()->dispatch($request);demo_check($response->get_status()===200,'REST post read with all plugins');
 $logSchema=new WordPress\AI\Logging\AI_Request_Log_Schema();$logs=new WordPress\AI\Logging\AI_Request_Log_Repository($logSchema);
 demo_check(!$logSchema->has_fulltext_index(),'AI plugin detects full-text unavailable');
 $logId=$logs->insert(['type'=>'text','operation'=>'demo.compatibility.needle','provider'=>'synthetic','model'=>'offline','status'=>'success','duration_ms'=>12,'tokens_input'=>10,'tokens_output'=>20,'context'=>['input_preview'=>'Needle search fixture','output_preview'=>'Synthetic response']]);
 demo_check(is_string($logId),'AI request log insertion');
 $found=$logs->query(['search'=>'needle']);demo_check($found['total']>=1,'AI log search uses existing LIKE fallback');
 $summary=$logs->get_summary('day',true);demo_check($summary['total_requests']>=1,'AI log summary and aggregate queries');
 $filters=$logs->get_filter_options(true);demo_check(in_array('synthetic',$filters['providers'],true),'AI log filter queries');
 demo_check(count($wpdb->dsql_errors)===0,'No database errors in full application checks');
 update_option('dsql_full_external_post_id',$id,false);
}catch(Throwable $e){$checks[]=['failure'=>$e->getMessage()];echo 'FAIL '.$e->getMessage()."\n";}
file_put_contents($root.'/.local/full-demo/application-report.json',json_encode(['checks'=>$checks,'errors'=>$wpdb->dsql_errors,'post_id'=>$id??null,'schema_changes'=>$changes??[]],JSON_PRETTY_PRINT));
exit(isset($checks[array_key_last($checks)]['failure'])?1:0);
