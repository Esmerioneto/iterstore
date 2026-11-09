<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * MARKETING-PLAN §5: the suggestions, the consent ("Aceita receber ofertas por e-mail", a system checkbox in
 * every customer set, off by default) with its history, and the visitors who unsubscribed.
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            "INSERT INTO eav_attribute (entity_type_id, code, label, input_type, storage_type, is_required, is_visible, is_system, sort_order)
             SELECT entity_type_id, 'marketing_optin', 'Aceita receber ofertas por e-mail', 'checkbox', 'boolean', 0, 1, 1, 91 FROM eav_entity_type
             WHERE code = 'customer' AND NOT EXISTS (SELECT 1 FROM eav_attribute e WHERE e.entity_type_id = eav_entity_type.entity_type_id AND e.code = 'marketing_optin')"
        );
        $pdo->exec(
            "INSERT INTO eav_attribute_set_item (attribute_set_id, attribute_id, group_name, sort_order)
             SELECT s.attribute_set_id, t.attribute_id,
                    COALESCE((SELECT i.group_name FROM eav_attribute_set_item i WHERE i.attribute_set_id = s.attribute_set_id ORDER BY i.sort_order LIMIT 1), 'Geral'),
                    91
             FROM eav_attribute_set s JOIN eav_attribute t ON t.entity_type_id = s.entity_type_id AND t.code = 'marketing_optin'
             ON CONFLICT DO NOTHING"
        );

        // every change of the consent, whoever made it (admin, app, unsubscribe link): the LGPD proof
        $pdo->exec("CREATE TABLE IF NOT EXISTS marketing_consent (
            consent_id BIGSERIAL PRIMARY KEY,
            customer_id INT NOT NULL REFERENCES customer_entity (customer_id) ON DELETE CASCADE,
            optin SMALLINT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW()
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS marketing_consent_customer ON marketing_consent (customer_id, created_at)');
        // a trigger, not app code: the EAV save upserts every value of the set, from many places
        $pdo->exec("CREATE OR REPLACE FUNCTION marketing_consent_log() RETURNS trigger AS \$\$
            DECLARE
                attr INT := (SELECT a.attribute_id FROM eav_attribute a JOIN eav_entity_type t ON t.entity_type_id = a.entity_type_id
                             WHERE t.code = 'customer' AND a.code = 'marketing_optin');
                old_value SMALLINT := CASE WHEN TG_OP = 'INSERT' THEN 0 ELSE OLD.value END;
                new_value SMALLINT := CASE WHEN TG_OP = 'DELETE' THEN 0 ELSE NEW.value END;
                customer INT := CASE WHEN TG_OP = 'DELETE' THEN OLD.entity_id ELSE NEW.entity_id END;
            BEGIN
                IF COALESCE(NEW.attribute_id, OLD.attribute_id) = attr AND (old_value <> 0) <> (new_value <> 0) THEN
                    -- the customer is deleted before its values: nothing to record then
                    INSERT INTO marketing_consent (customer_id, optin)
                    SELECT customer, CASE WHEN new_value <> 0 THEN 1 ELSE 0 END WHERE EXISTS (SELECT 1 FROM customer_entity WHERE customer_id = customer);
                END IF;
                RETURN NULL;
            END
        \$\$ LANGUAGE plpgsql");
        $pdo->exec('DROP TRIGGER IF EXISTS marketing_consent_log ON eav_value_boolean');
        $pdo->exec('CREATE TRIGGER marketing_consent_log AFTER INSERT OR UPDATE OR DELETE ON eav_value_boolean FOR EACH ROW EXECUTE FUNCTION marketing_consent_log()');

        // visitors (no customer record) who used the unsubscribe link
        $pdo->exec("CREATE TABLE IF NOT EXISTS marketing_unsubscribe (
            website_id SMALLINT NOT NULL REFERENCES store_website (website_id) ON DELETE CASCADE,
            email VARCHAR(255) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            PRIMARY KEY (website_id, email)
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS marketing_suggestion (
            suggestion_id BIGSERIAL PRIMARY KEY,
            website_id SMALLINT NOT NULL REFERENCES store_website (website_id) ON DELETE CASCADE,
            customer_id INT NULL REFERENCES customer_entity (customer_id) ON DELETE SET NULL,
            email VARCHAR(255) NOT NULL,
            rule VARCHAR(32) NOT NULL,
            reason_key VARCHAR(64) NOT NULL,
            vars JSONB NOT NULL DEFAULT '{}',
            status VARCHAR(12) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','sent','discarded','expired','skipped')),
            mail_id BIGINT NULL REFERENCES mail_queue (message_id) ON DELETE SET NULL,
            admin_user_id INT NULL REFERENCES admin_user (user_id) ON DELETE SET NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            decided_at TIMESTAMP NULL,
            sent_at TIMESTAMP NULL
        )");
        // one e-mail per reason, even if the cron runs twice
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS marketing_suggestion_reason ON marketing_suggestion (rule, reason_key, lower(email))');
        // the per-customer limit
        $pdo->exec('CREATE INDEX IF NOT EXISTS marketing_suggestion_sent ON marketing_suggestion (lower(email), sent_at) WHERE sent_at IS NOT NULL');
        // the menu's counter
        $pdo->exec("CREATE INDEX IF NOT EXISTS marketing_suggestion_pending ON marketing_suggestion (website_id) WHERE status = 'pending'");
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS marketing_suggestion, marketing_unsubscribe, marketing_consent');
        $pdo->exec('DROP TRIGGER IF EXISTS marketing_consent_log ON eav_value_boolean');
        $pdo->exec('DROP FUNCTION IF EXISTS marketing_consent_log()');
        $pdo->exec("DELETE FROM eav_attribute WHERE code = 'marketing_optin' AND entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE code = 'customer')");
    }
};
