<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * The lookups that don't start with promotion_id: the promotion list counts and the uses screen
 * (sales_order_discount by promotion), canceling an order (promotion_coupon_usage by order) and
 * deleting a product or category (the cascade into the promotion's links).
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec('CREATE INDEX IF NOT EXISTS sales_order_discount_promotion ON sales_order_discount (promotion_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS promotion_coupon_usage_order ON promotion_coupon_usage (order_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS promotion_product_product ON promotion_product (product_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS promotion_category_category ON promotion_category (category_id)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP INDEX IF EXISTS promotion_category_category');
        $pdo->exec('DROP INDEX IF EXISTS promotion_product_product');
        $pdo->exec('DROP INDEX IF EXISTS promotion_coupon_usage_order');
        $pdo->exec('DROP INDEX IF EXISTS sales_order_discount_promotion');
    }
};
