<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

// COMMERCE-PLAN delivery 4: the payment method chosen in the cart
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE sales_quote ADD COLUMN IF NOT EXISTS payment_method VARCHAR(64) NULL');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE sales_quote DROP COLUMN IF EXISTS payment_method');
    }
};
