<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * TRYON-PLAN §11: one row per generation asked to the provider (never the photos, only their SHA-256), and the
 * product attribute "Provador virtual" (§9.3), a system checkbox in every product set, in the images' group.
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS tryon_request (
            tryon_id SERIAL PRIMARY KEY,
            customer_id INT NOT NULL REFERENCES customer_entity (customer_id) ON DELETE CASCADE,
            website_id SMALLINT NOT NULL REFERENCES store_website (website_id) ON DELETE CASCADE,
            product_id INT NULL REFERENCES catalog_product (product_id) ON DELETE SET NULL,
            provider VARCHAR(32) NOT NULL,
            provider_task VARCHAR(128) NULL,
            model VARCHAR(64) NOT NULL,
            status VARCHAR(16) NOT NULL CHECK (status IN ('processing','success','error')),
            progress SMALLINT NULL,
            image_url TEXT NULL,
            image_expires_at TIMESTAMP NULL,
            cost NUMERIC(10,5) NULL,
            error_code VARCHAR(48) NULL,
            provider_code VARCHAR(64) NULL,
            person_hash CHAR(64) NOT NULL,
            garment_hash CHAR(64) NOT NULL,
            request_json JSONB NULL,
            response_json JSONB NULL,
            hits INT NOT NULL DEFAULT 0,
            polled_at TIMESTAMP NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
            UNIQUE (provider, provider_task)
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS tryon_request_customer ON tryon_request (customer_id, created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS tryon_request_status ON tryon_request (status, created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS tryon_request_same ON tryon_request (customer_id, person_hash, garment_hash, model)');

        $pdo->exec(
            "INSERT INTO eav_attribute (entity_type_id, code, label, input_type, storage_type, is_required, is_visible, is_system, sort_order)
             SELECT entity_type_id, 'tryon_enabled', 'Provador virtual', 'checkbox', 'boolean', 0, 1, 1, 90 FROM eav_entity_type
             WHERE code = 'product' AND NOT EXISTS (SELECT 1 FROM eav_attribute e WHERE e.entity_type_id = eav_entity_type.entity_type_id AND e.code = 'tryon_enabled')"
        );
        // the group of the set's first image field, else its first group
        $pdo->exec(
            "INSERT INTO eav_attribute_set_item (attribute_set_id, attribute_id, group_name, sort_order)
             SELECT s.attribute_set_id, t.attribute_id,
                    COALESCE((SELECT i.group_name FROM eav_attribute_set_item i JOIN eav_attribute a ON a.attribute_id = i.attribute_id
                              WHERE i.attribute_set_id = s.attribute_set_id AND a.input_type = 'image' ORDER BY i.sort_order LIMIT 1),
                             (SELECT i.group_name FROM eav_attribute_set_item i WHERE i.attribute_set_id = s.attribute_set_id ORDER BY i.sort_order LIMIT 1),
                             'Geral'),
                    90
             FROM eav_attribute_set s JOIN eav_attribute t ON t.entity_type_id = s.entity_type_id AND t.code = 'tryon_enabled'
             ON CONFLICT DO NOTHING"
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS tryon_request');
        $pdo->exec("DELETE FROM eav_attribute WHERE code = 'tryon_enabled' AND entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE code = 'product')");
    }
};
