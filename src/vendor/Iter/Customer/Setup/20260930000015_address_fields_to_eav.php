<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;
use Iter\Customer\Model\AddressService;

/**
 * CUSTOMER-EAV-PLAN delivery 5: the address fields become system attributes of the new entity
 * "customer_address" (entity_id = address_id), as 20260930000012 did for the customer.
 * customer_address keeps the customer and the default flags (their partial unique indexes stay);
 * the old columns are renamed legacy_* and the view customer_address_flat gives back their names.
 */
return new class implements MigrationInterface {
    /** code => [label, input_type, is_required] (all varchar) */
    private const FIELDS = [
        'postcode' => ['CEP', 'cep', 1],
        'street' => ['Rua', 'text', 1],
        'number' => ['Número', 'text', 1],
        'complement' => ['Complemento', 'text', 0],
        'district' => ['Bairro', 'text', 1],
        'city' => ['Cidade', 'text', 1],
        'region' => ['Estado (UF)', 'select', 1],
        'recipient' => ['Destinatário', 'text', 0],
        'telephone' => ['Telefone', 'phone', 0],
    ];

    /** NOT NULL columns, restored by down() */
    private const REQUIRED = ['postcode', 'street', 'number', 'district', 'city', 'region'];

    public function up(PDO $pdo): void
    {
        $pdo->exec("INSERT INTO eav_entity_type (code, label) VALUES ('customer_address', 'Endereço do cliente')");
        $insert = $pdo->prepare(
            "INSERT INTO eav_attribute (entity_type_id, code, label, input_type, storage_type, is_required, is_visible, is_system, sort_order)
             SELECT entity_type_id, :code, :label, :input_type, 'varchar', :is_required, 1, 1, :sort_order
             FROM eav_entity_type WHERE code = 'customer_address'
             RETURNING attribute_id"
        );
        $ids = [];
        $sort = 0;
        foreach (self::FIELDS as $code => [$label, $inputType, $required]) {
            $insert->execute(['code' => $code, 'label' => $label, 'input_type' => $inputType, 'is_required' => $required, 'sort_order' => ++$sort]);
            $ids[$code] = (int) $insert->fetchColumn();
        }
        $option = $pdo->prepare('INSERT INTO eav_attribute_option (attribute_id, value, label, sort_order) VALUES (?, ?, ?, ?)');
        $sort = 0;
        foreach (AddressService::REGIONS as $uf => $name) {
            $option->execute([$ids['region'], $uf, $name, $sort += 10]);
        }

        // copied as AddressService writes them: trimmed, empty = no value
        foreach (array_keys(self::FIELDS) as $code) {
            $value = "NULLIF(trim({$code}), '')";
            $pdo->exec("INSERT INTO eav_value_varchar (attribute_id, entity_id, value)
                SELECT {$ids[$code]}, address_id, {$value} FROM customer_address WHERE {$value} IS NOT NULL");
            $copied = (int) $pdo->query("SELECT COUNT(*) FROM eav_value_varchar WHERE attribute_id = {$ids[$code]}")->fetchColumn();
            $filled = (int) $pdo->query("SELECT COUNT(*) FROM customer_address WHERE {$value} IS NOT NULL")->fetchColumn();
            if ($copied !== $filled) {
                throw new RuntimeException("Cópia de '{$code}' divergente: {$copied} valores para {$filled} endereços.");
            }
            $pdo->exec("ALTER TABLE customer_address RENAME COLUMN {$code} TO legacy_{$code}");
            $pdo->exec("ALTER TABLE customer_address ALTER COLUMN legacy_{$code} DROP NOT NULL");
        }

        // ponytail: a new customer_address column must be added to this view too
        $columns = [];
        $joins = [];
        foreach (array_keys(self::FIELDS) as $code) {
            $columns[] = "{$code}.value AS {$code}";
            $joins[] = "LEFT JOIN eav_value_varchar {$code} ON {$code}.attribute_id = {$ids[$code]} AND {$code}.entity_id = a.address_id";
        }
        $pdo->exec('CREATE OR REPLACE VIEW customer_address_flat AS
            SELECT a.address_id, a.customer_id, ' . implode(', ', $columns) . ',
                a.is_default_shipping, a.is_default_billing, a.created_at, a.updated_at
            FROM customer_address a ' . implode(' ', $joins));
    }

    public function down(PDO $pdo): void
    {
        $set = [];
        foreach (array_keys(self::FIELDS) as $code) {
            $pdo->exec("ALTER TABLE customer_address RENAME COLUMN legacy_{$code} TO {$code}");
            $set[] = "{$code} = f.{$code}";
        }
        $pdo->exec('UPDATE customer_address c SET ' . implode(', ', $set) . ' FROM customer_address_flat f WHERE f.address_id = c.address_id');
        $pdo->exec('DROP VIEW IF EXISTS customer_address_flat');
        $pdo->exec('ALTER TABLE customer_address ' . implode(', ', array_map(static fn ($c) => "ALTER COLUMN {$c} SET NOT NULL", self::REQUIRED)));
        // values and options go with the attributes (ON DELETE CASCADE)
        $pdo->exec("DELETE FROM eav_attribute WHERE entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE code = 'customer_address')");
        $pdo->exec("DELETE FROM eav_entity_type WHERE code = 'customer_address'");
    }
};
