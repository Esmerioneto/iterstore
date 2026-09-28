<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        // sku/name/status are columns (list, search, uniqueness); everything else is EAV.
        // RESTRICT: a set with products can't be deleted out from under them
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS catalog_product (
                product_id SERIAL PRIMARY KEY,
                attribute_set_id INT NOT NULL REFERENCES eav_attribute_set (attribute_set_id) ON DELETE RESTRICT,
                sku VARCHAR(64) NOT NULL UNIQUE,
                name VARCHAR(255) NOT NULL,
                is_active SMALLINT NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW()
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS catalog_product_set ON catalog_product (attribute_set_id)');
        $pdo->exec(
            'CREATE OR REPLACE TRIGGER catalog_product_set_updated_at
                BEFORE UPDATE ON catalog_product
                FOR EACH ROW
                EXECUTE FUNCTION set_updated_at()'
        );

        // system attributes every product has, in the default set's "Geral" group
        $pdo->exec(
            "INSERT INTO eav_attribute (entity_type_id, code, label, input_type, storage_type, is_required, is_visible, is_system, sort_order)
             SELECT entity_type_id, a.code, a.label, a.input_type, a.storage_type, a.is_required, 1, 1, a.sort_order
             FROM eav_entity_type, (VALUES ('price', 'Preço', 'number', 'decimal', 1, 10), ('description', 'Descrição', 'textarea', 'text', 0, 20))
                  AS a (code, label, input_type, storage_type, is_required, sort_order)
             WHERE eav_entity_type.code = 'product'
             ON CONFLICT (entity_type_id, code) DO NOTHING"
        );
        $pdo->exec(
            "INSERT INTO eav_attribute_set_item (attribute_set_id, attribute_id, group_name, sort_order)
             SELECT s.attribute_set_id, a.attribute_id, 'Geral', a.sort_order
             FROM eav_attribute_set s
             JOIN eav_attribute a ON a.entity_type_id = s.entity_type_id AND a.code IN ('price', 'description')
             JOIN eav_entity_type et ON et.entity_type_id = s.entity_type_id
             WHERE et.code = 'product' AND s.code = 'default'
             ON CONFLICT DO NOTHING"
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS catalog_product');
        $pdo->exec(
            "DELETE FROM eav_attribute WHERE is_system = 1 AND code IN ('price', 'description')
             AND entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE code = 'product')"
        );
    }
};
