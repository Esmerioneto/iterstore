<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

// a fresh install has no website, and later migrations (Catalog/Inventory add_website_*) give existing rows
// the default one with NOT NULL; only seeds when the table is empty, so an existing install keeps its websites
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec("INSERT INTO store_website (code, name, sort_order, is_default)
            SELECT 'base', 'Site principal', 0, 1 WHERE NOT EXISTS (SELECT 1 FROM store_website)");
    }

    public function down(PDO $pdo): void
    {
        // the website may have data by now; deleting it is left to the admin
    }
};
