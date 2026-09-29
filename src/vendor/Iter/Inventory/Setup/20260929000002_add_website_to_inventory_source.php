<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        // a source serves one website; the existing ones go to the default website (or the first one)
        $pdo->exec('ALTER TABLE inventory_source ADD COLUMN IF NOT EXISTS website_id SMALLINT NULL REFERENCES store_website (website_id) ON DELETE RESTRICT');
        $pdo->exec('UPDATE inventory_source SET website_id = (SELECT website_id FROM store_website ORDER BY is_default DESC, sort_order, website_id LIMIT 1) WHERE website_id IS NULL');
        $pdo->exec('ALTER TABLE inventory_source ALTER COLUMN website_id SET NOT NULL');
        $pdo->exec('CREATE INDEX IF NOT EXISTS inventory_source_website ON inventory_source (website_id)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE inventory_source DROP COLUMN IF EXISTS website_id');
    }
};
