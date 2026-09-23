<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS store_group (
                group_id SMALLSERIAL PRIMARY KEY,
                website_id SMALLINT NOT NULL DEFAULT 0,
                name VARCHAR(255) NOT NULL,
                root_category_id INTEGER NOT NULL DEFAULT 0,
                default_store_id SMALLINT NOT NULL DEFAULT 0,
                code VARCHAR(32),
                CONSTRAINT store_group_website_id_store_website_website_id
                    FOREIGN KEY (website_id) REFERENCES store_website (website_id) ON DELETE CASCADE,
                CONSTRAINT store_group_code UNIQUE (code)
            )'
        );

        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS store_group_website_id ON store_group (website_id)'
        );
        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS store_group_default_store_id ON store_group (default_store_id)'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS store_group');
    }
};
