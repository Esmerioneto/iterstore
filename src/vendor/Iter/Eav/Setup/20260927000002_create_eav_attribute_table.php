<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS eav_attribute (
                attribute_id SERIAL PRIMARY KEY,
                entity_type_id SMALLINT NOT NULL REFERENCES eav_entity_type (entity_type_id) ON DELETE CASCADE,
                code VARCHAR(100) NOT NULL,
                label VARCHAR(255) NOT NULL,
                input_type VARCHAR(32) NOT NULL,
                storage_type VARCHAR(16) NOT NULL,
                default_value TEXT NULL,
                is_required SMALLINT NOT NULL DEFAULT 0,
                is_visible SMALLINT NOT NULL DEFAULT 1,
                is_searchable SMALLINT NOT NULL DEFAULT 0,
                is_filterable SMALLINT NOT NULL DEFAULT 0,
                is_sortable SMALLINT NOT NULL DEFAULT 0,
                is_system SMALLINT NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
                CONSTRAINT eav_attribute_entity_code UNIQUE (entity_type_id, code),
                CONSTRAINT eav_attribute_code_format CHECK (code ~ '^[a-z][a-z0-9_]*$'),
                CONSTRAINT eav_attribute_storage_type
                    CHECK (storage_type IN ('varchar', 'text', 'int', 'decimal', 'datetime', 'boolean'))
            )"
        );

        $pdo->exec(
            'CREATE OR REPLACE TRIGGER eav_attribute_set_updated_at
                BEFORE UPDATE ON eav_attribute
                FOR EACH ROW
                EXECUTE FUNCTION set_updated_at()'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS eav_attribute');
    }
};
