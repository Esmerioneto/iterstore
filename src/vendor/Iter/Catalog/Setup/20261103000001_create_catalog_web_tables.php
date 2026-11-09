<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * WEB-HOME-PLAN §2: the web store's home, per website: the announcement bar, the campaign, and the sections
 * (a category with its photo) with up to 4 cards (subcategories, each with its own photo).
 * Files are names under public/media (web/<2 hex>/<name>.<ext>).
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS catalog_web_home (
            website_id SMALLINT PRIMARY KEY REFERENCES store_website (website_id) ON DELETE CASCADE,
            announcement_enabled SMALLINT NOT NULL DEFAULT 0,
            announcement_text VARCHAR(120) NULL,
            announcement_url VARCHAR(500) NULL,
            campaign_enabled SMALLINT NOT NULL DEFAULT 0,
            campaign_video VARCHAR(255) NULL,
            campaign_image_desktop VARCHAR(255) NULL,
            campaign_image_mobile VARCHAR(255) NULL,
            campaign_title VARCHAR(80) NULL,
            campaign_text VARCHAR(300) NULL,
            campaign_button VARCHAR(30) NULL,
            campaign_url VARCHAR(500) NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT NOW()
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS catalog_web_section (
            section_id SERIAL PRIMARY KEY,
            website_id SMALLINT NOT NULL REFERENCES store_website (website_id) ON DELETE CASCADE,
            category_id INT NOT NULL REFERENCES catalog_category (category_id) ON DELETE CASCADE,
            title VARCHAR(80) NOT NULL DEFAULT '',
            image VARCHAR(255) NOT NULL,
            position INT NOT NULL DEFAULT 0
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS catalog_web_section_website ON catalog_web_section (website_id, position)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS catalog_web_section_category ON catalog_web_section (category_id)');
        $pdo->exec("CREATE TABLE IF NOT EXISTS catalog_web_card (
            card_id SERIAL PRIMARY KEY,
            section_id INT NOT NULL REFERENCES catalog_web_section (section_id) ON DELETE CASCADE,
            category_id INT NOT NULL REFERENCES catalog_category (category_id) ON DELETE CASCADE,
            image VARCHAR(255) NOT NULL,
            position INT NOT NULL DEFAULT 0
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS catalog_web_card_section ON catalog_web_card (section_id, position)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS catalog_web_card_category ON catalog_web_card (category_id)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS catalog_web_card, catalog_web_section, catalog_web_home');
    }
};
