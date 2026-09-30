<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * No two attributes of an entity (and no two options of an attribute) whose
 * names only differ by case, accents, punctuation, connecting words, word order
 * or a regular pt-BR plural: "Rua"/"rua", "Número"/"Numeros", "numero_casa"/"Número da casa".
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        // ponytail: heuristic singular, regular plurals only; synonyms (Rua/Logradouro) pass
        $pdo->exec(<<<'SQL'
            CREATE OR REPLACE FUNCTION iter_attr_key(txt TEXT) RETURNS TEXT
            LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE AS $$
                -- a name made only of connecting words ("A", "De") keeps them
                SELECT COALESCE(string_agg(w, '' ORDER BY w), regexp_replace(translate(lower(txt), 'áàâãäéèêëíìîïóòôõöúùûüçñ', 'aaaaaeeeeiiiiooooouuuucn'), '[^a-z0-9]', '', 'g')) FROM (
                    SELECT CASE WHEN length(w) <= 3 THEN w
                        ELSE regexp_replace(regexp_replace(regexp_replace(regexp_replace(regexp_replace(w,
                            '(oe|ae|ao)s$', 'ao'),
                            '([aeou])is$', '\1l'),
                            'ns$', 'm'),
                            '([rzs])es$', '\1'),
                            '([aeiou])s$', '\1')
                    END AS w
                    FROM regexp_split_to_table(
                        regexp_replace(
                            translate(lower(txt), 'áàâãäéèêëíìîïóòôõöúùûüçñ.''', 'aaaaaeeeeiiiiooooouuuucn'),
                            '[^a-z0-9]+', ' ', 'g'),
                        ' ') AS w
                    WHERE w <> '' AND w NOT IN ('de', 'da', 'do', 'das', 'dos', 'e', 'a', 'o')
                ) words
            $$
            SQL);

        $clash = $pdo->query(<<<'SQL'
            SELECT et.code || ': ' || a.label || ' (' || a.code || ') × ' || b.label || ' (' || b.code || ')'
            FROM eav_attribute a
            JOIN eav_attribute b ON b.entity_type_id = a.entity_type_id AND b.attribute_id > a.attribute_id
            JOIN eav_entity_type et ON et.entity_type_id = a.entity_type_id
            WHERE iter_attr_key(b.label) IN (iter_attr_key(a.label), iter_attr_key(a.code))
               OR iter_attr_key(b.code) IN (iter_attr_key(a.label), iter_attr_key(a.code))
            UNION ALL
            SELECT a.code || ': ' || o.label || ' × ' || p.label
            FROM eav_attribute_option o
            JOIN eav_attribute_option p ON p.attribute_id = o.attribute_id AND p.option_id > o.option_id
            JOIN eav_attribute a ON a.attribute_id = o.attribute_id
            WHERE iter_attr_key(p.label) = iter_attr_key(o.label)
            SQL)->fetchAll(PDO::FETCH_COLUMN);
        if ($clash) {
            throw new RuntimeException("Atributos ou opções com o mesmo nome; renomeie antes de migrar:\n" . implode("\n", $clash));
        }

        // a trigger, not a unique index: a label must not match another attribute's code either.
        // The advisory lock serializes attribute writes per entity, so two concurrent saves can't both pass.
        $pdo->exec(<<<'SQL'
            CREATE OR REPLACE FUNCTION eav_attribute_name_unique() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE other RECORD;
            BEGIN
                PERFORM pg_advisory_xact_lock(hashtext('eav_attribute_name'), NEW.entity_type_id);
                SELECT label, code INTO other FROM eav_attribute
                WHERE entity_type_id = NEW.entity_type_id AND attribute_id <> NEW.attribute_id
                  AND (iter_attr_key(label) IN (iter_attr_key(NEW.label), iter_attr_key(NEW.code))
                    OR iter_attr_key(code) IN (iter_attr_key(NEW.label), iter_attr_key(NEW.code)))
                LIMIT 1;
                IF FOUND THEN
                    RAISE EXCEPTION 'Já existe o atributo "%" (%) com esse nome.', other.label, other.code
                        USING ERRCODE = 'unique_violation', CONSTRAINT = 'eav_attribute_name_key';
                END IF;
                RETURN NEW;
            END $$
            SQL);
        $pdo->exec('CREATE OR REPLACE TRIGGER eav_attribute_name_unique
            BEFORE INSERT OR UPDATE OF entity_type_id, code, label ON eav_attribute
            FOR EACH ROW EXECUTE FUNCTION eav_attribute_name_unique()');

        // options: checked at commit, since replaceOptions rewrites them one by one (swapping two labels is fine)
        $pdo->exec(<<<'SQL'
            CREATE OR REPLACE FUNCTION eav_attribute_option_label_unique() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE other TEXT;
            BEGIN
                SELECT label INTO other FROM eav_attribute_option
                WHERE attribute_id = NEW.attribute_id AND option_id <> NEW.option_id
                  AND iter_attr_key(label) = iter_attr_key(NEW.label)
                LIMIT 1;
                IF FOUND THEN
                    RAISE EXCEPTION 'As opções "%" e "%" têm o mesmo nome.', other, NEW.label
                        USING ERRCODE = 'unique_violation', CONSTRAINT = 'eav_attribute_option_label_key';
                END IF;
                RETURN NULL;
            END $$
            SQL);
        $pdo->exec('DROP TRIGGER IF EXISTS eav_attribute_option_label_unique ON eav_attribute_option');
        $pdo->exec('CREATE CONSTRAINT TRIGGER eav_attribute_option_label_unique
            AFTER INSERT OR UPDATE OF label ON eav_attribute_option
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION eav_attribute_option_label_unique()');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TRIGGER IF EXISTS eav_attribute_option_label_unique ON eav_attribute_option');
        $pdo->exec('DROP TRIGGER IF EXISTS eav_attribute_name_unique ON eav_attribute');
        $pdo->exec('DROP FUNCTION IF EXISTS eav_attribute_option_label_unique()');
        $pdo->exec('DROP FUNCTION IF EXISTS eav_attribute_name_unique()');
        $pdo->exec('DROP FUNCTION IF EXISTS iter_attr_key(TEXT)');
    }
};
