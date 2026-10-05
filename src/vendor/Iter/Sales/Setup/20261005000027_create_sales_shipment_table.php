<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * COMMERCE-PLAN delivery 6: the shipment. In this phase it takes the whole order, so its items are the
 * order's; the source's name is copied (a source can be renamed or removed later).
 * ponytail: no sales_shipment_item until partial shipments
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS sales_shipment (
                shipment_id SERIAL PRIMARY KEY,
                order_id INT NOT NULL UNIQUE REFERENCES sales_order (order_id) ON DELETE CASCADE,
                source_id INT NULL REFERENCES inventory_source (source_id) ON DELETE SET NULL,
                source_name VARCHAR(255) NOT NULL,
                carrier VARCHAR(255) NULL,
                tracking_code VARCHAR(255) NULL,
                admin_user_id INT NULL REFERENCES admin_user (user_id) ON DELETE SET NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW()
            )'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS sales_shipment');
    }
};
