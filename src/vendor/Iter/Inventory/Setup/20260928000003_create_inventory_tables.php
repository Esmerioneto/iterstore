<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        // a source is a physical place stock ships from (warehouse, DC, shop)
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS inventory_source (
                source_id SERIAL PRIMARY KEY,
                code VARCHAR(64) NOT NULL UNIQUE CHECK (code ~ '^[a-z][a-z0-9_]*$'),
                name VARCHAR(255) NOT NULL,
                is_enabled SMALLINT NOT NULL DEFAULT 1,
                description TEXT NULL,
                postcode VARCHAR(16) NULL,
                city VARCHAR(128) NULL,
                region VARCHAR(128) NULL,
                country_code CHAR(2) NOT NULL DEFAULT 'BR',
                priority INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW()
            )"
        );
        $pdo->exec(
            'CREATE OR REPLACE TRIGGER inventory_source_set_updated_at
                BEFORE UPDATE ON inventory_source
                FOR EACH ROW
                EXECUTE FUNCTION set_updated_at()'
        );
        $pdo->exec("INSERT INTO inventory_source (code, name) VALUES ('default', 'Origem padrão') ON CONFLICT (code) DO NOTHING");

        // no stored balance: the salable quantity is summed on read, so it can't go stale.
        // RESTRICT: a source is only removed once empty (SourceService checks it first);
        // CASCADE: removing the product removes its stock, no code needed
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inventory_source_item (
                source_id INT NOT NULL REFERENCES inventory_source (source_id) ON DELETE RESTRICT,
                product_id INT NOT NULL REFERENCES catalog_product (product_id) ON DELETE CASCADE,
                quantity NUMERIC(12,4) NOT NULL DEFAULT 0 CHECK (quantity >= 0),
                status SMALLINT NOT NULL DEFAULT 1,
                updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
                PRIMARY KEY (source_id, product_id)
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS inventory_source_item_product ON inventory_source_item (product_id)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS inventory_source_item');
        $pdo->exec('DROP TABLE IF EXISTS inventory_source');
    }
};
