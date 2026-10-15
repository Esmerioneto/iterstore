<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * Deleting a product checks sales_order_item and cascades into sales_quote_item by product_id:
 * without these indexes each delete reads both tables whole.
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec('CREATE INDEX IF NOT EXISTS sales_order_item_product ON sales_order_item (product_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS sales_quote_item_product ON sales_quote_item (product_id)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP INDEX IF EXISTS sales_quote_item_product');
        $pdo->exec('DROP INDEX IF EXISTS sales_order_item_product');
    }
};
