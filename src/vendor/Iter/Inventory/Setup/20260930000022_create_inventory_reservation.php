<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * Reservations (INVENTORY-PLAN §7.3, COMMERCE-PLAN delivery 1): an order reserves without touching the
 * physical quantity; salable = physical of the website's sources + SUM(reservations). Append-only:
 * an order inserts -qty, a cancellation or shipment +qty; a row is never changed, so no race can
 * lose one. Rows go only with their product or website (CASCADE).
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inventory_reservation (
                reservation_id BIGSERIAL PRIMARY KEY,
                product_id INT NOT NULL REFERENCES catalog_product (product_id) ON DELETE CASCADE,
                website_id SMALLINT NOT NULL REFERENCES store_website (website_id) ON DELETE CASCADE,
                quantity NUMERIC(12,4) NOT NULL CHECK (quantity <> 0),
                reason VARCHAR(32) NOT NULL,
                reference VARCHAR(64) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW()
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS inventory_reservation_product ON inventory_reservation (product_id, website_id)');
        $pdo->exec(
            "CREATE OR REPLACE FUNCTION inventory_reservation_append_only() RETURNS trigger AS \$\$
            BEGIN
                RAISE EXCEPTION 'inventory_reservation só aceita inclusão: lance uma reserva com a quantidade oposta.';
            END \$\$ LANGUAGE plpgsql"
        );
        $pdo->exec(
            'CREATE OR REPLACE TRIGGER inventory_reservation_append_only
                BEFORE UPDATE ON inventory_reservation
                FOR EACH ROW EXECUTE FUNCTION inventory_reservation_append_only()'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS inventory_reservation');
        $pdo->exec('DROP FUNCTION IF EXISTS inventory_reservation_append_only()');
    }
};
