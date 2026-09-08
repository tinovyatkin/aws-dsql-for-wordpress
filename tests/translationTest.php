<?php
use PHPUnit\Framework\TestCase;
use WPDSQL\MySQL\Translation\Shape;
use WPDSQL\MySQL\Translation\Compiler;
use WPDSQL\MySQL\Translation\Renderer;

final class translationTest extends TestCase {
    private function translate(string $sql):array {
        $shape=new Shape($sql);$plan=(new Compiler($shape))->compile();
        $pdo=new class extends PDO {
            public function __construct(){}
            public function prepare(string $query,array $options=[]):PDOStatement|false {
                return new class extends PDOStatement {
                    public function execute(?array $params=null):bool {return true;}
                    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array {return array_map(fn($name)=>['column_name'=>$name,'data_type'=>'timestamp without time zone','is_nullable'=>'NO','column_default'=>null,'is_identity'=>'NO'],['comment_date_gmt','post_date']);}
                };
            }
        };
        return (new Renderer($pdo,'public',false))->render($plan['body'],$shape);
    }
    public function test_values_are_bound_not_compiled_into_sql():void {
        $a=$this->translate("SELECT CONCAT('a; DROP TABLE t', LOWER('SECOND')) AS value");
        self::assertSame(['a; DROP TABLE t','SECOND'],$a['params']);
        self::assertStringNotContainsString('DROP TABLE',$a['sql']);
        self::assertStringContainsString(' || ',$a['sql']);
        self::assertStringContainsString('LOWER(',$a['sql']);
    }
    public function test_nested_limit_and_outer_ordinal_are_independent():void {
        $result=$this->translate('SELECT (SELECT 10 LIMIT 1),20 ORDER BY 2 LIMIT 3,4');
        self::assertMatchesRegularExpression('/SELECT 10\s+LIMIT 1/',$result['sql']);
        self::assertMatchesRegularExpression('/ORDER BY 2\s+LIMIT 4 OFFSET 3/',$result['sql']);
    }
    public function test_values_never_reach_antlr_template():void {
        $shape=new Shape("SELECT '".str_repeat('large-private-content',10000)."', 2147483648");
        self::assertLessThan(100,strlen($shape->template));
        self::assertStringNotContainsString('large-private-content',$shape->template);
        $plan=(new Compiler($shape))->compile();
        self::assertStringNotContainsString('large-private-content',json_encode($plan));
    }
    public function test_lexically_significant_boundaries_are_preserved():void {
        foreach([["SELECT N'x'","SELECT N 'x'"],['SELECT 1abc','SELECT 2abc'],['SELECT COUNT(1)','SELECT COUNT/**/(1)'],['SELECT 1','SELECT 1.0']] as [$a,$b])self::assertNotSame((new Shape($a))->key,(new Shape($b))->key);
    }
    public function test_schema_and_metadata_statements_do_not_become_regular_cached_queries():void {
        $plan=(new Compiler(new Shape('CREATE TABLE wp_t(id int)')))->compile();
        self::assertSame('ddl',$plan['kind']);
        $this->expectException(RuntimeException::class);
        (new Compiler(new Shape('SHOW TABLES')))->compile();
    }
    public function test_multiple_statements_cannot_hit_single_statement_plan():void {
        $this->expectException(WPDSQL\MySQL\ParseException::class);
        (new Compiler(new Shape('SELECT 1; DELETE FROM wp_posts')))->compile();
    }
    public function test_sequential_assignment_dependencies_fail_before_execution():void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Sequentially dependent');
        (new Compiler(new Shape('UPDATE wp_t SET a=a+1,b=a')))->compile();
    }
    public function test_window_clause_cannot_be_silently_dropped_from_count():void {
        $this->expectException(RuntimeException::class);
        (new Compiler(new Shape('SELECT COUNT(*) OVER () FROM wp_comments')))->compile();
    }
    public function test_json_path_is_not_misinterpreted_as_postgresql_key():void {
        $this->expectException(RuntimeException::class);
        (new Compiler(new Shape("SELECT payload->>'$.name' FROM wp_t")))->compile();
    }
    public function test_found_rows_has_a_separate_unlimited_plan():void {
        $plan=(new Compiler(new Shape('SELECT SQL_CALC_FOUND_ROWS 1 LIMIT 0,1')))->compile();
        self::assertNotNull($plan['count']);
        self::assertStringNotContainsString('LimitClause',json_encode($plan['count']));
        $pdo=new class extends PDO {public function __construct(){}};
        $renderer=new Renderer($pdo,'public',false);
        self::assertStringNotContainsString('LIMIT',$renderer->render($plan['count'],new Shape('SELECT SQL_CALC_FOUND_ROWS 1 LIMIT 0,1'))['sql']);
    }
    public function test_distinct_calendar_dropdown_keeps_chronological_order():void {
        $result=$this->translate('SELECT DISTINCT YEAR(post_date) AS year,MONTH(post_date) AS month FROM wp_posts ORDER BY post_date DESC');
        self::assertMatchesRegularExpression('/ORDER BY 1 DESC , 2 DESC/',$result['sql']);
        $ordinary=$this->translate('SELECT YEAR(post_date),MONTH(post_date) FROM wp_posts ORDER BY post_date DESC');
        self::assertStringContainsString('ORDER BY "post_date" DESC',$ordinary['sql']);
    }
    public function test_global_count_drops_only_irrelevant_ordering():void {
        $result=$this->translate('SELECT COUNT(*) FROM wp_comments ORDER BY comment_date_gmt DESC');
        self::assertStringNotContainsString('ORDER BY',$result['sql']);
        $grouped=$this->translate('SELECT 1,COUNT(*) FROM wp_comments GROUP BY 1 ORDER BY 1');
        self::assertStringContainsString('ORDER BY 1',$grouped['sql']);
        self::assertStringContainsString('ORDER BY 1',$this->translate('SELECT 1 ORDER BY 1')['sql']);
        self::assertStringContainsString('ORDER BY',$this->translate('SELECT COUNT(*) FROM wp_comments ORDER BY unknown_column')['sql']);
        self::assertStringContainsString('RANDOM()',$this->translate('SELECT COUNT(*) FROM wp_comments ORDER BY RAND()')['sql']);
    }
    public function test_union_numeric_projection_is_cast_when_another_branch_is_text():void {
        $result=$this->translate("SELECT 'label' AS value UNION ALL SELECT 1 AS value");
        self::assertSame(['label'],$result['params']);
        self::assertStringContainsString('CAST(1 AS text)',$result['sql']);
    }
    public function test_distinct_term_ordering_carries_a_unique_key_guard():void {
        $plan=(new Compiler(new Shape('SELECT DISTINCT t.term_id FROM wp_terms t ORDER BY t.name')))->compile();
        $guards=[];$walk=function($n)use(&$walk,&$guards){if(!is_array($n))return;if(($n['op']??null)==='term_distinct')$guards[]=$n;foreach($n as $v)if(is_array($v))$walk($v);};$walk($plan);
        self::assertCount(1,$guards);
        self::assertSame('wp_terms',$guards[0]['table']);
    }
    public function test_nested_alias_scopes_keep_their_own_metadata():void {
        $plan=(new Compiler(new Shape('SELECT t.id FROM outer_table t WHERE EXISTS (SELECT 1 FROM inner_table t WHERE t.id=1)')))->compile();
        $columns=[];$walk=function($n)use(&$walk,&$columns){if(!is_array($n))return;if(($n['op']??null)==='column')$columns[]=$n;foreach($n as $v)if(is_array($v))$walk($v);};$walk($plan);
        self::assertSame(['outer_table'],$columns[0]['tables']);
        self::assertSame(['inner_table'],$columns[1]['tables']);
    }
    public function test_unsupported_functions_do_not_fall_back_to_regex_rewriting():void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported MySQL function');
        (new Compiler(new Shape('SELECT unreviewed_function(1)')))->compile();
    }
}
