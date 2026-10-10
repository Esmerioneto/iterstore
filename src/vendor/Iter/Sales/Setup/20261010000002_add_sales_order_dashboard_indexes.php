<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * DASHBOARD-PLAN delivery 2: the sales dashboard reads the orders changed since its watermark
 * (sales_order_updated) and, once an hour, each day's fingerprint straight from sales_order_dashboard
 * (index-only scan: count, sum, highest updated_at and status, without reading the table).
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec('CREATE INDEX IF NOT EXISTS sales_order_updated ON sales_order (website_id, updated_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS sales_order_dashboard ON sales_order (website_id, created_at) INCLUDE (status, grand_total, updated_at)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP INDEX IF EXISTS sales_order_dashboard');
        $pdo->exec('DROP INDEX IF EXISTS sales_order_updated');
    }
};
