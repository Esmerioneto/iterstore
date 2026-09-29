<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

return new class implements MigrationInterface {
    // the options of each variant's combination: RESTRICT keeps an attribute option from
    // being deleted while a variant uses it (Eav reports the FK error as OPTION_IN_USE)
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS catalog_product_variant_option (
                product_id INT NOT NULL REFERENCES catalog_product (product_id) ON DELETE CASCADE,
                option_id INT NOT NULL REFERENCES eav_attribute_option (option_id) ON DELETE RESTRICT,
                PRIMARY KEY (product_id, option_id)
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS catalog_product_variant_option_option ON catalog_product_variant_option (option_id)');
        // existing variants: their grid values (select = eav_value_varchar)
        $pdo->exec('INSERT INTO catalog_product_variant_option (product_id, option_id)
            SELECT p.product_id, o.option_id FROM catalog_product p
            JOIN catalog_product_variant_attribute va ON va.product_id = p.parent_id
            JOIN eav_value_varchar v ON v.entity_id = p.product_id AND v.attribute_id = va.attribute_id
            JOIN eav_attribute_option o ON o.attribute_id = va.attribute_id AND o.value = v.value
            ON CONFLICT DO NOTHING');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS catalog_product_variant_option');
    }
};
