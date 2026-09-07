<?php
/** Preserve source MySQL schema semantics for migrated tables. SPDX-License-Identifier: GPL-2.0-or-later */
final class DSQL_Schema_Catalog {
    public const TABLE = '__wp_dsql_schema';
    private PDO $pdo;
    private array $cache=[];
    public function __construct(PDO $pdo) { $this->pdo=$pdo; }
    public function create(): void {
        $this->pdo->exec('CREATE TABLE "'.self::TABLE.'" (table_name text PRIMARY KEY, metadata text NOT NULL, fingerprint text NOT NULL)');
    }
    public function fingerprint(string $table): string {
        $s=$this->pdo->prepare("SELECT column_name,data_type,is_nullable,column_default,character_maximum_length,numeric_precision,numeric_scale,datetime_precision,is_identity FROM information_schema.columns WHERE table_schema='public' AND table_name=? ORDER BY ordinal_position");$s->execute([$table]);$columns=$s->fetchAll(PDO::FETCH_ASSOC);
        $s=$this->pdo->prepare("SELECT indexname,indexdef FROM pg_indexes WHERE schemaname='public' AND tablename=? ORDER BY indexname");$s->execute([$table]);$indexes=$s->fetchAll(PDO::FETCH_ASSOC);
        $normalize=static function($rows) { return array_map(static fn($r)=>array_map(static fn($v)=>$v===null?null:(string)$v,$r),$rows); };
        $columns=$normalize($columns);$indexes=$normalize($indexes);
        return hash('sha256',json_encode([$columns,$indexes],JSON_THROW_ON_ERROR));
    }
    public function put(array $table): void {
        $metadata=['mysql_ddl'=>$table['mysql_ddl'],'columns'=>$table['columns'],'indexes'=>$table['indexes']];
        $s=$this->pdo->prepare('INSERT INTO "'.self::TABLE.'" (table_name,metadata,fingerprint) VALUES (?,?,?)');
        $s->execute([$table['name'],json_encode($metadata,JSON_THROW_ON_ERROR),$this->fingerprint($table['name'])]);
        unset($this->cache[$table['name']]);
    }
    public function get(string $table): ?array {
        if (array_key_exists($table,$this->cache)) { return $this->cache[$table]; }
        try {
            $s=$this->pdo->prepare('SELECT metadata,fingerprint FROM "'.self::TABLE.'" WHERE table_name=?');$s->execute([$table]);$r=$s->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            if ((string)$e->getCode()==='42P01') { return $this->cache[$table]=null; }
            throw $e;
        }
        if (!$r) { return $this->cache[$table]=null; }
        if (!hash_equals($r['fingerprint'],$this->fingerprint($table))) { throw new RuntimeException('DSQL schema differs from its restored metadata: '.$table); }
        return $this->cache[$table]=json_decode($r['metadata'],true,512,JSON_THROW_ON_ERROR);
    }
}
