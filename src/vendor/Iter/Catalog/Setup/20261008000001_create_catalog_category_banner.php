<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * BANNER-PLAN: a category's banners (one image, or a carousel of up to CategoryBannerService::LIMIT),
 * in order. file is relative to public/media/category-banner/.
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS catalog_category_banner (
                banner_id SERIAL PRIMARY KEY,
                category_id INT NOT NULL REFERENCES catalog_category (category_id) ON DELETE CASCADE,
                file VARCHAR(255) NOT NULL,
                position INT NOT NULL DEFAULT 0
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS catalog_category_banner_category ON catalog_category_banner (category_id, position)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS catalog_category_banner');
    }
};
