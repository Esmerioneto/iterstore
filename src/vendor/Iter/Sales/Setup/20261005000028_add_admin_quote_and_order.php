<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * ADMINSALES-PLAN delivery 1 (here in Sales, which reads the column; Iter_Adminsales uses it): a cart the admin builds (admin_user_id) has a cartId like a guest's, and
 * also the customer when the sale is for one; it stays apart from the cart the customer builds in the
 * store (the one-active-cart rule is only for the store's). The order keeps who sold it.
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE sales_quote ADD COLUMN IF NOT EXISTS admin_user_id INT NULL REFERENCES admin_user (user_id) ON DELETE CASCADE');
        $pdo->exec('ALTER TABLE sales_quote DROP CONSTRAINT IF EXISTS sales_quote_check');
        $pdo->exec('ALTER TABLE sales_quote ADD CONSTRAINT sales_quote_check CHECK (CASE WHEN admin_user_id IS NULL
            THEN (token_hash IS NULL) <> (customer_id IS NULL) ELSE token_hash IS NOT NULL END)');
        $pdo->exec('DROP INDEX IF EXISTS sales_quote_customer_active');
        $pdo->exec('CREATE UNIQUE INDEX sales_quote_customer_active ON sales_quote (customer_id, website_id)
            WHERE is_active = 1 AND customer_id IS NOT NULL AND admin_user_id IS NULL');
        $pdo->exec('CREATE INDEX IF NOT EXISTS sales_quote_admin ON sales_quote (admin_user_id) WHERE admin_user_id IS NOT NULL');
        $pdo->exec('ALTER TABLE sales_order ADD COLUMN IF NOT EXISTS admin_user_id INT NULL REFERENCES admin_user (user_id) ON DELETE SET NULL');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE sales_order DROP COLUMN IF EXISTS admin_user_id');
        $pdo->exec('DELETE FROM sales_quote WHERE admin_user_id IS NOT NULL');
        $pdo->exec('DROP INDEX IF EXISTS sales_quote_admin');
        $pdo->exec('DROP INDEX IF EXISTS sales_quote_customer_active');
        $pdo->exec('CREATE UNIQUE INDEX sales_quote_customer_active ON sales_quote (customer_id, website_id) WHERE is_active = 1 AND customer_id IS NOT NULL');
        $pdo->exec('ALTER TABLE sales_quote DROP CONSTRAINT IF EXISTS sales_quote_check');
        $pdo->exec('ALTER TABLE sales_quote ADD CONSTRAINT sales_quote_check CHECK ((token_hash IS NULL) <> (customer_id IS NULL))');
        $pdo->exec('ALTER TABLE sales_quote DROP COLUMN IF EXISTS admin_user_id');
    }
};
