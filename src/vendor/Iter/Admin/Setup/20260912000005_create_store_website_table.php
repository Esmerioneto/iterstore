<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS store_website (
                website_id SMALLSERIAL PRIMARY KEY,
                code VARCHAR(32),
                name VARCHAR(64),
                sort_order SMALLINT NOT NULL DEFAULT 0,
                default_group_id SMALLINT NOT NULL DEFAULT 0,
                is_default SMALLINT DEFAULT 0,
                CONSTRAINT store_website_code UNIQUE (code)
            )'
        );

        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS store_website_sort_order ON store_website (sort_order)'
        );
        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS store_website_default_group_id ON store_website (default_group_id)'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS store_website');
    }
};
