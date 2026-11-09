<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * MARKETING-PLAN delivery 2: "approved" (automatic, or approved in the admin, waiting for the sending hours),
 * a note on why it was skipped, and one e-mail per reason across rules ("Sem comprar" and "Fora do ritmo"
 * share last_order:<id>).
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE marketing_suggestion DROP CONSTRAINT IF EXISTS marketing_suggestion_status_check');
        $pdo->exec("ALTER TABLE marketing_suggestion ADD CONSTRAINT marketing_suggestion_status_check CHECK (status IN ('pending','approved','sent','discarded','expired','skipped'))");
        $pdo->exec('ALTER TABLE marketing_suggestion ADD COLUMN IF NOT EXISTS note VARCHAR(255) NULL');
        $pdo->exec('DROP INDEX IF EXISTS marketing_suggestion_reason');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS marketing_suggestion_reason ON marketing_suggestion (reason_key, lower(email))');
        $pdo->exec("CREATE INDEX IF NOT EXISTS marketing_suggestion_approved ON marketing_suggestion (website_id, created_at) WHERE status = 'approved'");
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec("DELETE FROM marketing_suggestion WHERE status = 'approved'");
        $pdo->exec('DROP INDEX IF EXISTS marketing_suggestion_approved');
        $pdo->exec('DROP INDEX IF EXISTS marketing_suggestion_reason');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS marketing_suggestion_reason ON marketing_suggestion (rule, reason_key, lower(email))');
        $pdo->exec('ALTER TABLE marketing_suggestion DROP COLUMN IF EXISTS note');
        $pdo->exec('ALTER TABLE marketing_suggestion DROP CONSTRAINT IF EXISTS marketing_suggestion_status_check');
        $pdo->exec("ALTER TABLE marketing_suggestion ADD CONSTRAINT marketing_suggestion_status_check CHECK (status IN ('pending','sent','discarded','expired','skipped'))");
    }
};
