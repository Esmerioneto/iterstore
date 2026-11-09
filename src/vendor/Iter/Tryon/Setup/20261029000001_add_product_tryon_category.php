<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * "Tipo de peça no provador" in every product set, next to "Provador virtual": which part of the body the garment
 * takes. A dedicated try-on model needs it (FASHN: tops, bottoms, one-pieces); empty = a top.
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            "INSERT INTO eav_attribute (entity_type_id, code, label, input_type, storage_type, is_required, is_visible, is_system, sort_order)
             SELECT entity_type_id, 'tryon_category', 'Tipo de peça no provador', 'select', 'varchar', 0, 1, 1, 91 FROM eav_entity_type
             WHERE code = 'product' AND NOT EXISTS (SELECT 1 FROM eav_attribute e WHERE e.entity_type_id = eav_entity_type.entity_type_id AND e.code = 'tryon_category')"
        );
        $pdo->exec(
            "INSERT INTO eav_attribute_option (attribute_id, value, label, sort_order, is_default)
             SELECT a.attribute_id, o.value, o.label, o.sort_order, o.is_default
             FROM eav_attribute a JOIN eav_entity_type t ON t.entity_type_id = a.entity_type_id AND t.code = 'product'
             CROSS JOIN (VALUES ('tops', 'Parte de cima (camiseta, camisa, blusa, jaqueta)', 10, 1),
                                ('bottoms', 'Parte de baixo (calça, bermuda, saia)', 20, 0),
                                ('one-pieces', 'Peça inteira (vestido, macacão)', 30, 0)) AS o (value, label, sort_order, is_default)
             WHERE a.code = 'tryon_category'
               AND NOT EXISTS (SELECT 1 FROM eav_attribute_option x WHERE x.attribute_id = a.attribute_id AND x.value = o.value)"
        );
        // the group of "Provador virtual", right after it
        $pdo->exec(
            "INSERT INTO eav_attribute_set_item (attribute_set_id, attribute_id, group_name, sort_order)
             SELECT s.attribute_set_id, c.attribute_id,
                    COALESCE((SELECT i.group_name FROM eav_attribute_set_item i WHERE i.attribute_set_id = s.attribute_set_id AND i.attribute_id = e.attribute_id),
                             (SELECT i.group_name FROM eav_attribute_set_item i WHERE i.attribute_set_id = s.attribute_set_id ORDER BY i.sort_order LIMIT 1),
                             'Geral'),
                    COALESCE((SELECT i.sort_order + 1 FROM eav_attribute_set_item i WHERE i.attribute_set_id = s.attribute_set_id AND i.attribute_id = e.attribute_id), 91)
             FROM eav_attribute_set s
             JOIN eav_attribute c ON c.entity_type_id = s.entity_type_id AND c.code = 'tryon_category'
             JOIN eav_attribute e ON e.entity_type_id = s.entity_type_id AND e.code = 'tryon_enabled'
             ON CONFLICT DO NOTHING"
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec("DELETE FROM eav_attribute WHERE code = 'tryon_category' AND entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE code = 'product')");
    }
};
