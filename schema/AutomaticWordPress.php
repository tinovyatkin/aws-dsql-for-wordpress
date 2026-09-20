<?php
namespace WPDSQL\Schema;

/** Small WordPress-facing surface; database upgrade work stays in the coordinator. */
final class AutomaticWordPress {
    public static function register(): void {
        add_action('admin_notices',[self::class,'notice']);
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
