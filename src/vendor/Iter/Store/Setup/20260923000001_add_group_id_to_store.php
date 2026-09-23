<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        // Magento hierarchy: website -> group -> store. Nullable so stores
        // created before the hierarchy existed (or via GraphQL without a
        // group) stay valid. RESTRICT: a group (or, through the group's
        // CASCADE, a website) that still has stores can't be deleted.
        $pdo->exec(
            'ALTER TABLE store
                ADD COLUMN IF NOT EXISTS group_id SMALLINT
                CONSTRAINT store_group_id_store_group_group_id
                    REFERENCES store_group (group_id) ON DELETE RESTRICT'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS store_group_id ON store (group_id)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE store DROP COLUMN IF EXISTS group_id');
    }
};
