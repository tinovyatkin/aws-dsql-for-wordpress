<?php
namespace WPDSQLMigration;
require_once dirname(__DIR__).'/pg4wp/dsql/class-dsql-schema-catalog.php';
final class Restore {
    public const STATE='__wp_dsql_restore';
    public static function target(array $settings,string $classification='synthetic'): \PDO {
        if (!preg_match('/^([a-z0-9]{26})\.dsql\.([a-z0-9-]+)\.on\.aws$/D',$settings['endpoint']??'',$host) || $host[2]!==$settings['region']) { throw new \RuntimeException('Expected an Aurora DSQL public endpoint matching its region'); }
        $sdk=new \Aws\DSQL\DSQLClient(['version'=>'latest','region'=>$settings['region'],'profile'=>$settings['profile']??null]);
        $cluster=$sdk->getCluster(['identifier'=>$host[1]]);
        $purpose=$classification==='production'?'wordpress-dsql-production-migration':'synthetic-wordpress-migration';
        if (($cluster['tags']['Purpose']??'')!==$purpose) { throw new \RuntimeException('Target cluster purpose does not match backup classification'); }
        if ($cluster['status']!=='ACTIVE') { throw new \RuntimeException('Target cluster is not active'); }
        return \Aws\AuroraDsql\PdoPgsql\AuroraDsql::connect(new \Aws\AuroraDsql\PdoPgsql\DsqlConfig(host:$settings['endpoint'],user:$settings['user']??'admin',credentialsProvider:static fn()=>$sdk->getCredentials(),occMaxRetries:3),[\PDO::ATTR_STRINGIFY_FETCHES=>true]);
    }
    public static function run(\PDO $p,string $directory,array $manifest,string $expectedHost): array {
        $plan=Plan::inspect($directory,$manifest);
        if (!$plan['restore_supported']) { throw new \RuntimeException('Backup preflight failed; inspect the plan before restoring'); }
        if (!$expectedHost) { throw new \RuntimeException('An explicit expected destination is required'); }
        // Resolve all DDL before creating anything. Unsupported constraints fail here.
        $ddls=[];foreach ($manifest['tables'] as $t) { $ddls[$t['name']]=Plan::ddl($t,$p); }
        $existing=$p->query("SELECT tablename FROM pg_tables WHERE schemaname='public'")->fetchAll(\PDO::FETCH_COLUMN);
        if ($existing) { throw new \RuntimeException('Destination is not empty; restore never overwrites or merges existing tables'); }
        $id=hash_file('sha256',$directory.'/manifest.json');
        $p->exec('CREATE TABLE "'.self::STATE.'" (id text PRIMARY KEY, phase text NOT NULL, manifest_sha256 text NOT NULL, created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $s=$p->prepare('INSERT INTO "'.self::STATE.'" (id,phase,manifest_sha256) VALUES (?,?,?)');$s->execute(['restore','schema',$id]);
        $catalog=new \DSQL_Schema_Catalog($p);$catalog->create();$jobs=[];
        try {
            foreach ($ddls as $name=>$statements) {
                foreach ($statements as $sql) {
                    if (str_starts_with($sql,'CREATE TABLE')) { $p->exec($sql); }
                    else { $job=$p->query($sql)->fetchColumn();if ($job) $jobs[]=$job; }
                }
            }
            foreach ($jobs as $job) { $s=$p->prepare('CALL sys.wait_for_job(?)');$s->execute([$job]);if (!$s->fetchColumn()) throw new \RuntimeException('Index build failed'); }
            self::phase($p,'loading');
            foreach ($manifest['tables'] as $table) {
                self::loadTable($p,$directory,$table);
                foreach ($table['columns'] as $c) {
                    if (!str_contains($c['Extra']??'','auto_increment')) continue;
                    $max=$p->query('SELECT MAX('.Backup::qi($c['Field']).') FROM '.Backup::qi($table['name']))->fetchColumn();
                    // Restore original IDs first, then allocate beyond the greatest imported ID.
                    $s=$p->prepare('SELECT setval(pg_get_serial_sequence(?,?),?,?)');
                    $s->execute([$table['name'],$c['Field'],$max===null?'1':$max,$max===null?'false':'true']);
                }
                $catalog->put($table);
            }
            $report=self::verify($p,$directory,$manifest);
            if (!$report['verified']) { throw new \RuntimeException('Restored data failed verification'); }
            self::phase($p,'verified');
            return $report;
        } catch (\Throwable $e) { if ($p->inTransaction()) $p->rollBack(); self::phase($p,'failed'); throw $e; }
    }
    private static function phase(\PDO $p,string $phase): void { $s=$p->prepare('UPDATE "'.self::STATE.'" SET phase=? WHERE id=?');$s->execute([$phase,'restore']); }
    private static function loadTable(\PDO $p,string $directory,array $table): void {
        $batch=[];$bytes=0;$limit=min(250,max(1,intdiv(30000,count($table['columns']))));
        foreach (Backup::rows($directory,$table) as $row) {
            $size=strlen(Backup::row($row));
            if ($batch && (count($batch)>=$limit || $bytes+$size>1048576)) { self::insertBatch($p,$table,$batch);$batch=[];$bytes=0; }
            $batch[]=$row;$bytes+=$size;
        }
        if ($batch) self::insertBatch($p,$table,$batch);
    }
    private static function insertBatch(\PDO $p,array $table,array $rows): void {
        $params=[];$groups=[];
        foreach ($rows as $row) {
            $placeholders=[];
            foreach ($row as $i=>$value) {
                $c=$table['columns'][$i];$binary=Plan::type($c)==='bytea';
                $placeholders[]=$binary?"decode(?, 'base64')":'?';
                $params[]=$binary && $value!==null?base64_encode($value):Plan::convert($value,$c);
            }
            $groups[]='('.implode(',',$placeholders).')';
        }
        $sql='INSERT INTO '.Backup::qi($table['name']).' ('.implode(',',array_map(static fn($c)=>Backup::qi($c['Field']),$table['columns'])).') VALUES '.implode(',',$groups);
        $p->transaction(static function(\PDO $tx)use($sql,$params){$s=$tx->prepare($sql);$s->execute($params);});
    }
    public static function verify(\PDO $p,string $directory,array $manifest): array {
        $checks=[];
        foreach ($manifest['tables'] as $table) {
            $columns=[];
            foreach ($table['columns'] as $c) {
                $q=Backup::qi($c['Field']);$columns[]=Plan::type($c)==='bytea'?"encode($q,'base64') AS $q":$q;
            }
            $s=$p->query('SELECT '.implode(',',$columns).' FROM '.Backup::qi($table['name']));$hashes=[];$count=0;
            while ($row=$s->fetch(\PDO::FETCH_NUM)) {
                foreach ($row as $i=>&$v) {
                    if ($v===null) continue;
                    if (Plan::type($table['columns'][$i])==='bytea') { $v=base64_decode($v,true); }
                    else { $v=Plan::convert((string)$v,$table['columns'][$i],true); }
                }
                unset($v);$hashes[]=hash('sha256',Backup::row($row));$count++;
            }
            $digest=Backup::digest($hashes);
            $checks[]=['table'=>$table['name'],'source_rows'=>$table['rows'],'target_rows'=>$count,'rows_sha256'=>$digest,'matches'=>$count===$table['rows']&&hash_equals($table['rows_sha256'],$digest)];
        }
        return ['format'=>'wordpress-dsql-restore-verification-v1','manifest_sha256'=>hash_file('sha256',$directory.'/manifest.json'),'verified'=>!array_filter($checks,static fn($c)=>!$c['matches']),'tables'=>$checks,'verified_at'=>gmdate('c'),'cutover_performed'=>false];
    }
}
