<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/** BANNER-PLAN: "Mostrar banner" of a category in Catálogo → Mobile (its banners atop its section in the app) */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE catalog_mobile_category ADD COLUMN IF NOT EXISTS show_banner SMALLINT NOT NULL DEFAULT 0');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE catalog_mobile_category DROP COLUMN IF EXISTS show_banner');
    }
};
