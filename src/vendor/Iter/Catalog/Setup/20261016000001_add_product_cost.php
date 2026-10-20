<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * REPORT-PLAN: the product's cost, a system attribute right after the price (in its group), in every
 * set that has the price. Read only with Iter_Catalog::cost; the order item keeps a copy (sales_order_item.cost).
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            "INSERT INTO eav_attribute (entity_type_id, code, label, input_type, storage_type, is_required, is_visible, is_system, sort_order)
             SELECT entity_type_id, 'cost', 'Custo', 'number', 'decimal', 0, 1, 1, 11 FROM eav_entity_type
             WHERE code = 'product' AND NOT EXISTS (SELECT 1 FROM eav_attribute e WHERE e.entity_type_id = eav_entity_type.entity_type_id AND e.code = 'cost')"
        );
        $pdo->exec("UPDATE eav_attribute SET is_system = 1 WHERE code = 'cost' AND entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE code = 'product')");
        $pdo->exec(
            "INSERT INTO eav_attribute_set_item (attribute_set_id, attribute_id, group_name, sort_order)
             SELECT i.attribute_set_id, c.attribute_id, i.group_name, i.sort_order + 1
             FROM eav_attribute_set_item i
             JOIN eav_attribute p ON p.attribute_id = i.attribute_id AND p.code = 'price'
             JOIN eav_attribute c ON c.entity_type_id = p.entity_type_id AND c.code = 'cost'
             ON CONFLICT DO NOTHING"
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec("DELETE FROM eav_attribute WHERE code = 'cost' AND entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE code = 'product')");
    }
};
