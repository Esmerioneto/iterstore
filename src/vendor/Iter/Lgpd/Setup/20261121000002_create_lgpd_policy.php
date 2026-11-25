<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * LGPD-PLAN §7: the privacy policy per website, with versions, and who accepted which one.
 * One draft per website (version NULL) and the published versions (1, 2…), never changed: a published text is
 * what the customers accepted. A trigger refuses changing a published version; deleting one that someone accepted
 * is refused by the accepts' foreign key (the website's own delete still cascades through an unaccepted one).
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS lgpd_policy (
            policy_id SERIAL PRIMARY KEY,
            website_id SMALLINT NOT NULL REFERENCES store_website (website_id) ON DELETE CASCADE,
            version INT NULL CHECK (version IS NULL OR version >= 1),
            content TEXT NOT NULL DEFAULT '',
            admin_user_id INT NULL REFERENCES admin_user (user_id) ON DELETE SET NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
            published_at TIMESTAMP NULL,
            CHECK ((version IS NULL) = (published_at IS NULL))
        )");
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS lgpd_policy_version ON lgpd_policy (website_id, version) WHERE version IS NOT NULL');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS lgpd_policy_draft ON lgpd_policy (website_id) WHERE version IS NULL');
        $pdo->exec("CREATE OR REPLACE FUNCTION lgpd_policy_frozen() RETURNS trigger AS \$\$
            BEGIN
                IF OLD.version IS NOT NULL THEN
                    RAISE EXCEPTION 'lgpd_policy: a versão % publicada não pode ser alterada', OLD.version;
                END IF;
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END \$\$ LANGUAGE plpgsql");
        $pdo->exec('DROP TRIGGER IF EXISTS lgpd_policy_frozen ON lgpd_policy');
        $pdo->exec('CREATE TRIGGER lgpd_policy_frozen BEFORE UPDATE ON lgpd_policy FOR EACH ROW EXECUTE FUNCTION lgpd_policy_frozen()');

        $pdo->exec("CREATE TABLE IF NOT EXISTS lgpd_policy_accept (
            accept_id BIGSERIAL PRIMARY KEY,
            customer_id INT NOT NULL REFERENCES customer_entity (customer_id) ON DELETE CASCADE,
            policy_id INT NOT NULL REFERENCES lgpd_policy (policy_id), -- NO ACTION: checked at the end, after a website's cascade took its customers' accepts
            origin VARCHAR(8) NOT NULL CHECK (origin IN ('app','web')),
            ip VARCHAR(45) NULL,
            accepted_at TIMESTAMP NOT NULL DEFAULT NOW(),
            UNIQUE (customer_id, policy_id)
        )");
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS lgpd_policy_accept');
        $pdo->exec('DROP TABLE IF EXISTS lgpd_policy');
        $pdo->exec('DROP FUNCTION IF EXISTS lgpd_policy_frozen()');
    }
};
