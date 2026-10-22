<?php
use PHPUnit\Framework\TestCase;
use WPDSQL\Engine\RequestTiming;

final class requestTimingTest extends TestCase {
    public function test_nested_query_and_connection_work_are_not_double_counted(): void {
        $now=0.0;$timing=new RequestTiming(static function()use(&$now){return $now;});
        $result=$timing->measure(function()use($timing,&$now){
            $now=2.0;$timing->measure(function()use(&$now){$now=7.0;});
            $now=10.0;return 'result';
        },true);
        $timing->measure(function()use(&$now){$now=13.0;});
        self::assertSame('result',$result);
        self::assertSame(13.0,$timing->snapshot()['total_ms']);
        self::assertSame(1,$timing->snapshot()['query_calls']);
    }
    public function test_failure_records_elapsed_time_and_keeps_exception_identity(): void {
        $now=0.0;$timing=new RequestTiming(static function()use(&$now){return $now;});
        $error=new RuntimeException('failure');
        try{$timing->measure(function()use(&$now,$error){$now=5.0;throw $error;},true);self::fail('Exception swallowed');}
        catch(RuntimeException $caught){self::assertSame($error,$caught);}
        $timing->measure(function()use(&$now){$now=7.0;},true);
        self::assertSame(7.0,$timing->snapshot()['total_ms']);
        self::assertSame(2,$timing->snapshot()['query_calls']);
        self::assertSame(1,$timing->snapshot()['failed_queries']);
    }
    public function test_only_bounded_numeric_phase_totals_are_retained(): void {
        $timing=new RequestTiming();
        $timing->recordNative(['execute_ms'=>3.0,'tokenize_ms'=>0.2,'query'=>'private','rows'=>[['private']],'metadata_ms'=>NAN,'decode_ms'=>-1]);
        $timing->recordNative(['execute_ms'=>2,'compile_ms'=>0.1,'metadata_ms'=>INF,'decode_ms'=>'private']);
        $stats=$timing->snapshot();
        self::assertSame(5.0,$stats['execute_ms']);self::assertSame(0.2,$stats['tokenize_ms']);
        self::assertSame(0.0,$stats['metadata_ms']);self::assertSame(0.0,$stats['decode_ms']);
        self::assertCount(8,$stats);self::assertStringNotContainsString('private',json_encode($stats));
    }
}
