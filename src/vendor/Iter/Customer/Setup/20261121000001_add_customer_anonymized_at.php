<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/** LGPD-PLAN §5: when the customer's data was erased at their request (the row stays, anonymized) */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE customer_entity ADD COLUMN IF NOT EXISTS anonymized_at TIMESTAMP NULL');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE customer_entity DROP COLUMN IF EXISTS anonymized_at');
    }
};
