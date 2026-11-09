<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * WEB-HOME-2-PLAN: in Catálogo → Web, each section gets "Mostrar banner" (its category's banners atop its rail),
 * how many products it shows and the ones chosen by hand (in order); the top of the home is the campaign
 * (banner) or a carousel of slides, and the carousel controls (arrows, dots, autoplay seconds) are per website.
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec("ALTER TABLE catalog_web_home
            ADD COLUMN IF NOT EXISTS top_mode VARCHAR(10) NOT NULL DEFAULT 'banner',
            ADD COLUMN IF NOT EXISTS carousel_arrows SMALLINT NOT NULL DEFAULT 1,
            ADD COLUMN IF NOT EXISTS carousel_dots SMALLINT NOT NULL DEFAULT 1,
            ADD COLUMN IF NOT EXISTS carousel_autoplay SMALLINT NOT NULL DEFAULT 5");
        $pdo->exec('ALTER TABLE catalog_web_section
            ADD COLUMN IF NOT EXISTS show_banner SMALLINT NOT NULL DEFAULT 0,
            ADD COLUMN IF NOT EXISTS per_category SMALLINT NOT NULL DEFAULT 4');
        $pdo->exec('CREATE TABLE IF NOT EXISTS catalog_web_section_product (
            section_id INT NOT NULL REFERENCES catalog_web_section (section_id) ON DELETE CASCADE,
            product_id INT NOT NULL REFERENCES catalog_product (product_id) ON DELETE CASCADE,
            position INT NOT NULL DEFAULT 0,
            PRIMARY KEY (section_id, product_id)
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS catalog_web_section_product_product ON catalog_web_section_product (product_id)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS catalog_web_slide (
            slide_id SERIAL PRIMARY KEY,
            website_id SMALLINT NOT NULL REFERENCES store_website (website_id) ON DELETE CASCADE,
            image_desktop VARCHAR(255) NOT NULL,
            image_mobile VARCHAR(255) NULL,
            title VARCHAR(80) NULL,
            url VARCHAR(500) NULL,
            position INT NOT NULL DEFAULT 0
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS catalog_web_slide_website ON catalog_web_slide (website_id, position)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS catalog_web_slide, catalog_web_section_product');
        $pdo->exec('ALTER TABLE catalog_web_section DROP COLUMN IF EXISTS show_banner, DROP COLUMN IF EXISTS per_category');
        $pdo->exec('ALTER TABLE catalog_web_home DROP COLUMN IF EXISTS top_mode, DROP COLUMN IF EXISTS carousel_arrows,
            DROP COLUMN IF EXISTS carousel_dots, DROP COLUMN IF EXISTS carousel_autoplay');
    }
};
