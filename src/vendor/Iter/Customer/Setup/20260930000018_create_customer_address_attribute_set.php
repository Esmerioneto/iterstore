<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * The address block is built like the customer's: CEP, street, number, district, city and UF stay
 * fixed in the form (CEP lookup, shipping and orders use them); complement, recipient and telephone
 * go into the default set of customer_address, next to the attributes the admin creates.
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            "INSERT INTO eav_attribute_set (entity_type_id, code, label, is_default)
             SELECT entity_type_id, 'default', 'Padrão', 1 FROM eav_entity_type WHERE code = 'customer_address'
             ON CONFLICT DO NOTHING"
        );
        $pdo->exec(
            "INSERT INTO eav_attribute_set_item (attribute_set_id, attribute_id, group_name, sort_order)
             SELECT s.attribute_set_id, a.attribute_id, 'Complemento e contato', a.sort_order
             FROM eav_attribute_set s
             JOIN eav_attribute a ON a.entity_type_id = s.entity_type_id AND a.code IN ('complement', 'recipient', 'telephone')
             JOIN eav_entity_type et ON et.entity_type_id = s.entity_type_id
             WHERE et.code = 'customer_address' AND s.code = 'default'
             ON CONFLICT DO NOTHING"
        );
    }

    public function down(PDO $pdo): void
    {
        // items go with the set (ON DELETE CASCADE)
        $pdo->exec("DELETE FROM eav_attribute_set WHERE code = 'default'
            AND entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE code = 'customer_address')");
    }
};
