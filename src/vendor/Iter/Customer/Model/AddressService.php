<?php

declare(strict_types=1);

namespace Iter\Customer\Model;

use Iter\Core\Db\Connection;
use Iter\Eav\Form\FormSchemaGenerator;
use Iter\Eav\Model\AttributeException;
use Iter\Eav\Model\ValueService;

/**
 * A customer's addresses: customer_address keeps the customer and the default flags, the fields
 * are attributes of "customer_address". The fixed ones (FIXED, read through the view
 * customer_address_flat) have their own rules here; the others come from the entity's default
 * attribute set (complement, recipient, telephone and the admin's). save() replaces the list: rows with an
 * address_id are updated, the others created, and the ids not given are removed. Blank
 * rows are ignored. Without a marked default, the first address becomes the default
 * for shipping and for billing.
 */
class AddressService
{
    public const ENTITY_TYPE = 'customer_address';

    public const REGIONS = [
        'AC' => 'Acre', 'AL' => 'Alagoas', 'AP' => 'Amapá', 'AM' => 'Amazonas', 'BA' => 'Bahia', 'CE' => 'Ceará',
        'DF' => 'Distrito Federal', 'ES' => 'Espírito Santo', 'GO' => 'Goiás', 'MA' => 'Maranhão', 'MT' => 'Mato Grosso',
        'MS' => 'Mato Grosso do Sul', 'MG' => 'Minas Gerais', 'PA' => 'Pará', 'PB' => 'Paraíba', 'PR' => 'Paraná',
        'PE' => 'Pernambuco', 'PI' => 'Piauí', 'RJ' => 'Rio de Janeiro', 'RN' => 'Rio Grande do Norte', 'RS' => 'Rio Grande do Sul',
        'RO' => 'Rondônia', 'RR' => 'Roraima', 'SC' => 'Santa Catarina', 'SP' => 'São Paulo', 'SE' => 'Sergipe', 'TO' => 'Tocantins',
    ];

    /** fixed in the form and in the flat view: CEP lookup, shipping and orders read them */
    public const FIXED = ['postcode', 'street', 'number', 'district', 'city', 'region'];

    private const TEXT = ['street' => 255, 'number' => 20, 'district' => 255, 'city' => 255];

    /** @return array<int, array<string, mixed>> the customer's addresses, defaults first, with every attribute value */
    public function getList(int $customerId): array
    {
        $db = Connection::get()->adapter();
        $values = new ValueService();

        // ponytail: one values query per address; a customer has a handful
        return array_map(static fn (array $row) => $row + $values->load(self::ENTITY_TYPE, (int) $row['address_id']),
            $db->fetchAll($db->select()->from('customer_address_flat')->where('customer_id = ?', $customerId)
                ->order(['is_default_shipping DESC', 'is_default_billing DESC', 'address_id'])));
    }

    /**
     * The set's fields (visible ones, in order); hidden attributes keep their values.
     * ponytail: every address uses the default set; a set per address when a store needs two kinds
     *
     * @return array<int, array> FormSchemaGenerator fields
     */
    public static function fields(): array
    {
        return (new FormSchemaGenerator())->generate(self::ENTITY_TYPE)['fields'];
    }

    /**
     * Validates all rows before writing any (call inside the customer's transaction)
     *
     * @param array<int, array<string, mixed>> $addresses
     */
    public function save(int $customerId, array $addresses): void
    {
        $current = array_column($this->getList($customerId), null, 'address_id');
        $rows = $this->validate($addresses, array_keys($current));
        $db = Connection::get()->adapter();

        $values = new ValueService();

        $db->transaction(function () use ($db, $values, $customerId, $current, $rows): void {
            $kept = array_filter(array_column($rows, 'address_id'));
            foreach (array_diff(array_keys($current), $kept) as $id) {
                $db->delete('customer_address', ['address_id = ?' => $id]);
                $values->deleteEntity(self::ENTITY_TYPE, (int) $id);
            }
            // clear the defaults first, so the partial unique indexes never see two
            $db->update('customer_address', ['is_default_shipping' => 0, 'is_default_billing' => 0], ['customer_id = ?' => $customerId]);
            foreach ($rows as $row) {
                $flags = ['is_default_shipping' => $row['is_default_shipping'], 'is_default_billing' => $row['is_default_billing']];
                $id = $row['address_id'];
                if ($id) {
                    $db->update('customer_address', $flags, ['address_id = ?' => $id]);
                } else {
                    $id = $db->insert('customer_address', $flags + ['customer_id' => $customerId])['address_id'];
                }
                $values->write((int) $id, $row['values']);
            }
        });
    }

    /**
     * One address outside a customer's list (the cart's delivery or billing address, a copy in an order):
     * the same checks, returned as code => value (fixed fields + the set's), nothing written.
     *
     * @param string $label prefix of the messages, e.g. "Endereço de entrega"
     * @return array<string, mixed>
     */
    public function check(array $address, string $label): array
    {
        $values = $this->normalize($address, $label) ?? $this->normalize(['postcode' => '-'] + $address, $label); // blank: the CEP error
        try {
            (new ValueService())->validate(self::ENTITY_TYPE, $values);
        } catch (AttributeException $e) {
            throw new AttributeException($e->errorCode, "{$label}: " . $e->getMessage());
        }

        return $values;
    }

    /**
     * An address carries its fields by code (the form's addresses[i][code]); the API may send the
     * set's fields in 'attributes' (code => value), where a code outside the set is an error.
     *
     * @return ?array<string, mixed> fixed fields + the set's, checked; null for a blank form row
     */
    private function normalize(array $address, string $label): ?array
    {
        $fields = array_column(self::fields(), null, 'name');
        $extra = is_array($address['attributes'] ?? null) ? $address['attributes'] : [];
        $unknown = array_diff(array_keys($extra), array_keys($fields));
        if ($unknown) {
            throw new AttributeException('ATTRIBUTE_NOT_FOUND', 'Atributo fora do conjunto do endereço: ' . implode(', ', $unknown) . '.');
        }
        $row = ['postcode' => preg_replace('/\D/', '', (string) ($address['postcode'] ?? '')), 'region' => strtoupper(trim((string) ($address['region'] ?? '')))];
        foreach (self::TEXT as $key => $max) {
            $row[$key] = mb_substr(trim((string) ($address[$key] ?? '')), 0, $max);
        }
        $set = [];
        foreach ($fields as $code => $field) {
            $set[$code] = $extra[$code] ?? $address[$code] ?? '';
        }
        // blank row of the form (an unchecked Sim/Não posts "0")
        if (implode('', $row) === '' && !array_filter($set, static fn ($v) => !in_array($v, ['', '0', [], null], true))) {
            return null;
        }
        $error = static fn (string $message) => throw new AttributeException('ADDRESS_INVALID', "{$label}: {$message}");
        if (strlen($row['postcode']) !== 8) {
            $error('informe um CEP com 8 dígitos.');
        }
        foreach (['street' => 'a rua', 'number' => 'o número', 'district' => 'o bairro', 'city' => 'a cidade'] as $key => $name) {
            if ($row[$key] === '') {
                $error("informe {$name}.");
            }
        }
        if (!isset(self::REGIONS[$row['region']])) {
            $error('escolha o estado (UF).');
        }

        return $row + $set;
    }

    /** @return array<int, array{address_id: ?int, is_default_shipping: int, is_default_billing: int, values: array}> (address_id null for new ones) */
    private function validate(array $addresses, array $currentIds): array
    {
        $values = new ValueService();
        $rows = [];
        foreach (array_values($addresses) as $i => $address) {
            $address = is_array($address) ? $address : [];
            $checked = $this->normalize($address, 'Endereço ' . ($i + 1));
            if ($checked === null) {
                continue;
            }
            $id = (int) ($address['address_id'] ?? 0);
            if ($id && !in_array($id, $currentIds, true)) {
                throw new AttributeException('ADDRESS_NOT_FOUND', "O endereço {$id} não é deste cliente.");
            }
            try {
                $valueRows = $values->validate(self::ENTITY_TYPE, $checked, $id ?: null);
            } catch (AttributeException $e) {
                throw new AttributeException($e->errorCode, 'Endereço ' . ($i + 1) . ': ' . $e->getMessage());
            }
            $rows[] = [
                'values' => $valueRows,
                'address_id' => $id ?: null,
                'is_default_shipping' => empty($address['is_default_shipping']) ? 0 : 1,
                'is_default_billing' => empty($address['is_default_billing']) ? 0 : 1,
            ];
        }
        // one default of each kind: the first marked, else the first address
        foreach (['is_default_shipping', 'is_default_billing'] as $flag) {
            $first = array_key_first(array_filter(array_column($rows, $flag))) ?? ($rows ? 0 : null);
            foreach ($rows as $k => $row) {
                $rows[$k][$flag] = $k === $first ? 1 : 0;
            }
        }

        return $rows;
    }
}
