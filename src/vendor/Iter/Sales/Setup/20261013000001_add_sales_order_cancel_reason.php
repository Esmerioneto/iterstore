<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/** Why the order was canceled: a code of OrderService::CANCEL_REASONS (CANCEL-PLAN). Orders canceled before stay null ("Não informado"). */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE sales_order ADD COLUMN IF NOT EXISTS cancel_reason VARCHAR(16) NULL');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE sales_order DROP COLUMN IF EXISTS cancel_reason');
    }
};
