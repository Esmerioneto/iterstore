<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

return new class implements MigrationInterface {
    // a configurable product (the parent) groups variants: simple products with parent_id,
    // one per combination of its variant attributes (e.g. color × size)
    public function up(PDO $pdo): void
    {
        $pdo->exec("ALTER TABLE catalog_product
            ADD COLUMN IF NOT EXISTS type VARCHAR(16) NOT NULL DEFAULT 'simple' CHECK (type IN ('simple', 'configurable')),
            ADD COLUMN IF NOT EXISTS parent_id INT NULL REFERENCES catalog_product (product_id) ON DELETE CASCADE,
            ADD COLUMN IF NOT EXISTS variant_key VARCHAR(255) NULL");
        $pdo->exec('ALTER TABLE catalog_product DROP CONSTRAINT IF EXISTS catalog_product_variant_key_check');
        $pdo->exec('ALTER TABLE catalog_product ADD CONSTRAINT catalog_product_variant_key_check CHECK ((parent_id IS NULL) = (variant_key IS NULL))');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS catalog_product_parent_variant ON catalog_product (parent_id, variant_key)');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS catalog_product_variant_attribute (
                product_id INT NOT NULL REFERENCES catalog_product (product_id) ON DELETE CASCADE,
                attribute_id INT NOT NULL REFERENCES eav_attribute (attribute_id) ON DELETE RESTRICT,
                position SMALLINT NOT NULL DEFAULT 0,
                PRIMARY KEY (product_id, attribute_id)
            )'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS catalog_product_variant_attribute');
        $pdo->exec('DELETE FROM catalog_product WHERE parent_id IS NOT NULL');
        $pdo->exec('DROP INDEX IF EXISTS catalog_product_parent_variant');
        $pdo->exec('ALTER TABLE catalog_product DROP CONSTRAINT IF EXISTS catalog_product_variant_key_check,
            DROP COLUMN IF EXISTS variant_key, DROP COLUMN IF EXISTS parent_id, DROP COLUMN IF EXISTS type');
    }
};
