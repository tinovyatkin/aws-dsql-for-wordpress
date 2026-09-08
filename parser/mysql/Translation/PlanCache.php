<?php
namespace WPDSQL\MySQL\Translation;

/** Bounded, optional private JSON cache. Never stores bindings or PHP objects. */
final class PlanCache {
    public const VERSION='wordpress-lalr-plan-v1';
    private static ?string $build=null;
    public static function buildFingerprint():string {return self::$build??=hash('sha256',self::VERSION.hash_file('sha256',dirname(__DIR__).'/TreeAdapter.php').hash_file('sha256',dirname(__DIR__).'/Node.php').hash_file('sha256',dirname(__DIR__).'/Token.php').hash_file('sha256',dirname(__DIR__).'/SqlParser.php').hash_file('sha256',dirname(__DIR__).'/WordPress/source.json').hash_file('sha256',__DIR__.'/Compiler.php').hash_file('sha256',__DIR__.'/Renderer.php').hash_file('sha256',__DIR__.'/Shape.php'));}
    private array $memory=[];
    private int $bytes=0;
    private ?string $directory=null;
    public array $stats=['hits'=>0,'misses'=>0,'disk_hits'=>0,'writes'=>0,'errors'=>0];
    public function __construct(string $scope,?string $directory=null,private int $limit=256,private int $ttl=86400,private bool $enabled=true) {
        if(!$this->enabled||$directory==='')return;
        $base=$directory??sys_get_temp_dir().'/wp-dsql-plans-'.(function_exists('posix_geteuid')?posix_geteuid():getmyuid());
        try {
            if(!is_dir($base)&&!@mkdir($base,0700,true))return;
            if(is_link($base)||((fileperms($base)&0077)!==0)||!is_writable($base))return;
            $path=$base.'/'.hash('sha256',self::buildFingerprint().'|'.$scope);
            if(!is_dir($path)&&!@mkdir($path,0700))return;
            if(!is_link($path)&&(fileperms($path)&0077)===0)$this->directory=$path;
        }catch(\Throwable $e){$this->stats['errors']++;}
    }
    public function persistenceEnabled():bool {return $this->directory!==null;}
    public function get(string $key):?array {
        if(!$this->enabled){$this->stats['misses']++;return null;}
        if(isset($this->memory[$key])){$entry=$this->memory[$key];unset($this->memory[$key]);$this->memory[$key]=$entry;$this->stats['hits']++;return json_decode($entry['json'],true,512,JSON_THROW_ON_ERROR);}
        try { if($this->directory!==null) {
            $file=$this->directory.'/'.$key.'.json';
            if(is_file($file)&&!is_link($file)&&@filesize($file)<=131072&&@filemtime($file)>time()-$this->ttl) {
                $json=@file_get_contents($file);$data=is_string($json)?json_decode($json,true):null;
                if(is_array($data)&&($data['version']??null)===self::VERSION&&($data['key']??null)===$key&&is_array($data['plan']??null)&&is_string($data['sha256']??null)&&hash_equals($data['sha256'],hash('sha256',json_encode($data['plan'],JSON_THROW_ON_ERROR)))) {
                    $this->remember($key,$data['plan']);$this->stats['hits']++;$this->stats['disk_hits']++;return $data['plan'];
                }
            }
        }
        }catch(\Throwable $e){$this->stats['errors']++;}
        $this->stats['misses']++;return null;
    }
    private function remember(string $key,array $plan):void {
        $encoded=json_encode($plan,JSON_THROW_ON_ERROR);$size=strlen($encoded);if($size>131072)return;
        if(isset($this->memory[$key])){$this->bytes-=$this->memory[$key]['size'];unset($this->memory[$key]);}
        $this->memory[$key]=['json'=>$encoded,'size'=>$size];$this->bytes+=$size;
        while(count($this->memory)>$this->limit||$this->bytes>4194304){$k=array_key_first($this->memory);$this->bytes-=$this->memory[$k]['size'];unset($this->memory[$k]);}
    }
    public function put(string $key,array $plan):void {
        if(!$this->enabled)return;
        try{$this->remember($key,$plan);}catch(\Throwable $e){$this->stats['errors']++;return;}
        if($this->directory===null)return;
        try {
            $json=json_encode(['version'=>self::VERSION,'key'=>$key,'plan'=>$plan,'sha256'=>hash('sha256',json_encode($plan,JSON_THROW_ON_ERROR))],JSON_THROW_ON_ERROR);if(strlen($json)>131072)return;
            $file=$this->directory.'/'.$key.'.json';$tmp=tempnam($this->directory,'.plan-');if($tmp===false)return;
            try {chmod($tmp,0600);if(file_put_contents($tmp,$json)!==strlen($json))return;if(!@rename($tmp,$file))return;}
            finally {if(is_file($tmp))@unlink($tmp);}
            $this->stats['writes']++;
            $files=glob($this->directory.'/*.json')?:[];
            if(count($files)>$this->limit){usort($files,fn($a,$b)=>(@filemtime($a)?:0)<=>(@filemtime($b)?:0));foreach(array_slice($files,0,count($files)-$this->limit) as $old)if(!is_link($old))@unlink($old);}
        }catch(\Throwable $e){$this->stats['errors']++;}
    }
}
