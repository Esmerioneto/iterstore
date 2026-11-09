<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * TRYON-PLAN §9.4: "Pode usar o provador virtual" in the customer's record, a system checkbox in every customer set
 * (in its first group), off by default. Only the store sets it (etc/customer_staff_attributes.json).
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            "INSERT INTO eav_attribute (entity_type_id, code, label, input_type, storage_type, is_required, is_visible, is_system, sort_order)
             SELECT entity_type_id, 'tryon_enabled', 'Pode usar o provador virtual', 'checkbox', 'boolean', 0, 1, 1, 90 FROM eav_entity_type
             WHERE code = 'customer' AND NOT EXISTS (SELECT 1 FROM eav_attribute e WHERE e.entity_type_id = eav_entity_type.entity_type_id AND e.code = 'tryon_enabled')"
        );
        $pdo->exec(
            "INSERT INTO eav_attribute_set_item (attribute_set_id, attribute_id, group_name, sort_order)
             SELECT s.attribute_set_id, t.attribute_id,
                    COALESCE((SELECT i.group_name FROM eav_attribute_set_item i WHERE i.attribute_set_id = s.attribute_set_id ORDER BY i.sort_order LIMIT 1), 'Geral'),
                    90
             FROM eav_attribute_set s JOIN eav_attribute t ON t.entity_type_id = s.entity_type_id AND t.code = 'tryon_enabled'
             ON CONFLICT DO NOTHING"
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec("DELETE FROM eav_attribute WHERE code = 'tryon_enabled' AND entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE code = 'customer')");
    }
};
