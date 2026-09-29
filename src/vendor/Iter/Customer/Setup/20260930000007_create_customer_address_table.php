<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

// customer addresses (Brazil): many per customer, at most one default for shipping and one for billing
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS customer_address (
                address_id SERIAL PRIMARY KEY,
                customer_id INT NOT NULL REFERENCES customer_entity (customer_id) ON DELETE CASCADE,
                recipient VARCHAR(255) NULL,
                postcode CHAR(8) NOT NULL,
                street VARCHAR(255) NOT NULL,
                number VARCHAR(20) NOT NULL,
                complement VARCHAR(255) NULL,
                district VARCHAR(255) NOT NULL,
                city VARCHAR(255) NOT NULL,
                region CHAR(2) NOT NULL,
                telephone VARCHAR(20) NULL,
                is_default_shipping SMALLINT NOT NULL DEFAULT 0,
                is_default_billing SMALLINT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW()
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS customer_address_customer ON customer_address (customer_id)');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS customer_address_default_shipping ON customer_address (customer_id) WHERE is_default_shipping = 1');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS customer_address_default_billing ON customer_address (customer_id) WHERE is_default_billing = 1');
        $pdo->exec(
            'CREATE OR REPLACE TRIGGER customer_address_set_updated_at
                BEFORE UPDATE ON customer_address
                FOR EACH ROW
                EXECUTE FUNCTION set_updated_at()'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS customer_address');
    }
};
