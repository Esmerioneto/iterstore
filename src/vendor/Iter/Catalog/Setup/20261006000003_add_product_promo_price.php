<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * The promotional price: price_promo (kept when the store already created it) and the dates it runs between,
 * price_promo_from/price_promo_to, all system attributes. The dates go into every set that has price_promo
 * (the default set gets all three), in its group, after it.
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            "INSERT INTO eav_attribute (entity_type_id, code, label, input_type, storage_type, is_required, is_visible, is_system, sort_order)
             SELECT entity_type_id, a.code, a.label, a.input_type, a.storage_type, 0, 1, 1, a.sort_order
             FROM eav_entity_type, (VALUES ('price_promo', 'Preço promocional', 'number', 'decimal', 12),
                 ('price_promo_from', 'Promoção de', 'date', 'datetime', 13), ('price_promo_to', 'Promoção até', 'date', 'datetime', 14))
                 AS a (code, label, input_type, storage_type, sort_order)
             WHERE eav_entity_type.code = 'product'
                 AND NOT EXISTS (SELECT 1 FROM eav_attribute e WHERE e.entity_type_id = eav_entity_type.entity_type_id AND e.code = a.code)"
        );
        // the code reads it now: a store's own price_promo can't be deleted any more
        $pdo->exec("UPDATE eav_attribute SET is_system = 1 WHERE code = 'price_promo' AND entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE code = 'product')");
        $pdo->exec(
            "INSERT INTO eav_attribute_set_item (attribute_set_id, attribute_id, group_name, sort_order)
             SELECT s.attribute_set_id, a.attribute_id, 'Geral', a.sort_order
             FROM eav_attribute_set s
             JOIN eav_entity_type et ON et.entity_type_id = s.entity_type_id
             JOIN eav_attribute a ON a.entity_type_id = s.entity_type_id AND a.code = 'price_promo'
             WHERE et.code = 'product' AND s.code = 'default'
             ON CONFLICT DO NOTHING"
        );
        $pdo->exec(
            "INSERT INTO eav_attribute_set_item (attribute_set_id, attribute_id, group_name, sort_order)
             SELECT i.attribute_set_id, d.attribute_id, i.group_name, i.sort_order + d.sort_order - a.sort_order
             FROM eav_attribute_set_item i
             JOIN eav_attribute a ON a.attribute_id = i.attribute_id AND a.code = 'price_promo'
             JOIN eav_attribute d ON d.entity_type_id = a.entity_type_id AND d.code IN ('price_promo_from', 'price_promo_to')
             ON CONFLICT DO NOTHING"
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec("DELETE FROM eav_attribute WHERE code IN ('price_promo_from', 'price_promo_to') AND entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE code = 'product')");
        $pdo->exec("UPDATE eav_attribute SET is_system = 0 WHERE code = 'price_promo' AND entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE code = 'product')");
    }
};
