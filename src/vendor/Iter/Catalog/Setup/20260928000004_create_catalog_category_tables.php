<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        // a tree: parent_id NULL = top level. RESTRICT: a category with children isn't
        // deleted (CategoryService says so first). Extra fields are EAV (entity "category").
        // url_key is unique in the whole catalog, so the storefront can look a category up by it
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS catalog_category (
                category_id SERIAL PRIMARY KEY,
                parent_id INT NULL REFERENCES catalog_category (category_id) ON DELETE RESTRICT,
                name VARCHAR(255) NOT NULL,
                url_key VARCHAR(255) NOT NULL UNIQUE CHECK (url_key ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
                is_active SMALLINT NOT NULL DEFAULT 1,
                position INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW()
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS catalog_category_parent ON catalog_category (parent_id)');
        $pdo->exec(
            'CREATE OR REPLACE TRIGGER catalog_category_set_updated_at
                BEFORE UPDATE ON catalog_category
                FOR EACH ROW
                EXECUTE FUNCTION set_updated_at()'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS catalog_category_product (
                category_id INT NOT NULL REFERENCES catalog_category (category_id) ON DELETE CASCADE,
                product_id INT NOT NULL REFERENCES catalog_product (product_id) ON DELETE CASCADE,
                PRIMARY KEY (category_id, product_id)
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS catalog_category_product_product ON catalog_category_product (product_id)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS catalog_category_product');
        $pdo->exec('DROP TABLE IF EXISTS catalog_category');
    }
};
