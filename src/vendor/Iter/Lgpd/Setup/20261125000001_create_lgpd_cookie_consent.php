<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * LGPD-PLAN §8: the proof of what each browser chose in the store web's cookie notice. One row per choice (a new
 * choice is a new row: the history is the proof). visitor_id is the anonymous ID the store web keeps in its cookie;
 * customer_id only when the visitor was logged in, so the data subject's copy lists it.
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS lgpd_cookie_consent (
            consent_id BIGSERIAL PRIMARY KEY,
            website_id SMALLINT NOT NULL REFERENCES store_website (website_id) ON DELETE CASCADE,
            visitor_id VARCHAR(64) NOT NULL,
            customer_id INT NULL REFERENCES customer_entity (customer_id) ON DELETE SET NULL,
            categories VARCHAR(64) NOT NULL,
            policy_version INT NULL,
            ip VARCHAR(45) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW()
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS lgpd_cookie_consent_visitor ON lgpd_cookie_consent (visitor_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS lgpd_cookie_consent_customer ON lgpd_cookie_consent (customer_id) WHERE customer_id IS NOT NULL');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS lgpd_cookie_consent');
    }
};
