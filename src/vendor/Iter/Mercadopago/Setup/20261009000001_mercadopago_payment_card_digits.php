<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * The card's first six and last four digits (the most a store may keep), read from the token before charging,
 * for the admin to tell which card paid.
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE mercadopago_payment ADD COLUMN IF NOT EXISTS card_first_six VARCHAR(6) NULL, ADD COLUMN IF NOT EXISTS card_last_four VARCHAR(4) NULL');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE mercadopago_payment DROP COLUMN IF EXISTS card_first_six, DROP COLUMN IF EXISTS card_last_four');
    }
};
