<?php
/** Logical MySQL backup / Aurora DSQL restore. SPDX-License-Identifier: GPL-2.0-or-later */
namespace WPDSQLMigration;

final class Backup {
    public const FORMAT = 'wordpress-dsql-logical-backup-v1';
    public static function qi(string $name, string $quote = '"'): string {
        if ($name === '' || str_contains($name, "\0")) { throw new \RuntimeException('Invalid identifier'); }
        return $quote . str_replace($quote, $quote.$quote, $name) . $quote;
    }
    public static function json($data): string { return json_encode($data, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES); }
    public static function digest(array $hashes): string { sort($hashes, SORT_STRING); return hash('sha256', implode("\n", $hashes)); }
    public static function row(array $values): string {
        return self::json(array_map(static fn($v) => $v === null ? null : base64_encode((string)$v), $values));
    }
    private static function mysqlOption(string $name): int {
        return defined('Pdo\\Mysql::'.$name) ? constant('Pdo\\Mysql::'.$name) : constant('PDO::MYSQL_'.$name);
    }
    public static function source(array $config): \PDO {
        $options=[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION, \PDO::ATTR_STRINGIFY_FETCHES=>true, \PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC, self::mysqlOption('ATTR_USE_BUFFERED_QUERY')=>false];
        if (!preg_match('/(?:^|[:;])host=(?:127\.0\.0\.1|localhost)(?:;|$)/',$config['dsn'])) {
            if (empty($config['ssl_ca']) || !is_readable($config['ssl_ca'])) { throw new \RuntimeException('Remote MySQL export requires a readable ssl_ca trust bundle'); }
            $options[self::mysqlOption('ATTR_SSL_CA')]=$config['ssl_ca'];
            $options[self::mysqlOption('ATTR_SSL_VERIFY_SERVER_CERT')]=true;
        }
        $p = new \PDO($config['dsn'], $config['user'], $config['password'], $options);
        if ($p->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') { throw new \RuntimeException('MySQL source required'); }
        $p->exec("SET SESSION time_zone='+00:00'");
        $p->exec('SET NAMES utf8mb4');
        return $p;
    }
    /** Consistent source snapshot; DDL must not run concurrently. */
    public static function export(\PDO $p, string $directory, string $classification = 'synthetic'): array {
        if (!in_array($classification, ['synthetic','production'], true)) { throw new \RuntimeException('Explicit backup classification required'); }
        if (file_exists($directory)) { throw new \RuntimeException('Backup destination already exists'); }
        if (!mkdir($directory, 0700, true)) { throw new \RuntimeException('Cannot create private backup directory'); }
        $manifest = ['format'=>self::FORMAT,'classification'=>$classification,'created_at'=>gmdate('c'),'source_engine'=>$p->getAttribute(\PDO::ATTR_SERVER_VERSION),'tables'=>[]];
        $db = $p->query('SELECT DATABASE()')->fetchColumn();
        $manifest['source_database']=$db;
        foreach (['TRIGGERS'=>'TRIGGER_SCHEMA','ROUTINES'=>'ROUTINE_SCHEMA','EVENTS'=>'EVENT_SCHEMA'] as $collection=>$field) {
            $s=$p->prepare("SELECT COUNT(*) FROM information_schema.$collection WHERE $field=?");$s->execute([$db]);
            $count=(int)$s->fetchColumn();$s->closeCursor();
            if ($count) { throw new \RuntimeException("Source has $collection; export refuses to omit them"); }
        }
        $s=$p->prepare('SELECT TABLE_NAME, ENGINE, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME');$s->execute([$db]);$tables=$s->fetchAll();
        foreach ($tables as $t) {
            if ($t['ENGINE'] !== 'InnoDB' || $t['TABLE_TYPE'] !== 'BASE TABLE') { throw new \RuntimeException('Consistent export requires InnoDB base tables: '.$t['TABLE_NAME']); }
        }
        $p->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $p->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        try {
            foreach ($tables as $index=>$t) {
                $name=$t['TABLE_NAME'];$quoted=self::qi($name, '`');
                $ddl=array_values($p->query("SHOW CREATE TABLE $quoted")->fetch())[1];
                $columns=$p->query("SHOW FULL COLUMNS FROM $quoted")->fetchAll();
                $indexes=$p->query("SHOW INDEX FROM $quoted")->fetchAll();
                $file=sprintf('table-%04d.jsonl.gz',$index);
                $handle=gzopen($directory.'/'.$file,'wb6');
                if (!$handle) { throw new \RuntimeException('Cannot open table backup'); }
                $hashes=[];$count=0;$maximum=0;
                $query=$p->query('SELECT '.implode(',',array_map(static fn($c)=>self::qi($c['Field'],'`'),$columns))." FROM $quoted");
                while ($row=$query->fetch(\PDO::FETCH_NUM)) {
                    $line=self::row($row);$hashes[]=hash('sha256',$line);$maximum=max($maximum,strlen($line));$count++;
                    if (gzwrite($handle,$line."\n") === false) { throw new \RuntimeException('Cannot write table backup'); }
                }
                $query->closeCursor();
                gzclose($handle);chmod($directory.'/'.$file,0600);
                $after=array_values($p->query("SHOW CREATE TABLE $quoted")->fetch())[1];
                if ($ddl !== $after) { throw new \RuntimeException('Source DDL changed during export'); }
                $manifest['tables'][]=['name'=>$name,'mysql_ddl'=>$ddl,'columns'=>$columns,'indexes'=>$indexes,'rows'=>$count,'file'=>$file,'sha256'=>hash_file('sha256',$directory.'/'.$file),'rows_sha256'=>self::digest($hashes),'maximum_json_row_bytes'=>$maximum];
            }
            $p->commit();
        } catch (\Throwable $e) { if ($p->inTransaction()) { $p->rollBack(); } throw $e; }
        $bytes=self::json($manifest)."\n";
        file_put_contents($directory.'/manifest.json',$bytes,LOCK_EX);chmod($directory.'/manifest.json',0600);
        file_put_contents($directory.'/manifest.sha256',hash('sha256',$bytes)."\n",LOCK_EX);chmod($directory.'/manifest.sha256',0600);
        return $manifest;
    }
    /** Schema and aggregate limits only; never exports source row values. */
    public static function inspectSource(\PDO $p,array $policy=[]): array {
        if(!class_exists(Policy::class))require_once __DIR__.'/Policy.php';
        $policy=$policy?:Policy::load(null);$adaptations=[];
        $db=$p->query('SELECT DATABASE()')->fetchColumn();
        $s=$p->prepare('SELECT TABLE_NAME,ENGINE,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME');$s->execute([$db]);$tables=$s->fetchAll();$issues=[];
        foreach ($tables as $t) {
            $name=$t['TABLE_NAME'];$q=self::qi($name,chr(96));
            if ($t['ENGINE']!=='InnoDB'||$t['TABLE_TYPE']!=='BASE TABLE') {$issues[]=['table'=>$name,'reason'=>'Not an InnoDB base table'];continue;}
            $columns=$p->query("SHOW FULL COLUMNS FROM $q")->fetchAll();
            foreach ($columns as $c) {try {Plan::type($c);}catch(\Throwable $e){$issues[]=['table'=>$name,'column'=>$c['Field'],'reason'=>$e->getMessage()];}}
            $ddl=array_values($p->query("SHOW CREATE TABLE $q")->fetch())[1];
            if (preg_match('/\bFOREIGN\s+KEY\b|\bCHECK\s*\(|\bPARTITION\s+BY\b/i',$ddl)) $issues[]=['table'=>$name,'reason'=>'Explicit constraint/partition translation is required'];
            $indexes=$p->query("SHOW INDEX FROM $q")->fetchAll();
            $effective=[];
            try {$mapped=Policy::table(['name'=>$name,'indexes'=>$indexes],$policy);$effective=Plan::indexes($mapped);
                if($mapped['archived'])$adaptations[]=['table'=>$name,'action'=>'read-only archive'];
                foreach($mapped['omitted_indexes'] as $index)$adaptations[]=['table'=>$name,'action'=>'omit optional FULLTEXT index','index'=>$index];
            }catch(\Throwable $e){$issues[]=['table'=>$name,'reason'=>$e->getMessage()];}
            $indexed=[];foreach($effective as $g)foreach($g as $part)$indexed[]=$part['Column_name'];
            $lengths=[];$nul=[];$unsafeNul=[];
            foreach ($columns as $c) {
                $field=self::qi($c['Field'],chr(96));$lengths[]="COALESCE(OCTET_LENGTH($field),0)";
                if (preg_match('/char|text|enum|json/i',$c['Type'])) {
                    $condition="LOCATE(0x00,CAST($field AS BINARY))>0";$nul[]=$condition;
                    if(!preg_match('/^(tinytext|mediumtext|longtext|text)$/i',$c['Type'])||in_array($c['Field'],$indexed,true))$unsafeNul[]=$condition;
                }
            }
            $sizes=$p->query('SELECT MAX(GREATEST(0,'.implode(',',$lengths).')) AS max_column, MAX('.implode('+',$lengths).') AS max_row, SUM(CASE WHEN '.($nul?implode(' OR ',$nul):'FALSE').' THEN 1 ELSE 0 END) AS nul_rows, SUM(CASE WHEN '.($unsafeNul?implode(' OR ',$unsafeNul):'FALSE').' THEN 1 ELSE 0 END) AS unsafe_nul_rows FROM '.$q)->fetch();
            if ((int)$sizes['max_column']>1048576) $issues[]=['table'=>$name,'reason'=>'Column exceeds DSQL 1 MiB limit'];
            if ((int)$sizes['max_row']>2097152) $issues[]=['table'=>$name,'reason'=>'Row exceeds DSQL 2 MiB limit'];
            if ((int)$sizes['nul_rows']) {
                if(!$policy['value_codec']||(int)$sizes['unsafe_nul_rows'])$issues[]=['table'=>$name,'reason'=>'NUL value requires an enabled codec on unindexed TEXT','rows'=>(int)$sizes['nul_rows']];
                else $adaptations[]=['table'=>$name,'action'=>'reversible NUL text envelope','rows'=>(int)$sizes['nul_rows']];
            }
        }
        foreach (['TRIGGERS'=>'TRIGGER_SCHEMA','ROUTINES'=>'ROUTINE_SCHEMA','EVENTS'=>'EVENT_SCHEMA'] as $collection=>$field) {
            $s=$p->prepare("SELECT COUNT(*) FROM information_schema.$collection WHERE $field=?");$s->execute([$db]);
            if ((int)$s->fetchColumn()) $issues[]=['reason'=>'Source has '.$collection];
            $s->closeCursor();
        }
        return ['source_engine'=>$p->getAttribute(\PDO::ATTR_SERVER_VERSION),'tables'=>count($tables),'preflight_passed'=>!$issues,'issues'=>$issues,'adaptations'=>$adaptations,'policy_sha256'=>hash('sha256',self::json($policy)),'data_scanned'=>'aggregate sizes and NUL counts only','cutover_ready'=>false];
    }
    public static function load(string $directory): array {
        if (!is_file($directory.'/manifest.json') || !is_file($directory.'/manifest.sha256')) { throw new \RuntimeException('Complete backup manifest and checksum required'); }
        $bytes=file_get_contents($directory.'/manifest.json');
        if (!hash_equals(trim(file_get_contents($directory.'/manifest.sha256')),hash('sha256',$bytes))) { throw new \RuntimeException('Manifest checksum mismatch'); }
        $m=json_decode($bytes,true,512,JSON_THROW_ON_ERROR);
        if (($m['format']??'') !== self::FORMAT || !in_array($m['classification']??'', ['synthetic','production'],true)) { throw new \RuntimeException('Unsupported backup format'); }
        $seen=[];
        foreach ($m['tables'] as $t) {
            if (isset($seen[$t['name']]) || !preg_match('/^table-[0-9]+\.jsonl\.gz$/D',$t['file'])) { throw new \RuntimeException('Invalid table manifest'); }
            $seen[$t['name']]=true;
            if (is_link($directory.'/'.$t['file']) || !hash_equals($t['sha256'],hash_file('sha256',$directory.'/'.$t['file']))) { throw new \RuntimeException('Table checksum mismatch: '.$t['name']); }
        }
        return $m;
    }
    /** @return \Generator<array> Decoded values; never deserialize application data. */
    public static function rows(string $directory, array $table): \Generator {
        $f=gzopen($directory.'/'.$table['file'],'rb');
        try {
            while (($line=gzgets($f)) !== false) {
                $row=json_decode($line,true,512,JSON_THROW_ON_ERROR);
                if (count($row)!==count($table['columns'])) { throw new \RuntimeException('Column count mismatch'); }
                yield array_map(static function($v) {
                    if ($v===null) { return null; }
                    if (!is_string($v) || ($raw=base64_decode($v,true))===false) { throw new \RuntimeException('Invalid encoded cell'); }
                    return $raw;
                },$row);
            }
            if (!gzeof($f)) { throw new \RuntimeException('Incomplete compressed table'); }
        } finally { gzclose($f); }
    }
}
