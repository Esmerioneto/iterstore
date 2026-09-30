<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

// customer login through the API (generateCustomerToken): only the token's sha256 is stored, as admin_api_token;
// confirmation_hash is set while the e-mail waits for confirmation (customer/account/confirm_email)
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS customer_api_token (
                token_id SERIAL PRIMARY KEY,
                customer_id INTEGER NOT NULL REFERENCES customer_entity (customer_id) ON DELETE CASCADE,
                token_hash CHAR(64) NOT NULL UNIQUE,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at TIMESTAMP NOT NULL
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS customer_api_token_customer_id ON customer_api_token (customer_id)');
        $pdo->exec('ALTER TABLE customer_entity ADD COLUMN IF NOT EXISTS confirmation_hash CHAR(64) NULL UNIQUE');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE customer_entity DROP COLUMN IF EXISTS confirmation_hash');
        $pdo->exec('DROP TABLE IF EXISTS customer_api_token');
    }
};
