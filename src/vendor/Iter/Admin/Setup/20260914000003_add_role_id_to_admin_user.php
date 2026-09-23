<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'ALTER TABLE admin_user
                ADD COLUMN IF NOT EXISTS role_id INTEGER REFERENCES admin_role (role_id) ON DELETE SET NULL'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE admin_user DROP COLUMN IF EXISTS role_id');
    }
};
