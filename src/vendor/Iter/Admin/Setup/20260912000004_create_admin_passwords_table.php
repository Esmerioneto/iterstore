<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS admin_passwords (
                password_id SERIAL PRIMARY KEY,
                user_id INTEGER NOT NULL DEFAULT 0,
                password_hash VARCHAR(255),
                expires INTEGER NOT NULL DEFAULT 0,
                last_updated INTEGER NOT NULL DEFAULT 0,
                CONSTRAINT admin_passwords_user_id_admin_user_user_id
                    FOREIGN KEY (user_id) REFERENCES admin_user (user_id) ON DELETE CASCADE
            )'
        );

        // Postgres does not auto-index FK columns like MySQL/InnoDB does
        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS admin_passwords_user_id ON admin_passwords (user_id)'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS admin_passwords');
    }
};
