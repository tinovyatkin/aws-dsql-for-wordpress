<?php

declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class verifyAgainstStubsTest extends TestCase
{
    public const STUBS_DIRECTORY = __DIR__ . '/stubs';

    public function test_verify_against_stubs()
    {
        $files = array_diff(scandir(self::STUBS_DIRECTORY), array('.', '..'));
        foreach($files as $file) {
            $data = json_decode(file_get_contents(self::STUBS_DIRECTORY . "/" . $file), true);
            $this->assertSame($data['postgresql'], rewrite_fixture_sql($data['mysql']));
        }
    }

    protected function setUp(): void
    {
        global $wpdb;
        $wpdb = new class () {
            public $categories = "wp_categories";
            public $comments = "wp_comments";
            public $prefix = "wp_";
            public $options = "wp_options";
        };
    }
}
