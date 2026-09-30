<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * Case-insensitive lookup of a text value by attribute (customer search by e-mail, name...).
 * ponytail: ILIKE '%x%' still scans; pg_trgm (CUSTOMER-EAV-PLAN §8) when the list gets slow.
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec('CREATE INDEX IF NOT EXISTS eav_value_varchar_lower ON eav_value_varchar (attribute_id, LOWER(value))');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP INDEX IF EXISTS eav_value_varchar_lower');
    }
};
