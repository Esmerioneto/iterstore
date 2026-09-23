<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS admin_role_rule (
                role_id INTEGER NOT NULL,
                resource_id VARCHAR(128) NOT NULL,
                PRIMARY KEY (role_id, resource_id),
                CONSTRAINT admin_role_rule_role_id_admin_role_role_id
                    FOREIGN KEY (role_id) REFERENCES admin_role (role_id) ON DELETE CASCADE
            )'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS admin_role_rule');
    }
};
