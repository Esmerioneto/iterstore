<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * "Valor único": a value of the attribute can't repeat within a scope (0 = global; the
 * customer's e-mail and CPF/CNPJ will use the website). ValueService keeps eav_unique_value
 * in the same transaction as the value; the unique index stops concurrent saves and the
 * deferred trigger stops a value written by SQL without its key.
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE eav_attribute ADD COLUMN IF NOT EXISTS is_unique SMALLINT NOT NULL DEFAULT 0');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS eav_unique_value (
                attribute_id INT NOT NULL REFERENCES eav_attribute (attribute_id) ON DELETE CASCADE,
                entity_id INT NOT NULL,
                scope_id INT NOT NULL DEFAULT 0,
                value_key VARCHAR(255) NOT NULL,
                PRIMARY KEY (attribute_id, entity_id),
                CONSTRAINT eav_unique_value_key UNIQUE (attribute_id, scope_id, value_key)
            )'
        );

        $pdo->exec(<<<'SQL'
            CREATE OR REPLACE FUNCTION eav_value_unique_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM eav_attribute WHERE attribute_id = NEW.attribute_id AND is_unique = 1)
                   AND NOT EXISTS (SELECT 1 FROM eav_unique_value WHERE attribute_id = NEW.attribute_id
                                   AND entity_id = NEW.entity_id AND value_key = lower(NEW.value)) THEN
                    RAISE EXCEPTION 'Valor de atributo único gravado sem a chave em eav_unique_value (atributo %, registro %).', NEW.attribute_id, NEW.entity_id
                        USING ERRCODE = 'unique_violation';
                END IF;
                RETURN NULL;
            END $$
            SQL);
        $pdo->exec('DROP TRIGGER IF EXISTS eav_value_unique_guard ON eav_value_varchar');
        $pdo->exec('CREATE CONSTRAINT TRIGGER eav_value_unique_guard
            AFTER INSERT OR UPDATE ON eav_value_varchar
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION eav_value_unique_guard()');

        // system attributes: is_unique joins what only a migration changes
        $pdo->exec(<<<'SQL'
            CREATE OR REPLACE FUNCTION eav_attribute_system_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF current_setting('iter.allow_system_attribute', true) = 'on' THEN
                    RETURN COALESCE(NEW, OLD);
                END IF;
                IF TG_OP = 'DELETE' AND OLD.is_system = 1 THEN
                    RAISE EXCEPTION 'O atributo do sistema "%" não pode ser removido.', OLD.code USING ERRCODE = 'restrict_violation';
                END IF;
                IF TG_OP = 'UPDATE' AND (OLD.is_system = 1 OR NEW.is_system <> OLD.is_system)
                   AND (NEW.entity_type_id, NEW.code, NEW.input_type, NEW.storage_type, NEW.is_system, NEW.is_unique)
                       IS DISTINCT FROM (OLD.entity_type_id, OLD.code, OLD.input_type, OLD.storage_type, OLD.is_system, OLD.is_unique) THEN
                    RAISE EXCEPTION 'O atributo do sistema "%" não pode mudar de entidade, código, tipo, valor único ou deixar de ser do sistema.', OLD.code USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN COALESCE(NEW, OLD);
            END $$
            SQL);
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TRIGGER IF EXISTS eav_value_unique_guard ON eav_value_varchar');
        $pdo->exec('DROP FUNCTION IF EXISTS eav_value_unique_guard()');
        $pdo->exec('DROP TABLE IF EXISTS eav_unique_value');
        $pdo->exec('ALTER TABLE eav_attribute DROP COLUMN IF EXISTS is_unique');
        (require __DIR__ . '/20260930000010_protect_system_attributes.php')->up($pdo);
    }
};
