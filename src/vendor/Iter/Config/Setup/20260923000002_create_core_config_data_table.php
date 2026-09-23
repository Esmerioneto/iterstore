<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        // Magento's core_config_data. Only scope "default"/0 is used for now;
        // scope/scope_id are here so per-website/store values need no migration.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS core_config_data (
                config_id SERIAL PRIMARY KEY,
                scope VARCHAR(8) NOT NULL DEFAULT \'default\',
                scope_id INTEGER NOT NULL DEFAULT 0,
                path VARCHAR(255) NOT NULL DEFAULT \'general\',
                value TEXT,
                updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
                CONSTRAINT core_config_data_scope_scope_id_path UNIQUE (scope, scope_id, path)
            )'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS core_config_data');
    }
};
