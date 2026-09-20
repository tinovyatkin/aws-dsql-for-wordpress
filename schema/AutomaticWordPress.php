<?php
namespace WPDSQL\Schema;

/** Small WordPress-facing surface; database upgrade work stays in the coordinator. */
final class AutomaticWordPress {
    public static function register(): void {
        add_action('admin_notices',[self::class,'notice']);
        add_filter('http_request_args',[self::class,'loopbackTimeout'],10,2);
        add_action('requests-curl.before_send',[self::class,'beforeHttp'],PHP_INT_MAX,0);
        add_action('requests-fsockopen.before_send',[self::class,'beforeHttp'],PHP_INT_MAX,0);
    }
    public static function loopbackTimeout(array $args,string $url): array {
        $parts=wp_parse_url($url);$home=wp_parse_url(home_url('/'));
        parse_str($parts['query']??'',$query);
        if(isset($query['wp_scrape_key'],$query['wp_scrape_nonce'])
            &&($parts['host']??null)===($home['host']??null)
            &&($parts['port']??null)===($home['port']??null)) {
            // Core's 50-second fatal-error probe can include DSQL index jobs.
            $args['timeout']=max(120,(float)($args['timeout']??0));
        }
        return $args;
    }
    public static function beforeHttp(): void {
        if( \WPDSQL\Engine\NativeDriver::suspendSchemaForHttp()) {
            global $wpdb;
            if($wpdb instanceof \DSQL_WPDB)$wpdb->clear_dsql_column_metadata();
        }
    }
    public static function notice(): void {
        if(!current_user_can('update_plugins')||!defined('DSQL_SCHEMA_STATE_DIRECTORY'))return;
        $path=rtrim(DSQL_SCHEMA_STATE_DIRECTORY,'/').'/last-error.json';
        if(!is_readable($path))return;
        $error=json_decode(file_get_contents($path),true);
        $when=strtotime($error['timestamp']??'');
        if(!$when||time()-$when>86400)return;
        $message='A recent plugin or WordPress database change could not be completed automatically. '.($error['reason']??'A controlled database migration is required.');
        $message.=' Reference: '.substr($error['fingerprint']??'',0,12).'.';
        wp_admin_notice(esc_html($message),['type'=>'warning','dismissible'=>true]);
    }
}
