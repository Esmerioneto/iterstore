<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/** Campaign rules, coupons, quote code and immutable order discount snapshots. */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS promotion (
            promotion_id SERIAL PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            is_active SMALLINT NOT NULL DEFAULT 1,
            application_type VARCHAR(16) NOT NULL CHECK (application_type IN ('automatic','coupon')),
            discount_type VARCHAR(16) NOT NULL CHECK (discount_type IN ('fixed','percent')),
            discount_amount NUMERIC(12,2) NOT NULL CHECK (discount_amount > 0),
            minimum_subtotal NUMERIC(12,2) NULL CHECK (minimum_subtotal IS NULL OR minimum_subtotal >= 0),
            maximum_subtotal NUMERIC(12,2) NULL CHECK (maximum_subtotal IS NULL OR maximum_subtotal >= 0),
            starts_at TIMESTAMP NULL,
            ends_at TIMESTAMP NULL,
            is_accumulative SMALLINT NOT NULL DEFAULT 0,
            priority INT NOT NULL DEFAULT 0,
            applies_to_sale_price SMALLINT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
            CHECK (ends_at IS NULL OR starts_at IS NULL OR ends_at >= starts_at),
            CHECK (discount_type <> 'percent' OR discount_amount <= 100)
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS promotion_active_dates ON promotion (is_active, starts_at, ends_at, priority)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS promotion_website (promotion_id INT NOT NULL REFERENCES promotion (promotion_id) ON DELETE CASCADE, website_id SMALLINT NOT NULL REFERENCES store_website (website_id) ON DELETE CASCADE, PRIMARY KEY (promotion_id, website_id))');
        $pdo->exec('CREATE TABLE IF NOT EXISTS promotion_product (promotion_id INT NOT NULL REFERENCES promotion (promotion_id) ON DELETE CASCADE, product_id INT NOT NULL REFERENCES catalog_product (product_id) ON DELETE CASCADE, PRIMARY KEY (promotion_id, product_id))');
        $pdo->exec('CREATE TABLE IF NOT EXISTS promotion_category (promotion_id INT NOT NULL REFERENCES promotion (promotion_id) ON DELETE CASCADE, category_id INT NOT NULL REFERENCES catalog_category (category_id) ON DELETE CASCADE, PRIMARY KEY (promotion_id, category_id))');
        $pdo->exec('CREATE TABLE IF NOT EXISTS promotion_attribute_set (promotion_id INT NOT NULL REFERENCES promotion (promotion_id) ON DELETE CASCADE, attribute_set_id INT NOT NULL REFERENCES eav_attribute_set (attribute_set_id) ON DELETE CASCADE, PRIMARY KEY (promotion_id, attribute_set_id))');
        $pdo->exec("CREATE TABLE IF NOT EXISTS promotion_coupon (
            coupon_id SERIAL PRIMARY KEY,
            promotion_id INT NOT NULL REFERENCES promotion (promotion_id) ON DELETE CASCADE,
            code VARCHAR(32) NOT NULL UNIQUE,
            is_active SMALLINT NOT NULL DEFAULT 1,
            usage_limit INT NULL CHECK (usage_limit IS NULL OR usage_limit >= 1),
            usage_per_customer INT NULL CHECK (usage_per_customer IS NULL OR usage_per_customer >= 1),
            created_at TIMESTAMP NOT NULL DEFAULT NOW()
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS promotion_coupon_promotion ON promotion_coupon (promotion_id)');
        $pdo->exec("CREATE TABLE IF NOT EXISTS promotion_coupon_usage (
            usage_id SERIAL PRIMARY KEY,
            coupon_id INT NOT NULL REFERENCES promotion_coupon (coupon_id) ON DELETE RESTRICT,
            order_id INT NOT NULL REFERENCES sales_order (order_id) ON DELETE CASCADE,
            customer_id INT NULL REFERENCES customer_entity (customer_id) ON DELETE SET NULL,
            discount_amount NUMERIC(12,2) NOT NULL DEFAULT 0,
            status VARCHAR(16) NOT NULL DEFAULT 'used' CHECK (status IN ('used','released')),
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            UNIQUE (coupon_id, order_id)
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS promotion_coupon_usage_customer ON promotion_coupon_usage (coupon_id, customer_id, status)');
        $pdo->exec('ALTER TABLE sales_quote ADD COLUMN IF NOT EXISTS coupon_code VARCHAR(32) NULL');
        $pdo->exec('ALTER TABLE sales_order ADD COLUMN IF NOT EXISTS discount_amount NUMERIC(12,2) NOT NULL DEFAULT 0');
        $pdo->exec('ALTER TABLE sales_order ADD COLUMN IF NOT EXISTS coupon_code VARCHAR(32) NULL');
        $pdo->exec('ALTER TABLE sales_order_item ADD COLUMN IF NOT EXISTS discount_amount NUMERIC(12,2) NOT NULL DEFAULT 0');
        $pdo->exec("CREATE TABLE IF NOT EXISTS sales_order_discount (
            discount_id SERIAL PRIMARY KEY,
            order_id INT NOT NULL REFERENCES sales_order (order_id) ON DELETE CASCADE,
            promotion_id INT NULL REFERENCES promotion (promotion_id) ON DELETE SET NULL,
            promotion_name VARCHAR(255) NOT NULL,
            coupon_code VARCHAR(32) NULL,
            discount_amount NUMERIC(12,2) NOT NULL CHECK (discount_amount >= 0),
            details JSONB NOT NULL DEFAULT '{}'
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS sales_order_discount_order ON sales_order_discount (order_id)');
    }

    public function down(PDO $pdo): void
    {
        // Reversible for development only. This removes discount history; never run down on a live store.
        $pdo->exec('DROP TABLE IF EXISTS sales_order_discount');
        $pdo->exec('ALTER TABLE sales_order_item DROP COLUMN IF EXISTS discount_amount');
        $pdo->exec('ALTER TABLE sales_order DROP COLUMN IF EXISTS coupon_code');
        $pdo->exec('ALTER TABLE sales_order DROP COLUMN IF EXISTS discount_amount');
        $pdo->exec('ALTER TABLE sales_quote DROP COLUMN IF EXISTS coupon_code');
        $pdo->exec('DROP TABLE IF EXISTS promotion_coupon_usage');
        $pdo->exec('DROP TABLE IF EXISTS promotion_coupon');
        $pdo->exec('DROP TABLE IF EXISTS promotion_attribute_set');
        $pdo->exec('DROP TABLE IF EXISTS promotion_category');
        $pdo->exec('DROP TABLE IF EXISTS promotion_product');
        $pdo->exec('DROP TABLE IF EXISTS promotion_website');
        $pdo->exec('DROP TABLE IF EXISTS promotion');
    }
};
