<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        // each admin's arrangement of the product form ("Organizar tela"); no row = default order
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS catalog_product_form_layout (
                user_id INT PRIMARY KEY REFERENCES admin_user (user_id) ON DELETE CASCADE,
                layout JSONB NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT NOW()
            )'
        );
        $pdo->exec(
            'CREATE OR REPLACE TRIGGER catalog_product_form_layout_set_updated_at
                BEFORE UPDATE ON catalog_product_form_layout
                FOR EACH ROW
                EXECUTE FUNCTION set_updated_at()'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS catalog_product_form_layout');
    }
};
