<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS eav_entity_type (
                entity_type_id SMALLSERIAL PRIMARY KEY,
                code VARCHAR(64) NOT NULL,
                label VARCHAR(255) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
                CONSTRAINT eav_entity_type_code UNIQUE (code)
            )'
        );

        $pdo->exec(
            'CREATE OR REPLACE TRIGGER eav_entity_type_set_updated_at
                BEFORE UPDATE ON eav_entity_type
                FOR EACH ROW
                EXECUTE FUNCTION set_updated_at()'
        );

        // ponytail: no CRUD for entity types; modules add theirs with a migration like this one
        $pdo->exec(
            "INSERT INTO eav_entity_type (code, label) VALUES
                ('product', 'Produto'),
                ('customer', 'Cliente'),
                ('category', 'Categoria')
             ON CONFLICT (code) DO NOTHING"
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS eav_entity_type');
    }
};
