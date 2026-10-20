<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * REPORT-PLAN: the item keeps the product's cost at the time of the order, for the margin in the reports.
 * Older orders stay NULL: no margin (decided 2026-10-16, no backfill).
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE sales_order_item ADD COLUMN IF NOT EXISTS cost NUMERIC(12,4) NULL');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE sales_order_item DROP COLUMN IF EXISTS cost');
    }
};
