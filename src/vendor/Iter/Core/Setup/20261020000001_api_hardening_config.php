<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

// API-HARDENING-PLAN: the old default complexity (500, never chosen on purpose) becomes the new one, which counts
// list pages; the introspection saved as on goes back to the default (off). Any other saved value stays.
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec("UPDATE core_config_data SET value = '1500', updated_at = NOW() WHERE path = 'system/api/max_complexity' AND value = '500'");
        $pdo->exec("DELETE FROM core_config_data WHERE path = 'system/api/introspection' AND value = '1'");
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec("UPDATE core_config_data SET value = '500', updated_at = NOW() WHERE path = 'system/api/max_complexity' AND value = '1500'");
    }
};
