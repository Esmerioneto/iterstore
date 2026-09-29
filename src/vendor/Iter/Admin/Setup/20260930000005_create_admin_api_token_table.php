<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

// API login (GraphQL generateAdminToken): only the token's sha256 is stored, as with rp_token
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS admin_api_token (
                token_id SERIAL PRIMARY KEY,
                user_id INTEGER NOT NULL REFERENCES admin_user (user_id) ON DELETE CASCADE,
                token_hash CHAR(64) NOT NULL UNIQUE,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at TIMESTAMP NOT NULL
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS admin_api_token_user_id ON admin_api_token (user_id)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS admin_api_token');
    }
};
