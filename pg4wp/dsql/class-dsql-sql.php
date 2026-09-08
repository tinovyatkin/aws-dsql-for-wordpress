<?php
/** WordPress-parser SQL compilation with best-effort, value-free translation caching. */
require_once __DIR__.'/class-dsql-value-codec.php';
use WPDSQL\MySQL\Translation\Shape;
use WPDSQL\MySQL\Translation\PlanCache;
use WPDSQL\MySQL\Translation\Compiler;
use WPDSQL\MySQL\Translation\Renderer;

final class DSQL_SQL {
    private PlanCache $cache;
    private Renderer $renderer;
    private ?array $foundRows=null;
    private array $prepared=[];
    private int $preparedBytes=0;
    private function shapeSize(Shape $shape):int {return strlen($shape->sql)*3+count($shape->slots)*512;}
    private int $compilations=0;
    private int $preparedHits=0;
    private string $mode='';
    private ?Shape $currentShape=null;
    public function __construct(private PDO $pdo,private bool $valueCodec=false,private string $schema='public',string $sqlMode='',private string $cacheScope='local',private ?string $cacheDirectory=null,private bool $cacheEnabled=true,private string $tablePrefix='wp_') {
        $this->mode=$sqlMode;
        $this->initCache();
        $this->renderer=new Renderer($pdo,$schema,$valueCodec);
    }
    private function initCache():void {
        $schema=$this->schema;$valueCodec=$this->valueCodec;
        $scope=$this->cacheScope.'|'.$schema.'|'.($valueCodec?'codec':'plain').'|'.$this->mode;
        $directory=$this->cacheDirectory;
        $this->cache=new PlanCache($scope,$directory,256,86400,$this->cacheEnabled);
    }
    /** Refresh live catalog information while retaining value-free compiled plans. */
    public function refreshSchemaMetadata():void {
        $this->renderer=new Renderer($this->pdo,$this->schema,$this->valueCodec);
    }
    public function reconnect(PDO $pdo):void {$this->pdo=$pdo;$this->refreshSchemaMetadata();}
    public function sqlMode():string {return $this->mode;}
    public function setSqlModeStatement(string $sql):void {
        $shape=$this->shape($sql);
        if(count($shape->slots)!==1||$shape->slots[0]['kind']!=='string')throw new RuntimeException('SET sql_mode requires a literal mode list');
        $key=hash('sha256','sql-mode|'.$shape->key);
        if($this->cache->get($key)===null){\WPDSQL\MySQL\SqlParser::parse($shape->template,sqlMode:$this->mode);$this->cache->put($key,['kind'=>'sql_mode']);}
        $modes=array_values(array_filter(array_map('trim',explode(',',strtoupper($shape->slots[0]['value'])))));
        if(array_diff($modes,['ANSI_QUOTES','NO_BACKSLASH_ESCAPES','IGNORE_SPACE','PIPES_AS_CONCAT','HIGH_NOT_PRECEDENCE']))throw new RuntimeException('Unsupported SQL execution mode');
        sort($modes);$this->mode=implode(',',$modes);$this->prepared=[];$this->preparedBytes=0;$this->currentShape=null;$this->initCache();
    }
    private function shape(string $sql):Shape {return new Shape($sql,$this->mode);}
    public function operation(string $sql):string {
        $key=hash('sha256',$sql);$this->currentShape=$this->prepared[$key]??$this->shape($sql);return $this->currentShape->operation;
    }
    public function rememberPrepared(string $sql):void {
        if(strlen($sql)>65536)return;
        try {$shape=$this->shape($sql);}catch(Throwable $e){return;}
        $key=hash('sha256',$sql);if(isset($this->prepared[$key]))$this->preparedBytes-=$this->shapeSize($this->prepared[$key]);
        $this->prepared[$key]=$shape;$this->preparedBytes+=$this->shapeSize($shape);
        while(count($this->prepared)>64||$this->preparedBytes>1048576){$key=array_key_first($this->prepared);$this->preparedBytes-=$this->shapeSize($this->prepared[$key]);unset($this->prepared[$key]);}
    }
    public function stats():array {return $this->cache->stats+['compilations'=>$this->compilations,'prepared_hits'=>$this->preparedHits,'persistent_cache'=>$this->cache->persistenceEnabled()];}
    public function quote_mysql_literal(string $literal):string {
        $shape=$this->shape($literal);if(count($shape->slots)!==1||$shape->slots[0]['kind']!=='string'||trim($literal)!==$shape->slots[0]['raw'])throw new RuntimeException('Expected one SQL string literal');
        $value=$shape->slots[0]['value'];if(str_contains($value,"\0"))throw new RuntimeException('NUL in metadata pattern');
        return $this->pdo->quote($value);
    }
    /** @return list<array{sql:string,params:array}> */
    public function translate(string $mysql):array {
        $key=hash('sha256',$mysql);
        if(isset($this->prepared[$key])){$shape=$this->prepared[$key];$this->preparedHits++;}else $shape=$this->currentShape?->sql===$mysql?$this->currentShape:$this->shape($mysql);
        $plan=$this->cache->get($shape->key);
        if($plan===null){$this->compilations++;$plan=(new Compiler($shape))->compile();if($plan['kind']!=='ddl')$this->cache->put($shape->key,$plan);}
        if($plan['kind']==='ddl')return $this->ddl($mysql);
        if($plan['kind']==='found_rows'){if($this->foundRows===null)throw new RuntimeException('FOUND_ROWS called without SQL_CALC_FOUND_ROWS');return [$this->foundRows];}
        $statements=$this->renderer->statements($plan['body'],$shape);
        if($plan['count']!==null){$count=$this->renderer->render($plan['count'],$shape);$count['sql']='SELECT COUNT(*) FROM ('.$count['sql'].') AS dsql_found_rows';$this->foundRows=$count;}
        return $statements;
    }
    private function ddl(string $mysql):array {
        if((new DSQL_Schema_Catalog($this->pdo))->managed())throw new RuntimeException('Restored schema changes require the controlled upgrade runner');
        require_once dirname(__DIR__,2).'/upgrade/Engine.php';
        $change=(new WPDSQLUpgrade\Schema($mysql))->parse();
        if($change['kind']==='create') {
            if($change['if_exists']){$exists=$this->pdo->prepare('SELECT 1 FROM pg_tables WHERE schemaname=? AND tablename=?');$exists->execute([$this->schema,$change['table']]);if($exists->fetchColumn())return [];}
            $table=WPDSQLUpgrade\Schema::apply($change,null,['table_prefix'=>$this->tablePrefix,'allow_destructive'=>false,'omit_fulltext_indexes'=>[]]);
            $table['target_schema']=$this->schema;$table['value_codec']=$this->valueCodec;
            $sql=WPDSQLMigration\Plan::ddl($table,$this->pdo);
            if($change['if_exists']??false)$sql[0]=str_replace('CREATE TABLE ','CREATE TABLE IF NOT EXISTS ',$sql[0]);
        }elseif($change['kind']==='drop') {
            $sql=['DROP TABLE '.(($change['if_exists']??false)?'IF EXISTS ':'').Renderer::qi($change['table'])];
        }else throw new RuntimeException('Schema alteration requires the controlled upgrade runner');
        return array_map(fn($q)=>['sql'=>$q,'params'=>[]],$sql);
    }
}
