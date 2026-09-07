<?php
/** Reversible text envelope for PHP's binary serialized values. GPL-2.0-or-later. */
final class DSQL_Value_Codec {
    public const PREFIX='~dsqlb64:v1:';
    public static function encode(string $value): string {
        if(!preg_match('//u',$value)) throw new RuntimeException('Text codec requires UTF-8, optionally containing NUL bytes');
        if (!str_contains($value,"\0") && !str_starts_with($value,self::PREFIX)) return $value;
        return self::PREFIX.hash('sha256',$value).':'.base64_encode($value);
    }
    public static function decode(string $value): string {
        if (!str_starts_with($value,self::PREFIX)) return $value;
        $frame=substr($value,strlen(self::PREFIX));$split=strpos($frame,':');
        if($split!==64) throw new RuntimeException('Malformed DSQL value envelope');
        $digest=substr($frame,0,64);$decoded=base64_decode(substr($frame,65),true);
        if($decoded===false || base64_encode($decoded)!==substr($frame,65) || !hash_equals($digest,hash('sha256',$decoded))) throw new RuntimeException('DSQL value envelope checksum mismatch');
        return $decoded; // Exactly once: literal envelope-looking input also round-trips.
    }
}
