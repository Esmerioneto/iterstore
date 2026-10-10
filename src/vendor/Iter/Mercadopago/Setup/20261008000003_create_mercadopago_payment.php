<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * MERCADOPAGO-PLAN: one row per charge sent to the Mercado Pago (an order may have several: a declined
 * card tried again, a new Pix after the old one expired). No card data: the app sends only the token.
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS mercadopago_payment (
                payment_id SERIAL PRIMARY KEY,
                order_id INT NOT NULL REFERENCES sales_order (order_id) ON DELETE CASCADE,
                method VARCHAR(64) NOT NULL,
                external_id BIGINT NOT NULL UNIQUE,
                status VARCHAR(32) NOT NULL,
                status_detail VARCHAR(64) NULL,
                amount NUMERIC(12, 2) NOT NULL,
                installments SMALLINT NULL,
                card_brand VARCHAR(32) NULL,
                qr_code TEXT NULL,
                qr_code_base64 TEXT NULL,
                ticket_url TEXT NULL,
                challenge_url TEXT NULL,
                challenge_creq TEXT NULL,
                expires_at TIMESTAMP NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW()
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS mercadopago_payment_order ON mercadopago_payment (order_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS mercadopago_payment_status ON mercadopago_payment (status, created_at)');
        $pdo->exec(
            'CREATE OR REPLACE TRIGGER mercadopago_payment_set_updated_at
                BEFORE UPDATE ON mercadopago_payment
                FOR EACH ROW EXECUTE FUNCTION set_updated_at()'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS mercadopago_payment');
    }
};
