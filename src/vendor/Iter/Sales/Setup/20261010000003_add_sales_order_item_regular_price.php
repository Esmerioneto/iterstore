<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * PROMO-PLAN delivery 1: the item keeps the product's full price next to what was paid, so the order shows
 * when it was bought at the promotional price (price_promo). Older orders stay NULL: unknown then.
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE sales_order_item ADD COLUMN IF NOT EXISTS regular_price NUMERIC(12,2) NULL');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE sales_order_item DROP COLUMN IF EXISTS regular_price');
    }
};
