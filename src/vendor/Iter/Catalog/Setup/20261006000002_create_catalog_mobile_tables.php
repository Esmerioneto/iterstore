<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * MOBILE-PLAN: the app's order. Categories marked to show, in their own order, each with how many
 * products the app shows first (per_category) and the ones chosen by hand. No FK to
 * catalog_category_product: saving a product rewrites its links; a chosen product that left the
 * category is skipped when read (MobileService).
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS catalog_mobile_category (
                category_id INT PRIMARY KEY REFERENCES catalog_category (category_id) ON DELETE CASCADE,
                position INT NOT NULL DEFAULT 0,
                is_visible SMALLINT NOT NULL DEFAULT 1,
                per_category SMALLINT NOT NULL DEFAULT 10 CHECK (per_category BETWEEN 1 AND 50)
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS catalog_mobile_product (
                category_id INT NOT NULL REFERENCES catalog_mobile_category (category_id) ON DELETE CASCADE,
                product_id INT NOT NULL REFERENCES catalog_product (product_id) ON DELETE CASCADE,
                position INT NOT NULL DEFAULT 0,
                PRIMARY KEY (category_id, product_id)
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS catalog_mobile_product_product ON catalog_mobile_product (product_id)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS catalog_mobile_product');
        $pdo->exec('DROP TABLE IF EXISTS catalog_mobile_category');
    }
};
