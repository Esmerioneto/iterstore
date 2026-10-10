<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * DASHBOARD-PLAN delivery 1: the sales channel of the order, as the app (or another client) names it in
 * the X-Sales-Channel header; "Atendimento" for Vendas → Novo pedido. Every order placed before this is
 * "App mobile" (decision 2: the only channel until now, the admin's ones included).
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec("ALTER TABLE sales_order ADD COLUMN IF NOT EXISTS sales_channel VARCHAR(64) NOT NULL DEFAULT 'App mobile'");
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE sales_order DROP COLUMN IF EXISTS sales_channel');
    }
};
