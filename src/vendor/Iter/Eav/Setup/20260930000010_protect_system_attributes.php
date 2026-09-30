<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * A module's (system) attribute is neither deleted nor changed in what the code relies on
 * (entity, code, input/storage type, is_system), not even by SQL run by hand. Only a
 * migration opens the lock: MigrationRunner runs each one with SET LOCAL iter.allow_system_attribute.
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
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
                   AND (NEW.entity_type_id, NEW.code, NEW.input_type, NEW.storage_type, NEW.is_system)
                       IS DISTINCT FROM (OLD.entity_type_id, OLD.code, OLD.input_type, OLD.storage_type, OLD.is_system) THEN
                    RAISE EXCEPTION 'O atributo do sistema "%" não pode mudar de entidade, código, tipo ou deixar de ser do sistema.', OLD.code USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN COALESCE(NEW, OLD);
            END $$
            SQL);
        $pdo->exec('CREATE OR REPLACE TRIGGER eav_attribute_system_guard
            BEFORE UPDATE OR DELETE ON eav_attribute
            FOR EACH ROW EXECUTE FUNCTION eav_attribute_system_guard()');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TRIGGER IF EXISTS eav_attribute_system_guard ON eav_attribute');
        $pdo->exec('DROP FUNCTION IF EXISTS eav_attribute_system_guard()');
    }
};
