<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * PARENT-IMAGE-PLAN: the variant whose images a configurable product shows (only on the parent).
 * Removing that variant clears the choice; that it is the parent's own variant is checked by VariantService.
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE catalog_product ADD COLUMN IF NOT EXISTS image_variant_id INT NULL REFERENCES catalog_product (product_id) ON DELETE SET NULL');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE catalog_product DROP COLUMN IF EXISTS image_variant_id');
    }
};
