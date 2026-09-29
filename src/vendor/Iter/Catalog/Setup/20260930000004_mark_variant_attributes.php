<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

return new class implements MigrationInterface {
    // attributes already forming a grid keep being offered for new ones
    public function up(PDO $pdo): void
    {
        $pdo->exec('UPDATE eav_attribute SET is_variant = 1 WHERE attribute_id IN (SELECT attribute_id FROM catalog_product_variant_attribute)');
    }

    public function down(PDO $pdo): void
    {
    }
};
