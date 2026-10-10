<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * Checkout Transparente via Orders: the charge is a Mercado Pago order, whose id is text (ORD...),
 * not the old Payments API number.
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE mercadopago_payment ALTER COLUMN external_id TYPE VARCHAR(64)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE mercadopago_payment ALTER COLUMN external_id TYPE BIGINT USING external_id::BIGINT');
    }
};
