<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        // what already exists goes to the default website (or the first one)
        $default = '(SELECT website_id FROM store_website ORDER BY is_default DESC, sort_order, website_id LIMIT 1)';

        // a category belongs to one website; a subcategory to its parent's
        $pdo->exec('ALTER TABLE catalog_category ADD COLUMN IF NOT EXISTS website_id SMALLINT NULL REFERENCES store_website (website_id) ON DELETE RESTRICT');
        $pdo->exec("UPDATE catalog_category SET website_id = {$default} WHERE website_id IS NULL");
        $pdo->exec('ALTER TABLE catalog_category ALTER COLUMN website_id SET NOT NULL');
        $pdo->exec('CREATE INDEX IF NOT EXISTS catalog_category_website ON catalog_category (website_id)');

        // a product can be in several websites
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS catalog_product_website (
                product_id INT NOT NULL REFERENCES catalog_product (product_id) ON DELETE CASCADE,
                website_id SMALLINT NOT NULL REFERENCES store_website (website_id) ON DELETE RESTRICT,
                PRIMARY KEY (product_id, website_id)
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS catalog_product_website_website ON catalog_product_website (website_id)');
        $pdo->exec("INSERT INTO catalog_product_website (product_id, website_id)
            SELECT product_id, {$default} FROM catalog_product WHERE {$default} IS NOT NULL
            ON CONFLICT DO NOTHING");
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS catalog_product_website');
        $pdo->exec('ALTER TABLE catalog_category DROP COLUMN IF EXISTS website_id');
    }
};
