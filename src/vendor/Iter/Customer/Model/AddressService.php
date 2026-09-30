<?php

declare(strict_types=1);

namespace Iter\Customer\Model;

use Iter\Core\Db\Connection;
use Iter\Eav\Model\AttributeException;
use Iter\Eav\Model\ValueService;

/**
 * A customer's addresses: customer_address keeps the customer and the default flags, the fields
 * are system attributes of "customer_address" (read through the view customer_address_flat). save() replaces the list: rows with an
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

    private const TEXT = ['recipient' => 255, 'street' => 255, 'number' => 20, 'complement' => 255, 'district' => 255, 'city' => 255];

    /** @return array<int, array<string, mixed>> the customer's addresses, defaults first */
    public function getList(int $customerId): array
    {
        $db = Connection::get()->adapter();

        return $db->fetchAll($db->select()->from('customer_address_flat')->where('customer_id = ?', $customerId)
            ->order(['is_default_shipping DESC', 'is_default_billing DESC', 'address_id']));
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
                $values->write((int) $id, $values->validate(self::ENTITY_TYPE, array_diff_key($row, $flags + ['address_id' => 0]), (int) $id));
            }
        });
    }

    /** @return array<int, array<string, mixed>> table rows (address_id null for new ones) */
    private function validate(array $addresses, array $currentIds): array
    {
        $rows = [];
        foreach (array_values($addresses) as $i => $address) {
            $address = is_array($address) ? $address : [];
            $row = ['postcode' => preg_replace('/\D/', '', (string) ($address['postcode'] ?? '')), 'region' => strtoupper(trim((string) ($address['region'] ?? '')))];
            foreach (self::TEXT as $key => $max) {
                $row[$key] = mb_substr(trim((string) ($address[$key] ?? '')), 0, $max);
            }
            $row['telephone'] = mb_substr(trim((string) ($address['telephone'] ?? '')), 0, 20);
            if (implode('', $row) === '') {
                continue; // blank row of the form
            }
            $error = static fn (string $message) => throw new AttributeException('ADDRESS_INVALID', 'Endereço ' . ($i + 1) . ": {$message}");
            if (strlen($row['postcode']) !== 8) {
                $error('informe um CEP com 8 dígitos.');
            }
            foreach (['street' => 'a rua', 'number' => 'o número', 'district' => 'o bairro', 'city' => 'a cidade'] as $key => $label) {
                if ($row[$key] === '') {
                    $error("informe {$label}.");
                }
            }
            if (!isset(self::REGIONS[$row['region']])) {
                $error('escolha o estado (UF).');
            }
            $id = (int) ($address['address_id'] ?? 0);
            if ($id && !in_array($id, $currentIds, true)) {
                throw new AttributeException('ADDRESS_NOT_FOUND', "O endereço {$id} não é deste cliente.");
            }
            $rows[] = array_map(static fn ($v) => $v === '' ? null : $v, $row) + [
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
