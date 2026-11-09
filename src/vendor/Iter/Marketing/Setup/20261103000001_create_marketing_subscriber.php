<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * NEWSLETTER-PLAN §2: who left the e-mail in the store's "Não perca nada" (double opt-in), and the consent
 * history taking those people too: a consent row is of a customer or of a subscriber, with its origin.
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS marketing_subscriber (
            subscriber_id SERIAL PRIMARY KEY,
            website_id SMALLINT NOT NULL REFERENCES store_website (website_id) ON DELETE CASCADE,
            email VARCHAR(255) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','confirmed','unsubscribed')),
            token_hash CHAR(64) NULL,
            token_expires_at TIMESTAMP NULL,
            confirm_sent_at TIMESTAMP NULL,
            ip VARCHAR(64) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            confirmed_at TIMESTAMP NULL,
            unsubscribed_at TIMESTAMP NULL
        )");
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS marketing_subscriber_email ON marketing_subscriber (website_id, lower(email))');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS marketing_subscriber_token ON marketing_subscriber (token_hash) WHERE token_hash IS NOT NULL');

        $pdo->exec('ALTER TABLE marketing_consent ALTER COLUMN customer_id DROP NOT NULL');
        $pdo->exec('ALTER TABLE marketing_consent ADD COLUMN IF NOT EXISTS subscriber_id INT NULL REFERENCES marketing_subscriber (subscriber_id) ON DELETE CASCADE');
        // customer = the customer's form, the app or the unsubscribe link (the trigger); newsletter = this plan
        $pdo->exec("ALTER TABLE marketing_consent ADD COLUMN IF NOT EXISTS origin VARCHAR(16) NOT NULL DEFAULT 'customer'");
        $pdo->exec('ALTER TABLE marketing_consent DROP CONSTRAINT IF EXISTS marketing_consent_whose');
        $pdo->exec('ALTER TABLE marketing_consent ADD CONSTRAINT marketing_consent_whose CHECK (customer_id IS NOT NULL OR subscriber_id IS NOT NULL)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS marketing_consent_subscriber ON marketing_consent (subscriber_id) WHERE subscriber_id IS NOT NULL');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DELETE FROM marketing_consent WHERE customer_id IS NULL');
        $pdo->exec('ALTER TABLE marketing_consent DROP CONSTRAINT IF EXISTS marketing_consent_whose');
        $pdo->exec('ALTER TABLE marketing_consent DROP COLUMN IF EXISTS origin');
        $pdo->exec('ALTER TABLE marketing_consent DROP COLUMN IF EXISTS subscriber_id');
        $pdo->exec('ALTER TABLE marketing_consent ALTER COLUMN customer_id SET NOT NULL');
        $pdo->exec('DROP TABLE IF EXISTS marketing_subscriber');
    }
};
