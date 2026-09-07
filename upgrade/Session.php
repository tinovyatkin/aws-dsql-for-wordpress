<?php
namespace WPDSQLUpgrade;
final class Session {
    public array $data;
    private $lock;
    public function __construct(public string $directory,bool $lock=true) {
        $this->directory=realpath($directory)?:throw new \RuntimeException('Upgrade session directory missing');
        $this->data=json_decode(file_get_contents($this->directory.'/session.json'),true,512,JSON_THROW_ON_ERROR);
        if(($this->data['format']??'')!=='wordpress-dsql-upgrade-v1'||$this->data['directory']!==$this->directory||$this->data['host']!==gethostname())throw new \RuntimeException('Upgrade session belongs to another path or host');
        if($lock){$this->lock=fopen($this->directory.'/engine.lock','c');if(!flock($this->lock,LOCK_EX|LOCK_NB))throw new \RuntimeException('An upgrade process is already running');}
    }
    public static function guard(string $wordpress): string {$wordpress=realpath($wordpress)?:rtrim($wordpress,'/');return dirname($wordpress).'/.dsql-upgrade-'.substr(hash('sha256',$wordpress),0,16);}
    public static function syncDirectory(string $directory): void {
        $handle=fopen($directory,'r');if(!$handle)throw new \RuntimeException('Cannot open journal directory');
        try{if(!fsync($handle))throw new \RuntimeException('Cannot synchronize journal directory');}finally{fclose($handle);}
    }
    public static function write(string $path,array $value): void {
        $temp=$path.'.next';$f=fopen($temp,'wb');if(!$f)throw new \RuntimeException('Cannot save upgrade journal');chmod($temp,0600);
        try{$bytes=json_encode($value,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n";if(fwrite($f,$bytes)!==strlen($bytes))throw new \RuntimeException('Short journal write');fflush($f);fsync($f);}finally{fclose($f);}
        if(!rename($temp,$path))throw new \RuntimeException('Cannot publish upgrade journal');
        self::syncDirectory(dirname($path));
    }
    public function save(): void {self::write($this->directory.'/session.json',$this->data);}
    public function fail(): void {$this->data['failed']=true;$this->data['verified']=false;$this->save();}
    public function assertActive(): void {
        $guard=self::guard($this->data['wordpress']);
        if($this->data['closed']??false)throw new \RuntimeException('Upgrade session is closed');
        if(!is_file($guard)||trim(file_get_contents($guard))!==$this->data['id'])throw new \RuntimeException('Upgrade maintenance guard is missing or belongs to another session');
        if($this->data['failed']??false)throw new \RuntimeException('Upgrade failed; recover before running more WordPress commands');
    }
    public function operation(): ?array {return is_file($this->directory.'/pending.json')?json_decode(file_get_contents($this->directory.'/pending.json'),true,512,JSON_THROW_ON_ERROR):null;}
    public function pending(array $op): void {self::write($this->directory.'/pending.json',$op);$this->data['verified']=false;$this->save();}
    public function complete(array $op): void {
        self::write($this->directory.'/operation-'.$op['id'].'.json',$op+['completed_at'=>gmdate('c')]);
        unlink($this->directory.'/pending.json');
        self::syncDirectory($this->directory);
    }
}
