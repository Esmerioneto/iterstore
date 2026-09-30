<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

// customer "forgot password" (requestCustomerPasswordReset): one link per customer, only its sha256 is stored.
// A table of its own, so customer_entity and the view customer_flat don't change.
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS customer_password_reset (
                customer_id INTEGER PRIMARY KEY REFERENCES customer_entity (customer_id) ON DELETE CASCADE,
                token_hash CHAR(64) NOT NULL UNIQUE,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS customer_password_reset');
    }
};
