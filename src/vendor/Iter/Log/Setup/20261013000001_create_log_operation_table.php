<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/** Who did what in the admin, with the values before and after (LOG-OPERATION-PLAN §2). Insert only. */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        // no FK to admin_user: the line outlives the user, whose name is copied
        $pdo->exec("CREATE TABLE IF NOT EXISTS log_operation (
            log_id BIGSERIAL PRIMARY KEY,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            admin_user_id INT NULL,
            admin_name VARCHAR(255) NOT NULL,
            source VARCHAR(8) NOT NULL CHECK (source IN ('admin','api')),
            ip VARCHAR(45) NULL,
            action VARCHAR(16) NOT NULL,
            entity_type VARCHAR(32) NULL,
            entity_id INT NULL,
            entity_label VARCHAR(255) NULL,
            changes JSONB NULL
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS log_operation_created ON log_operation (created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS log_operation_entity ON log_operation (entity_type, entity_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS log_operation_user ON log_operation (admin_user_id)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS log_operation');
    }
};
