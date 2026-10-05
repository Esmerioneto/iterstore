<?php

declare(strict_types=1);

namespace Iter\Eav\Model\ResourceModel;

use Iter\Core\Db\Adapter;

use Iter\Core\Db\Expr;
use Iter\Core\Model\ResourceModel\AbstractDb;
use Iter\Eav\Model\ResourceModel\Attribute\Collection;
use Iter\Eav\InputTypeRegistry;

class Attribute extends AbstractDb
{
    protected function _construct(): void
    {
        $this->init('eav_attribute', 'attribute_id');
    }

    /**
     * @param array{entity_type?: string, input_type?: string, is_system?: string, q?: string} $filters
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function search(array $filters, int $page, int $perPage): array
    {
        $collection = (new Collection())->setOrder('et.code', 'ASC')->setOrder('sort_order', 'ASC')->setOrder('code', 'ASC')
            ->setPageSize($perPage)->setCurPage($page);
        if (($filters['entity_type'] ?? '') !== '') {
            $collection->addFieldToFilter('et.code', $filters['entity_type']);
        }
        if (($filters['input_type'] ?? '') !== '') {
            $collection->addFieldToFilter('input_type', $filters['input_type']);
        }
        if (in_array($filters['is_system'] ?? '', ['0', '1'], true)) {
            $collection->addFieldToFilter('is_system', (int) $filters['is_system']);
        }
        if (($filters['q'] ?? '') !== '') {
            $q = ['ilike' => '%' . addcslashes($filters['q'], '%_\\') . '%'];
            $collection->addFieldToFilter(['code', 'label'], [$q, $q]);
        }

        return ['items' => $collection->getData(), 'total' => $collection->getSize()];
    }

    /** @return array<string, array{entity_type_id: int, label: string}> code => row; read once per process (types come only from migrations) */
    public function getEntityTypes(): array
    {
        static $types = null;
        $db = $this->getConnection();

        return $types ??= array_column($db->fetchAll($db->select()->from('eav_entity_type', ['code', 'entity_type_id', 'label'])->order('label')), null, 'code');
    }

    /** @return array<int, array{value: string, label: string, sort_order: int, is_default: int}> */
    public function getOptions(int $attributeId): array
    {
        $db = $this->getConnection();

        return $db->fetchAll($db->select()->from('eav_attribute_option', ['value', 'label', 'sort_order', 'is_default'])
            ->where('attribute_id = ?', $attributeId)->order('sort_order, option_id'));
    }

    /**
     * Makes the attribute's options exactly $options, matched by value, so
     * an option keeps its option_id while its label/position change.
     *
     * @param array<int, array{value: string, label: string, sort_order: int, is_default: int}> $options
     */
    public function replaceOptions(int $attributeId, array $options): void
    {
        $this->getConnection()->transaction(static function ($db) use ($attributeId, $options): void {
            $values = array_column($options, 'value');
            $db->delete('eav_attribute_option', ['attribute_id = ?' => $attributeId] + ($values ? ['value NOT IN (?)' => $values] : []));
            foreach ($options as $option) {
                $db->insertOnConflict('eav_attribute_option', ['attribute_id' => $attributeId] + $option, ['attribute_id', 'value'], ['label', 'sort_order', 'is_default']);
            }
        });
    }

    /** @return array<int, array<string, mixed>> the entity type's attributes by position */
    public function getByEntityType(int $entityTypeId): array
    {
        $db = $this->getConnection();

        return $db->fetchAll($db->select()->from('eav_attribute')->where('entity_type_id = ?', $entityTypeId)->order('sort_order, code'));
    }

    /** @return array<int, array{code: string, storage_type: string, input_type: string, value: string}> raw values, as text */
    public function getValues(int $entityTypeId, int $entityId): array
    {
        return $this->getValuesMany($entityTypeId, [$entityId]);
    }

    /**
     * The values of many entities in one query (a list's page)
     *
     * @param int[] $entityIds
     * @param ?string[] $codes only these attributes; null = every one
     * @return array<int, array{entity_id: int, code: string, storage_type: string, input_type: string, value: string}> raw values, as text
     */
    public function getValuesMany(int $entityTypeId, array $entityIds, ?array $codes = null): array
    {
        if (!$entityIds || $codes === []) {
            return [];
        }
        // ponytail: UNION over the value tables stays SQL, Select has no UNION
        $union = implode(' UNION ALL ', array_map(
            static fn ($storage) => "SELECT attribute_id, entity_id, value::text FROM eav_value_{$storage}",
            InputTypeRegistry::STORAGE_TYPES
        ));
        $bind = ['entity_type_id' => $entityTypeId];
        $ids = Adapter::bindInto('?', array_map('intval', $entityIds), $bind);
        $only = $codes === null ? '' : ' AND a.code IN (' . Adapter::bindInto('?', array_map('strval', $codes), $bind) . ')';

        return $this->getConnection()->fetchAll(
            "SELECT v.entity_id, a.code, a.storage_type, a.input_type, v.value FROM ({$union}) v
             JOIN eav_attribute a ON a.attribute_id = v.attribute_id
             WHERE a.entity_type_id = :entity_type_id AND v.entity_id IN ({$ids}){$only}",
            $bind
        );
    }

    /**
     * One transaction for the whole entity: a null value deletes the row. A row with 'unique'
     * (the attribute's label, set by ValueService::validate) also keeps its eav_unique_value key.
     *
     * @param array<int, array{attribute_id: int, storage_type: string, value: string|int|null, unique?: ?string}> $rows
     */
    public function saveValues(int $entityId, array $rows, int $scopeId = 0): void
    {
        $this->getConnection()->transaction(function ($db) use ($entityId, $rows, $scopeId): void {
            foreach ($rows as $row) {
                if (($row['unique'] ?? null) !== null) {
                    $db->delete('eav_unique_value', ['attribute_id = ?' => $row['attribute_id'], 'entity_id = ?' => $entityId]);
                    if ($row['value'] !== null) {
                        $db->insert('eav_unique_value', ['attribute_id' => $row['attribute_id'], 'entity_id' => $entityId, 'scope_id' => $scopeId, 'value_key' => mb_strtolower((string) $row['value'])]);
                    }
                }
                $table = $this->valueTable($row['storage_type']);
                if ($row['value'] === null) {
                    $db->delete($table, ['attribute_id = ?' => $row['attribute_id'], 'entity_id = ?' => $entityId]);
                } else {
                    $db->insertOnConflict($table, ['attribute_id' => $row['attribute_id'], 'entity_id' => $entityId, 'value' => $row['value']], ['attribute_id', 'entity_id']);
                }
            }
        });
    }

    public function deleteValues(int $entityTypeId, int $entityId): void
    {
        $tables = array_merge(['eav_unique_value'], array_map(static fn ($storage) => 'eav_value_' . $storage, InputTypeRegistry::STORAGE_TYPES));
        foreach ($tables as $table) {
            $this->getConnection()->delete($table, [
                'attribute_id IN (SELECT attribute_id FROM eav_attribute WHERE entity_type_id = ?)' => $entityTypeId,
                'entity_id = ?' => $entityId,
            ]);
        }
    }

    /** another entity already has $value for the "Valor único" attribute in the scope */
    public function uniqueTaken(int $attributeId, int $scopeId, string $value, int $entityId): bool
    {
        $db = $this->getConnection();

        return (bool) $db->fetchOne($db->select()->from('eav_unique_value', [new Expr('1')])
            ->where('attribute_id = ?', $attributeId)->where('scope_id = ?', $scopeId)
            ->where('value_key = ?', mb_strtolower($value))->where('entity_id <> ?', $entityId));
    }

    public function hasValues(int $attributeId, string $storageType): bool
    {
        $db = $this->getConnection();

        return (bool) $db->fetchOne($db->select()->from($this->valueTable($storageType), [new Expr('1')])->where('attribute_id = ?', $attributeId)->limit(1));
    }

    /** table names can't be bound, so the storage type is checked against the fixed list */
    private function valueTable(string $storageType): string
    {
        if (!in_array($storageType, InputTypeRegistry::STORAGE_TYPES, true)) {
            throw new \InvalidArgumentException("storage_type inválido: {$storageType}");
        }

        return 'eav_value_' . $storageType;
    }

    /**
     * iter_attr_key() of each name (lowercase, no accents or punctuation, singular, sorted words), in order
     *
     * @param string[] $names
     * @return string[]
     */
    public function nameKeys(array $names): array
    {
        return $names ? $this->getConnection()->fetchCol(
            'SELECT iter_attr_key(value) FROM json_array_elements_text(CAST(? AS json)) WITH ORDINALITY ORDER BY ordinality',
            [json_encode(array_values($names))]
        ) : [];
    }

    /** @return array<int, array{attribute_id: int, code: string, label: string, code_key: string, label_key: string}> */
    public function getNameKeys(int $entityTypeId): array
    {
        return $this->getConnection()->fetchAll(
            'SELECT attribute_id, code, label, iter_attr_key(code) AS code_key, iter_attr_key(label) AS label_key
             FROM eav_attribute WHERE entity_type_id = ?',
            [$entityTypeId]
        );
    }

    public function codeExists(int $entityTypeId, string $code): bool
    {
        $db = $this->getConnection();

        return (bool) $db->fetchOne($db->select()->from('eav_attribute', [new Expr('1')])
            ->where('entity_type_id = ?', $entityTypeId)->where('code = ?', $code));
    }
}
