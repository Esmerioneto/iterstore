<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

return new class implements MigrationInterface {
    // "Usar na grade": a select attribute that can form a product's variants (Catalog)
    public function up(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE eav_attribute ADD COLUMN IF NOT EXISTS is_variant SMALLINT NOT NULL DEFAULT 0');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE eav_attribute DROP COLUMN IF EXISTS is_variant');
    }
};
