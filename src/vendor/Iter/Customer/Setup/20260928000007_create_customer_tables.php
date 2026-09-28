<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS customer_group (
                customer_group_id SERIAL PRIMARY KEY,
                code VARCHAR(32) NOT NULL UNIQUE,
                name VARCHAR(255) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW()
            )"
        );
        $pdo->exec(
            "INSERT INTO customer_group (code, name) VALUES ('general', 'Geral'), ('wholesale', 'Atacado'), ('vip', 'VIP')
             ON CONFLICT (code) DO NOTHING"
        );

        // email, names, type and documents are columns (list, search, uniqueness, future login); the rest is EAV.
        // A customer belongs to one website: the same e-mail/CPF may exist in another website.
        // taxvat holds only digits (CPF when pf, CNPJ when pj)
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS customer_entity (
                customer_id SERIAL PRIMARY KEY,
                website_id SMALLINT NOT NULL REFERENCES store_website (website_id) ON DELETE RESTRICT,
                attribute_set_id INT NOT NULL REFERENCES eav_attribute_set (attribute_set_id) ON DELETE RESTRICT,
                group_id INT NOT NULL REFERENCES customer_group (customer_group_id) ON DELETE RESTRICT,
                email VARCHAR(255) NOT NULL,
                firstname VARCHAR(255) NOT NULL,
                lastname VARCHAR(255) NOT NULL,
                customer_type CHAR(2) NOT NULL DEFAULT 'pf' CHECK (customer_type IN ('pf', 'pj')),
                taxvat VARCHAR(14) NULL,
                company_name VARCHAR(255) NULL,
                trade_name VARCHAR(255) NULL,
                state_registration VARCHAR(20) NULL,
                is_ie_exempt SMALLINT NOT NULL DEFAULT 0,
                dob DATE NULL,
                password_hash VARCHAR(255) NULL,
                is_active SMALLINT NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW()
            )"
        );
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS customer_entity_email ON customer_entity (website_id, LOWER(email))');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS customer_entity_taxvat ON customer_entity (website_id, taxvat) WHERE taxvat IS NOT NULL');
        $pdo->exec('CREATE INDEX IF NOT EXISTS customer_entity_group ON customer_entity (group_id)');
        $pdo->exec(
            'CREATE OR REPLACE TRIGGER customer_entity_set_updated_at
                BEFORE UPDATE ON customer_entity
                FOR EACH ROW
                EXECUTE FUNCTION set_updated_at()'
        );
        $pdo->exec(
            'CREATE OR REPLACE TRIGGER customer_group_set_updated_at
                BEFORE UPDATE ON customer_group
                FOR EACH ROW
                EXECUTE FUNCTION set_updated_at()'
        );

        // system attributes every customer has, in the default set's "Dados pessoais" group
        $pdo->exec(
            "INSERT INTO eav_attribute (entity_type_id, code, label, input_type, storage_type, is_required, is_visible, is_system, sort_order)
             SELECT entity_type_id, a.code, a.label, a.input_type, 'varchar', 0, 1, 1, a.sort_order
             FROM eav_entity_type, (VALUES ('gender', 'Sexo', 'select', 10), ('phone', 'Telefone', 'phone', 20), ('mobile', 'Celular', 'phone', 30))
                  AS a (code, label, input_type, sort_order)
             WHERE eav_entity_type.code = 'customer'
             ON CONFLICT (entity_type_id, code) DO NOTHING"
        );
        $pdo->exec(
            "INSERT INTO eav_attribute_option (attribute_id, value, label, sort_order)
             SELECT a.attribute_id, o.value, o.label, o.sort_order
             FROM eav_attribute a
             JOIN eav_entity_type et ON et.entity_type_id = a.entity_type_id AND et.code = 'customer',
                  (VALUES ('m', 'Masculino', 10), ('f', 'Feminino', 20), ('n', 'Não informar', 30)) AS o (value, label, sort_order)
             WHERE a.code = 'gender'
             ON CONFLICT DO NOTHING"
        );
        $pdo->exec(
            "INSERT INTO eav_attribute_set_item (attribute_set_id, attribute_id, group_name, sort_order)
             SELECT s.attribute_set_id, a.attribute_id, 'Dados pessoais', a.sort_order
             FROM eav_attribute_set s
             JOIN eav_attribute a ON a.entity_type_id = s.entity_type_id AND a.code IN ('gender', 'phone', 'mobile')
             JOIN eav_entity_type et ON et.entity_type_id = s.entity_type_id
             WHERE et.code = 'customer' AND s.code = 'default'
             ON CONFLICT DO NOTHING"
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS customer_entity');
        $pdo->exec('DROP TABLE IF EXISTS customer_group');
        $pdo->exec(
            "DELETE FROM eav_attribute WHERE is_system = 1 AND code IN ('gender', 'phone', 'mobile')
             AND entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE code = 'customer')"
        );
    }
};
