<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * COMMERCE-PLAN delivery 3: the table rate (a price per postcode range, optionally per weight) and the
 * product weight, a system attribute (kg) in the default product set, used by the table and later
 * by Melhor Envio.
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS shipping_table_rate (
                rate_id SERIAL PRIMARY KEY,
                website_id SMALLINT NOT NULL REFERENCES store_website (website_id) ON DELETE CASCADE,
                postcode_from CHAR(8) NOT NULL,
                postcode_to CHAR(8) NOT NULL,
                weight_to NUMERIC(12,4) NULL,
                price NUMERIC(12,2) NOT NULL CHECK (price >= 0),
                days SMALLINT NOT NULL DEFAULT 0 CHECK (days >= 0),
                CHECK (postcode_from <= postcode_to)
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS shipping_table_rate_website ON shipping_table_rate (website_id, postcode_from, postcode_to)');
        $pdo->exec(
            "INSERT INTO eav_attribute (entity_type_id, code, label, input_type, storage_type, is_required, is_visible, is_system, sort_order)
             SELECT entity_type_id, 'weight', 'Peso (kg)', 'number', 'decimal', 0, 1, 1, 15 FROM eav_entity_type WHERE code = 'product'
             ON CONFLICT (entity_type_id, code) DO NOTHING"
        );
        $pdo->exec(
            "INSERT INTO eav_attribute_set_item (attribute_set_id, attribute_id, group_name, sort_order)
             SELECT s.attribute_set_id, a.attribute_id, 'Geral', a.sort_order
             FROM eav_attribute_set s
             JOIN eav_attribute a ON a.entity_type_id = s.entity_type_id AND a.code = 'weight'
             JOIN eav_entity_type et ON et.entity_type_id = s.entity_type_id
             WHERE et.code = 'product' AND s.code = 'default'
             ON CONFLICT DO NOTHING"
        );
        $pdo->exec('ALTER TABLE sales_quote ADD COLUMN IF NOT EXISTS shipping_method VARCHAR(64) NULL');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE sales_quote DROP COLUMN IF EXISTS shipping_method');
        $pdo->exec("DELETE FROM eav_attribute WHERE code = 'weight' AND entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE code = 'product')");
        $pdo->exec('DROP TABLE IF EXISTS shipping_table_rate');
    }
};
