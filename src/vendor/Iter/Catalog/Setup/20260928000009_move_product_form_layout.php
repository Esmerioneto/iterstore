<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

return new class implements MigrationInterface {
    // the product layouts move to Eav's generic eav_form_layout (Eav migrates before Catalog)
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            "INSERT INTO eav_form_layout (user_id, entity_type, layout, updated_at)
             SELECT user_id, 'product', layout, updated_at FROM catalog_product_form_layout
             ON CONFLICT (user_id, entity_type) DO NOTHING"
        );
        $pdo->exec('DROP TABLE catalog_product_form_layout');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS catalog_product_form_layout (
                user_id INT PRIMARY KEY REFERENCES admin_user (user_id) ON DELETE CASCADE,
                layout JSONB NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT NOW()
            )'
        );
        $pdo->exec(
            "INSERT INTO catalog_product_form_layout (user_id, layout, updated_at)
             SELECT user_id, layout, updated_at FROM eav_form_layout WHERE entity_type = 'product'"
        );
        $pdo->exec("DELETE FROM eav_form_layout WHERE entity_type = 'product'");
    }
};
