<?php
namespace WPDSQLUpgrade;
require_once __DIR__.'/Session.php';
final class Context {
    public static ?Session $session=null;
    public static function load(string $wordpress,string $endpoint): ?Session {
        $path=getenv('DSQL_UPGRADE_SESSION');
        if(!$path)return null;
        if(PHP_SAPI!=='cli'||!defined('WP_CLI')||!WP_CLI)throw new \RuntimeException('Schema upgrades are CLI-only');
        $s=new Session($path);$s->assertActive();
        if(realpath($wordpress)!==$s->data['wordpress']||$endpoint!==$s->data['target']['endpoint'])throw new \RuntimeException('Upgrade target differs from WordPress configuration');
        if($s->operation())throw new \RuntimeException('Recover the pending schema operation before loading WordPress');
        return self::$session=$s;
    }
    public static function blockUnlessOwner(string $wordpress): void {
        $guard=Session::guard($wordpress);
        if(!is_file($guard))return;
        $s=self::$session;
        if($s&&$s->data['id']===trim(file_get_contents($guard)))return;
        if(PHP_SAPI==='cli'){fwrite(STDERR,"A controlled DSQL upgrade is in progress.\n");exit(75);}
        http_response_code(503);header('Cache-Control: no-store, private');header('Retry-After: 120');exit('WordPress is briefly undergoing a database upgrade.');
    }
}
