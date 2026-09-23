<?php

declare(strict_types=1);

require_once __DIR__ . '/../path.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once FUNCTIONS . 'bootstrap.php';

use Iter\Core\Setup\MigrationRunner;

$direction = $argv[1] ?? 'up';

if (!in_array($direction, ['up', 'down'], true)) {
    fwrite(STDERR, "Uso: php bin/migrate.php [up|down]\n");
    exit(1);
}

(new MigrationRunner())->$direction();
