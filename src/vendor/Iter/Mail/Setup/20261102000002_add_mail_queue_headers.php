<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/** Extra headers of a message (e.g. List-Unsubscribe of the marketing e-mails, MARKETING-PLAN §5) */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec("ALTER TABLE mail_queue ADD COLUMN IF NOT EXISTS headers JSONB NOT NULL DEFAULT '{}'");
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE mail_queue DROP COLUMN IF EXISTS headers');
    }
};
