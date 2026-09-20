<?php
namespace WPDSQL\Schema;

/** Coordinates WordPress requests sharing one schema state directory. */
final class SchemaGate {
    private static array $instances=[];
    private $handle;
    private bool $exclusive=false;
    private bool $suspended=false;
    private function __construct(public readonly string $directory) {
        if(!is_dir($directory)||!is_writable($directory))throw new \RuntimeException('Automatic schema state directory is unavailable');
        $new=!file_exists($directory.'/access.lock');
        $this->handle=fopen($directory.'/access.lock','c');
        if(!$this->handle)throw new \RuntimeException('Cannot open the schema coordination lock');
        if($new)chmod($directory.'/access.lock',0660);$this->lock(LOCK_SH);
    }
    public static function get(string $directory): self {
        $directory=realpath($directory)?:throw new \RuntimeException('Automatic schema state directory is missing');
        return self::$instances[$directory]??=new self($directory);
    }
    private function lock(int $mode): void {
        $deadline=microtime(true)+20;
        do {
            if(flock($this->handle,$mode|LOCK_NB))return;
            usleep(50000);
        }while(microtime(true)<$deadline);
        throw new SchemaBusy('A database schema update is in progress; retry shortly');
    }
    public static function canSuspend(): bool {foreach(self::$instances as $gate)if($gate->exclusive)return false;return true;}
    public static function suspendForHttp(): void {foreach(self::$instances as $gate){flock($gate->handle,LOCK_UN);$gate->suspended=true;}}
    public function resume(): void {if($this->suspended){$this->lock(LOCK_SH);$this->suspended=false;clearstatcache();}}
    public function exclusive(callable $operation): mixed {
        $this->resume();
        if($this->exclusive)return $operation();
        // Release this request's shared lease before waiting for other requests.
        flock($this->handle,LOCK_UN);
        try {$this->lock(LOCK_EX);$this->exclusive=true;clearstatcache();return $operation();}
        finally {$this->exclusive=false;$this->lock(LOCK_SH);}
    }
    public function __destruct() {if(is_resource($this->handle)){flock($this->handle,LOCK_UN);fclose($this->handle);}}
}
