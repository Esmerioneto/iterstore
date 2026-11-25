<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * LGPD-PLAN §10: "Guardar o log de operações por" now defaults to 365 days, but only for new installations. One
 * already running (the log has lines) that never set it keeps "never delete", saved here as its own value.
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec("INSERT INTO core_config_data (scope, scope_id, path, value)
            SELECT 'default', 0, 'system/operation_log/retention_days', '0' WHERE EXISTS (SELECT 1 FROM log_operation)
            ON CONFLICT (scope, scope_id, path) DO NOTHING");
    }

    public function down(PDO $pdo): void
    {
    }
};
