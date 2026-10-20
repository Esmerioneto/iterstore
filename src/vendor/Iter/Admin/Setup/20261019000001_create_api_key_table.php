<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

// Sistema → Chaves da API: the key each app or site sends in X-Api-Key; only its sha256 is stored, as with the tokens
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS api_key (
                key_id SERIAL PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                key_hash CHAR(64) NOT NULL UNIQUE,
                key_hint VARCHAR(12) NOT NULL,
                is_active SMALLINT NOT NULL DEFAULT 1,
                created_by INTEGER NULL REFERENCES admin_user (user_id) ON DELETE SET NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_used_at TIMESTAMP NULL,
                revoked_at TIMESTAMP NULL
            )'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS api_key');
    }
};
