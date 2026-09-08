<?php
namespace WPDSQL\Engine;

/** Explicit connection and compatibility settings; no WordPress globals. */
final class Config {
    public function __construct(
        public readonly string $host,
        public readonly string $user = 'admin',
        public readonly string $database = 'postgres',
        public readonly ?string $region = null,
        public readonly ?string $profile = null,
        public readonly ?string $credentialsFile = null,
        public readonly string $schema = 'public',
        public readonly bool $valueCodec = false,
        public readonly string $sqlMode = '',
        public readonly string $tablePrefix = 'wp_',
        public readonly bool $cacheEnabled = true,
        public readonly ?string $cacheDirectory = null,
    ) {
        if (!in_array($schema, ['public', 'wp_live'], true)) {
            throw new \InvalidArgumentException('Unsupported application schema');
        }
    }
}
