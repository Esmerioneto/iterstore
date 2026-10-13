<?php

declare(strict_types=1);
use Iter\Core\Setup\MigrationInterface;
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec("DO $$ BEGIN
            IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'promotion_coupon_code_format') THEN
                ALTER TABLE promotion_coupon ADD CONSTRAINT promotion_coupon_code_format CHECK (code = UPPER(code) AND code ~ '^[A-Z0-9_-]{1,32}$');
            END IF;
        END $$");
    }
    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE promotion_coupon DROP CONSTRAINT IF EXISTS promotion_coupon_code_format');
    }
};
