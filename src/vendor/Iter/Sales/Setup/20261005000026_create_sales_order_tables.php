<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * COMMERCE-PLAN delivery 5: the order. Everything the customer saw is copied (names, prices, addresses,
 * shipping, payment instructions), so the order doesn't change when the catalog or the account does.
 * quote_id is unique: one cart becomes one order (placeOrder is idempotent).
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        // the number per website: the row lock of the upsert keeps it sequential and gapless (a rolled back order gives its number back)
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS sales_order_sequence (
                website_id SMALLINT PRIMARY KEY REFERENCES store_website (website_id) ON DELETE CASCADE,
                last_value INT NOT NULL
            )'
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS sales_order (
                order_id SERIAL PRIMARY KEY,
                increment_id VARCHAR(32) NOT NULL,
                website_id SMALLINT NOT NULL REFERENCES store_website (website_id),
                quote_id INT NULL UNIQUE REFERENCES sales_quote (quote_id) ON DELETE SET NULL,
                customer_id INT NULL REFERENCES customer_entity (customer_id) ON DELETE SET NULL,
                email VARCHAR(255) NOT NULL,
                firstname VARCHAR(255) NOT NULL,
                lastname VARCHAR(255) NOT NULL,
                taxvat VARCHAR(14) NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'pending_payment',
                subtotal NUMERIC(12,2) NOT NULL,
                shipping_amount NUMERIC(12,2) NOT NULL DEFAULT 0,
                grand_total NUMERIC(12,2) NOT NULL,
                shipping_method VARCHAR(64) NULL,
                shipping_label VARCHAR(255) NULL,
                shipping_days SMALLINT NULL,
                payment_method VARCHAR(64) NULL,
                payment_label VARCHAR(255) NULL,
                payment_instructions TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
                UNIQUE (website_id, increment_id)
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS sales_order_customer ON sales_order (customer_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS sales_order_status ON sales_order (website_id, status, created_at)');
        $pdo->exec(
            'CREATE OR REPLACE TRIGGER sales_order_set_updated_at
                BEFORE UPDATE ON sales_order
                FOR EACH ROW EXECUTE FUNCTION set_updated_at()'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS sales_order_item (
                item_id SERIAL PRIMARY KEY,
                order_id INT NOT NULL REFERENCES sales_order (order_id) ON DELETE CASCADE,
                product_id INT NULL REFERENCES catalog_product (product_id) ON DELETE SET NULL,
                sku VARCHAR(64) NOT NULL,
                name VARCHAR(255) NOT NULL,
                options VARCHAR(255) NULL,
                quantity NUMERIC(12,4) NOT NULL,
                price NUMERIC(12,2) NOT NULL,
                row_total NUMERIC(12,2) NOT NULL
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS sales_order_item_order ON sales_order_item (order_id)');
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS sales_order_address (
                order_id INT NOT NULL REFERENCES sales_order (order_id) ON DELETE CASCADE,
                type VARCHAR(8) NOT NULL CHECK (type IN ('shipping', 'billing')),
                postcode VARCHAR(8) NULL,
                street VARCHAR(255) NULL,
                number VARCHAR(255) NULL,
                complement VARCHAR(255) NULL,
                district VARCHAR(255) NULL,
                city VARCHAR(255) NULL,
                region VARCHAR(255) NULL,
                recipient VARCHAR(255) NULL,
                telephone VARCHAR(255) NULL,
                attributes JSONB NOT NULL DEFAULT '{}',
                PRIMARY KEY (order_id, type)
            )"
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS sales_order_status_history (
                history_id SERIAL PRIMARY KEY,
                order_id INT NOT NULL REFERENCES sales_order (order_id) ON DELETE CASCADE,
                status VARCHAR(32) NOT NULL,
                comment TEXT NULL,
                is_customer_notified SMALLINT NOT NULL DEFAULT 0,
                admin_user_id INT NULL REFERENCES admin_user (user_id) ON DELETE SET NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW()
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS sales_order_status_history_order ON sales_order_status_history (order_id)');
    }

    public function down(PDO $pdo): void
    {
        foreach (['sales_order_status_history', 'sales_order_address', 'sales_order_item', 'sales_order', 'sales_order_sequence'] as $table) {
            $pdo->exec("DROP TABLE IF EXISTS {$table}");
        }
    }
};
