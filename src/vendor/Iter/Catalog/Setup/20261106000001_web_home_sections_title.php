<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * Catálogo → Web: the heading of the home's section grid (it was "Escolha uma seção" fixed in the store).
 * Empty = the store shows no heading. Existing websites keep the old text.
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE catalog_web_home ADD COLUMN IF NOT EXISTS sections_title VARCHAR(80) NULL');
        $pdo->exec("UPDATE catalog_web_home SET sections_title = 'Escolha uma seção' WHERE sections_title IS NULL");
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE catalog_web_home DROP COLUMN IF EXISTS sections_title');
    }
};
