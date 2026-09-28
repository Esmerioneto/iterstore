<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        // value is what gets stored as the attribute's value; label is display text and may change freely.
        // ponytail: single label column; add eav_attribute_option_value (per locale) when a second language exists
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS eav_attribute_option (
                option_id SERIAL PRIMARY KEY,
                attribute_id INT NOT NULL REFERENCES eav_attribute (attribute_id) ON DELETE CASCADE,
                value VARCHAR(255) NOT NULL,
                label VARCHAR(255) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                is_default SMALLINT NOT NULL DEFAULT 0,
                CONSTRAINT eav_attribute_option_value UNIQUE (attribute_id, value)
            )'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS eav_attribute_option');
    }
};
