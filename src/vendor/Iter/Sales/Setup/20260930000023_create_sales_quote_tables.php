<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * COMMERCE-PLAN delivery 2: the cart. A guest's is found by the sha256 of its cartId (the id itself
 * is only in the storefront); a customer has one active cart per website. Prices aren't stored: they
 * are read when the cart is read, so a price change reaches the cart.
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS sales_quote (
                quote_id SERIAL PRIMARY KEY,
                token_hash CHAR(64) NULL UNIQUE,
                customer_id INT NULL REFERENCES customer_entity (customer_id) ON DELETE CASCADE,
                website_id SMALLINT NOT NULL REFERENCES store_website (website_id) ON DELETE CASCADE,
                is_active SMALLINT NOT NULL DEFAULT 1,
                email VARCHAR(255) NULL,
                firstname VARCHAR(255) NULL,
                lastname VARCHAR(255) NULL,
                taxvat VARCHAR(14) NULL,
                shipping_address JSONB NULL,
                billing_address JSONB NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
                CHECK ((token_hash IS NULL) <> (customer_id IS NULL))
            )'
        );
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS sales_quote_customer_active ON sales_quote (customer_id, website_id) WHERE is_active = 1 AND customer_id IS NOT NULL');
        $pdo->exec('CREATE INDEX IF NOT EXISTS sales_quote_guest_updated ON sales_quote (updated_at) WHERE customer_id IS NULL');
        $pdo->exec(
            'CREATE OR REPLACE TRIGGER sales_quote_set_updated_at
                BEFORE UPDATE ON sales_quote
                FOR EACH ROW EXECUTE FUNCTION set_updated_at()'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS sales_quote_item (
                item_id SERIAL PRIMARY KEY,
                quote_id INT NOT NULL REFERENCES sales_quote (quote_id) ON DELETE CASCADE,
                product_id INT NOT NULL REFERENCES catalog_product (product_id) ON DELETE CASCADE,
                quantity NUMERIC(12,4) NOT NULL CHECK (quantity > 0),
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                UNIQUE (quote_id, product_id)
            )'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS sales_quote_item');
        $pdo->exec('DROP TABLE IF EXISTS sales_quote');
    }
};
