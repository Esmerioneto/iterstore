<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * "Filtrável" becomes "Usar nos filtros da loja" (products(filters) + aggregations): only option lists
 * and Sim/Não can be filtered, so the flag is cleared on the other types. Not reversible (the old
 * flag did nothing).
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec("UPDATE eav_attribute SET is_filterable = 0 WHERE is_filterable = 1 AND input_type NOT IN ('select', 'multiselect', 'radio', 'checkbox')");
    }

    public function down(PDO $pdo): void
    {
    }
};
