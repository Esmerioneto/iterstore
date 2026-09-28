<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS eav_attribute_set (
                attribute_set_id SERIAL PRIMARY KEY,
                entity_type_id SMALLINT NOT NULL REFERENCES eav_entity_type (entity_type_id) ON DELETE CASCADE,
                code VARCHAR(100) NOT NULL,
                label VARCHAR(255) NOT NULL,
                is_default SMALLINT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
                CONSTRAINT eav_attribute_set_entity_code UNIQUE (entity_type_id, code),
                CONSTRAINT eav_attribute_set_code_format CHECK (code ~ '^[a-z][a-z0-9_]*$')
            )"
        );
        // at most one default set per entity type
        $pdo->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS eav_attribute_set_one_default
                ON eav_attribute_set (entity_type_id) WHERE is_default = 1'
        );
        $pdo->exec(
            'CREATE OR REPLACE TRIGGER eav_attribute_set_set_updated_at
                BEFORE UPDATE ON eav_attribute_set
                FOR EACH ROW
                EXECUTE FUNCTION set_updated_at()'
        );

        // ponytail: groups are just group_name on the item (spec §19); promote to an
        // eav_attribute_group table if groups ever need their own fields
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS eav_attribute_set_item (
                attribute_set_id INT NOT NULL REFERENCES eav_attribute_set (attribute_set_id) ON DELETE CASCADE,
                attribute_id INT NOT NULL REFERENCES eav_attribute (attribute_id) ON DELETE CASCADE,
                group_name VARCHAR(100) NULL,
                sort_order INT NOT NULL DEFAULT 0,
                PRIMARY KEY (attribute_set_id, attribute_id)
            )'
        );
        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS eav_attribute_set_item_attribute ON eav_attribute_set_item (attribute_id)'
        );

        // every seeded entity type starts with a default set; modules adding an
        // entity type later create theirs in the same migration
        $pdo->exec(
            "INSERT INTO eav_attribute_set (entity_type_id, code, label, is_default)
             SELECT entity_type_id, 'default', 'Padrão', 1 FROM eav_entity_type
             ON CONFLICT (entity_type_id, code) DO NOTHING"
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS eav_attribute_set_item');
        $pdo->exec('DROP TABLE IF EXISTS eav_attribute_set');
    }
};
