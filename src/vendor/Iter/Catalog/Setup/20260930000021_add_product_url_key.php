<?php

declare(strict_types=1);

use Iter\Catalog\Model\CategoryService;
use Iter\Core\Setup\MigrationInterface;

/**
 * Friendly URLs (API-PLAN delivery 13): route(url) finds a product by url_key, as it finds a category.
 * Unique among all products (a product can be in several websites); variants have none, they open
 * through their parent. Existing products get their name's slug, with the id when it repeats.
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE catalog_product ADD COLUMN IF NOT EXISTS url_key VARCHAR(255)');
        $update = $pdo->prepare('UPDATE catalog_product SET url_key = ? WHERE product_id = ?');
        $taken = [];
        foreach ($pdo->query('SELECT product_id, name FROM catalog_product WHERE parent_id IS NULL ORDER BY product_id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = CategoryService::slug($row['name']) ?: 'produto';
            if (isset($taken[$key])) {
                $key .= '-' . $row['product_id'];
            }
            $taken[$key] = true;
            $update->execute([$key, $row['product_id']]);
        }
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS catalog_product_url_key ON catalog_product (url_key)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE catalog_product DROP COLUMN IF EXISTS url_key');
    }
};
