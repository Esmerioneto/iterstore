<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        // queue and history in one table: the message is stored already rendered, so a retry or a resend is identical
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS mail_queue (
                message_id BIGSERIAL PRIMARY KEY,
                template_code VARCHAR(100),
                website_id SMALLINT REFERENCES store_website (website_id) ON DELETE SET NULL,
                to_email VARCHAR(255) NOT NULL,
                recipients JSONB NOT NULL,
                from_email VARCHAR(255) NOT NULL,
                from_name VARCHAR(255),
                subject VARCHAR(998) NOT NULL,
                body_html TEXT,
                body_text TEXT,
                attachments JSONB NOT NULL DEFAULT \'[]\',
                is_private SMALLINT NOT NULL DEFAULT 0,
                status SMALLINT NOT NULL DEFAULT 0,
                attempts SMALLINT NOT NULL DEFAULT 0,
                last_error TEXT,
                send_after TIMESTAMP NOT NULL DEFAULT NOW(),
                sent_at TIMESTAMP,
                created_at TIMESTAMP NOT NULL DEFAULT NOW()
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS mail_queue_pending ON mail_queue (send_after) WHERE status = 0');
        $pdo->exec('CREATE INDEX IF NOT EXISTS mail_queue_to_email ON mail_queue (to_email, created_at)');

        // only the templates changed in the admin; without a row the module's file applies
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS mail_template (
                code VARCHAR(100) PRIMARY KEY,
                subject VARCHAR(998) NOT NULL,
                body_html TEXT NOT NULL,
                body_text TEXT,
                updated_by INT REFERENCES admin_user (user_id) ON DELETE SET NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT NOW()
            )'
        );
        $pdo->exec(
            'CREATE OR REPLACE TRIGGER mail_template_set_updated_at
                BEFORE UPDATE ON mail_template
                FOR EACH ROW
                EXECUTE FUNCTION set_updated_at()'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS mail_template');
        $pdo->exec('DROP TABLE IF EXISTS mail_queue');
    }
};
