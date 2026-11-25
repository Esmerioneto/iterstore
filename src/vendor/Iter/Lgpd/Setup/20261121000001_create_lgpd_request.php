<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * LGPD-PLAN §6: the data subjects' requests (art. 18) and what became of each, the proof that they were answered.
 * The e-mail is the subject's as it was asked (it stays after an erase: it is the proof, kept for the retention).
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS lgpd_request (
            request_id SERIAL PRIMARY KEY,
            website_id SMALLINT NOT NULL REFERENCES store_website (website_id) ON DELETE CASCADE,
            customer_id INT NULL REFERENCES customer_entity (customer_id) ON DELETE SET NULL,
            email VARCHAR(255) NOT NULL,
            type VARCHAR(16) NOT NULL CHECK (type IN ('access','export','correction','erase')),
            origin VARCHAR(8) NOT NULL CHECK (origin IN ('app','web','admin')),
            status VARCHAR(8) NOT NULL DEFAULT 'open' CHECK (status IN ('open','done','refused')),
            note TEXT NULL,
            admin_user_id INT NULL REFERENCES admin_user (user_id) ON DELETE SET NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            due_at TIMESTAMP NOT NULL DEFAULT NOW() + INTERVAL '15 days',
            done_at TIMESTAMP NULL
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS lgpd_request_open ON lgpd_request (due_at) WHERE status = \'open\'');
        $pdo->exec('CREATE INDEX IF NOT EXISTS lgpd_request_customer ON lgpd_request (customer_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS lgpd_request_email ON lgpd_request (LOWER(email))');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS lgpd_request');
    }
};
