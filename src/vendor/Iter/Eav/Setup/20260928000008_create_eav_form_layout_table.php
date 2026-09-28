<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        // each admin's arrangement of an entity's form ("Organizar tela"); no row = default order
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS eav_form_layout (
                user_id INT NOT NULL REFERENCES admin_user (user_id) ON DELETE CASCADE,
                entity_type VARCHAR(32) NOT NULL,
                layout JSONB NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
                PRIMARY KEY (user_id, entity_type)
            )'
        );
        $pdo->exec(
            'CREATE OR REPLACE TRIGGER eav_form_layout_set_updated_at
                BEFORE UPDATE ON eav_form_layout
                FOR EACH ROW
                EXECUTE FUNCTION set_updated_at()'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS eav_form_layout');
    }
};
