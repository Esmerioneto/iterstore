<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * The customer's registration fields become system attributes of "customer" (CUSTOMER-EAV-PLAN §1).
 * Values are copied to eav_value_*, e-mail and CPF/CNPJ get their eav_unique_value key with the
 * website as scope, and the counts are checked. The old columns are renamed legacy_* (kept one
 * delivery as a safety net, unused by the code). The view customer_flat gives back the old column
 * names, read by the model, the list and the search.
 */
return new class implements MigrationInterface {
    /** code => [label, input_type, storage_type, is_required, is_unique] */
    private const FIELDS = [
        'email' => ['E-mail', 'email', 'varchar', 1, 1],
        'firstname' => ['Nome', 'text', 'varchar', 1, 0],
        'lastname' => ['Sobrenome', 'text', 'varchar', 1, 0],
        'customer_type' => ['Tipo', 'select', 'varchar', 1, 0],
        'taxvat' => ['CPF/CNPJ', 'cpf_cnpj', 'varchar', 1, 1],
        'dob' => ['Nascimento', 'date', 'datetime', 0, 0],
        'company_name' => ['Razão social', 'text', 'varchar', 0, 0],
        'trade_name' => ['Nome fantasia', 'text', 'varchar', 0, 0],
        'state_registration' => ['Inscrição estadual', 'text', 'varchar', 0, 0],
        'is_ie_exempt' => ['Isento de IE', 'checkbox', 'boolean', 0, 0],
    ];

    public function up(PDO $pdo): void
    {
        $clash = $pdo->query(
            "SELECT 'website ' || website_id || ', e-mail ' || lower(email) || ': clientes ' || string_agg(customer_id::text, ', ')
             FROM customer_entity GROUP BY website_id, lower(email) HAVING COUNT(*) > 1
             UNION ALL
             SELECT 'website ' || website_id || ', CPF/CNPJ ' || taxvat || ': clientes ' || string_agg(customer_id::text, ', ')
             FROM customer_entity WHERE taxvat IS NOT NULL GROUP BY website_id, taxvat HAVING COUNT(*) > 1"
        )->fetchAll(PDO::FETCH_COLUMN);
        if ($clash) {
            throw new RuntimeException("Clientes com o mesmo e-mail ou CPF/CNPJ no website; corrija antes de migrar:\n" . implode("\n", $clash));
        }

        $insert = $pdo->prepare(
            "INSERT INTO eav_attribute (entity_type_id, code, label, input_type, storage_type, is_required, is_visible, is_system, is_unique, sort_order)
             SELECT entity_type_id, :code, :label, :input_type, :storage_type, :is_required, 1, 1, :is_unique, :sort_order
             FROM eav_entity_type WHERE code = 'customer'
             RETURNING attribute_id"
        );
        $ids = [];
        $sort = 0;
        foreach (self::FIELDS as $code => [$label, $inputType, $storage, $required, $unique]) {
            $insert->execute(['code' => $code, 'label' => $label, 'input_type' => $inputType, 'storage_type' => $storage,
                'is_required' => $required, 'is_unique' => $unique, 'sort_order' => ++$sort]);
            $ids[$code] = (int) $insert->fetchColumn();
        }
        $pdo->prepare("INSERT INTO eav_attribute_option (attribute_id, value, label, sort_order) VALUES (?, 'pf', 'Pessoa física', 10), (?, 'pj', 'Pessoa jurídica', 20)")
            ->execute([$ids['customer_type'], $ids['customer_type']]);

        // copied as CustomerService writes them: e-mail in lowercase, empty text = no value
        foreach (self::FIELDS as $code => [, , $storage]) {
            $value = match ($code) {
                'email' => 'lower(trim(email))',
                'customer_type' => 'trim(customer_type)',
                'dob', 'is_ie_exempt' => $code,
                default => "NULLIF(trim({$code}), '')",
            };
            $pdo->exec("INSERT INTO eav_value_{$storage} (attribute_id, entity_id, value)
                SELECT {$ids[$code]}, customer_id, {$value} FROM customer_entity WHERE {$value} IS NOT NULL");
            if (self::FIELDS[$code][4]) {
                $pdo->exec("INSERT INTO eav_unique_value (attribute_id, entity_id, scope_id, value_key)
                    SELECT {$ids[$code]}, customer_id, website_id, lower({$value}) FROM customer_entity WHERE {$value} IS NOT NULL");
            }
            $copied = (int) $pdo->query("SELECT COUNT(*) FROM eav_value_{$storage} WHERE attribute_id = {$ids[$code]}")->fetchColumn();
            $filled = (int) $pdo->query("SELECT COUNT(*) FROM customer_entity WHERE {$value} IS NOT NULL")->fetchColumn();
            if ($copied !== $filled) {
                throw new RuntimeException("Cópia de '{$code}' divergente: {$copied} valores para {$filled} clientes.");
            }
        }

        // uniqueness now lives in eav_unique_value; the legacy columns only keep the old values
        $pdo->exec('DROP INDEX IF EXISTS customer_entity_email');
        $pdo->exec('DROP INDEX IF EXISTS customer_entity_taxvat');
        foreach (array_keys(self::FIELDS) as $code) {
            $pdo->exec("ALTER TABLE customer_entity RENAME COLUMN {$code} TO legacy_{$code}");
            $pdo->exec("ALTER TABLE customer_entity ALTER COLUMN legacy_{$code} DROP NOT NULL, ALTER COLUMN legacy_{$code} DROP DEFAULT");
        }

        $this->createView($pdo, $ids);
    }

    public function down(PDO $pdo): void
    {
        $set = [];
        foreach (array_keys(self::FIELDS) as $code) {
            $pdo->exec("ALTER TABLE customer_entity RENAME COLUMN legacy_{$code} TO {$code}");
            $set[] = "{$code} = f.{$code}";
        }
        $pdo->exec('UPDATE customer_entity c SET ' . implode(', ', $set) . ' FROM customer_flat f WHERE f.customer_id = c.customer_id');
        $pdo->exec('DROP VIEW IF EXISTS customer_flat');
        $pdo->exec("ALTER TABLE customer_entity ALTER COLUMN email SET NOT NULL, ALTER COLUMN firstname SET NOT NULL, ALTER COLUMN lastname SET NOT NULL,
            ALTER COLUMN customer_type SET NOT NULL, ALTER COLUMN customer_type SET DEFAULT 'pf',
            ALTER COLUMN is_ie_exempt SET NOT NULL, ALTER COLUMN is_ie_exempt SET DEFAULT 0");
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS customer_entity_email ON customer_entity (website_id, LOWER(email))');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS customer_entity_taxvat ON customer_entity (website_id, taxvat) WHERE taxvat IS NOT NULL');
        // values, options and unique keys go with the attributes (ON DELETE CASCADE)
        $pdo->prepare("DELETE FROM eav_attribute WHERE is_system = 1 AND code IN (" . implode(', ', array_fill(0, count(self::FIELDS), '?')) . ")
            AND entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE code = 'customer')")->execute(array_keys(self::FIELDS));
    }

    /**
     * customer_entity with the attribute values under the old column names. The attribute ids are
     * fixed here: the codes of system attributes never change (eav_attribute_system_guard).
     * ponytail: a new customer_entity column must be added to this view too.
     */
    private function createView(PDO $pdo, array $ids): void
    {
        $columns = [];
        $joins = [];
        foreach (self::FIELDS as $code => [, , $storage]) {
            $columns[] = match ($code) {
                'dob' => 'dob.value::date AS dob',
                'is_ie_exempt' => 'COALESCE(is_ie_exempt.value, 0) AS is_ie_exempt',
                default => "{$code}.value AS {$code}",
            };
            $joins[] = "LEFT JOIN eav_value_{$storage} {$code} ON {$code}.attribute_id = {$ids[$code]} AND {$code}.entity_id = c.customer_id";
        }
        $pdo->exec('CREATE OR REPLACE VIEW customer_flat AS
            SELECT c.customer_id, c.website_id, c.attribute_set_id, c.group_id, ' . implode(', ', $columns) . ',
                c.password_hash, c.is_active, c.created_at, c.updated_at, c.confirmation_hash
            FROM customer_entity c ' . implode(' ', $joins));
    }
};
