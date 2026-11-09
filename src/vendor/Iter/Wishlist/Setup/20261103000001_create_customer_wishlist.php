<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/** WISHLIST-PLAN §2: the customer's favourites, one row per product (only parents) */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS customer_wishlist_item (
            customer_id INT NOT NULL REFERENCES customer_entity (customer_id) ON DELETE CASCADE,
            product_id INT NOT NULL REFERENCES catalog_product (product_id) ON DELETE CASCADE,
            created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
            PRIMARY KEY (customer_id, product_id)
        )");
        // the product's delete cascades through it
        $pdo->exec('CREATE INDEX IF NOT EXISTS customer_wishlist_item_product ON customer_wishlist_item (product_id)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS customer_wishlist_item');
    }
};
