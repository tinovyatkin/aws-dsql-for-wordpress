<?php
/** These helpers only use the existing synthetic lab, never a WordPress runtime. */
$root=dirname(__DIR__,2);
function lab(): array {
    global $root;
    $s=json_decode(file_get_contents($root.'/.local/settings.json'),true,flags:JSON_THROW_ON_ERROR);
    $c=json_decode(file_get_contents($root.'/.local/cluster.json'),true,flags:JSON_THROW_ON_ERROR);
    if (($c['tags']['Purpose']??'')!=='synthetic-wordpress-compatibility'||$s['endpoint']!==$c['identifier'].'.dsql.'.$s['region'].'.on.aws') throw new RuntimeException('Synthetic lab required');
    return $s;
}
function fixture(): array {
    global $root;
    return json_decode(file_get_contents($root.'/.local/native-fixture.json'),true,flags:JSON_THROW_ON_ERROR);
}
function native_client(): DsqlNativePrototype {
    $s=lab(); $f=fixture();
    return new DsqlNativePrototype($s['endpoint'],$s['region'],$s['profile'],'admin','public',$f['prefix'],$f['revision']);
}
function php_client(): WPDSQL\Engine\Driver {
    global $root;require_once $root.'/vendor/autoload.php';$s=lab();$f=fixture();
    return new WPDSQL\Engine\Driver(new WPDSQL\Engine\Config(host:$s['endpoint'],region:$s['region'],profile:$s['profile'],tablePrefix:$f['prefix'],cacheDirectory:$root.'/.local/native-php-cache'));
}
function fixture_queries(): array {
    $t=fixture()['posts'];$m=fixture()['meta'];
    return [
        "SELECT ID, post_title FROM `$t` WHERE post_status='publish' AND ID IN (1,2,3,4,5) ORDER BY ID DESC LIMIT 0,10",
        "SELECT p.ID, p.post_title, m.meta_value FROM `$t` p INNER JOIN `$m` m ON p.ID=m.post_id WHERE m.meta_key='rating' ORDER BY p.ID DESC LIMIT 20",
        "SELECT COUNT(*) AS total FROM `$t` WHERE post_status='publish'",
        "SELECT post_status, COUNT(*) AS total FROM `$t` GROUP BY post_status ORDER BY post_status",
        "SELECT ID, IFNULL(post_title,'untitled') AS title FROM `$t` WHERE ID BETWEEN 1 AND 10 ORDER BY ID",
        "SELECT ID FROM `$t` WHERE post_title LIKE 'Synthetic%' ORDER BY ID LIMIT 10",
    ];
}
