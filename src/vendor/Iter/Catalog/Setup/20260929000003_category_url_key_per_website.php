<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

return new class implements MigrationInterface {
    // two websites can have a category with the same URL key
    public function up(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE catalog_category DROP CONSTRAINT IF EXISTS catalog_category_url_key_key');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS catalog_category_website_url_key ON catalog_category (website_id, url_key)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP INDEX IF EXISTS catalog_category_website_url_key');
        $pdo->exec('ALTER TABLE catalog_category ADD CONSTRAINT catalog_category_url_key_key UNIQUE (url_key)');
    }
};
