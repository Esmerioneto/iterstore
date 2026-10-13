<?php

declare(strict_types=1);
use Iter\Core\Setup\MigrationInterface;
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS promotion_coupon_code_upper ON promotion_coupon (UPPER(code))');
    }
    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP INDEX IF EXISTS promotion_coupon_code_upper');
    }
};
