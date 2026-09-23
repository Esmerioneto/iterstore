<?php

declare(strict_types=1);

namespace Iter\Core\Setup;

interface MigrationInterface
{
    public function up(\PDO $pdo): void;

    public function down(\PDO $pdo): void;
}
