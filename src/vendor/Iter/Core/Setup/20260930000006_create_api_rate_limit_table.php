<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

// API rate limit (Iter\Core\RateLimit): hits per key (e.g. "admin_login:<ip>") per one-minute window
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS api_rate_limit (
                rate_key VARCHAR(128) NOT NULL,
                window_start TIMESTAMP NOT NULL,
                hits INTEGER NOT NULL DEFAULT 1,
                PRIMARY KEY (rate_key, window_start)
            )'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS api_rate_limit');
    }
};
