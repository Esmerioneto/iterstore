<?php

declare(strict_types=1);

namespace Iter\Eav\Model\ResourceModel;

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

    /** @return array<string, array{entity_type_id: int, label: string}> code => row */
    public function getEntityTypes(): array
    {
        $db = $this->getConnection();

        return array_column($db->fetchAll($db->select()->from('eav_entity_type', ['code', 'entity_type_id', 'label'])->order('label')), null, 'code');
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
        // ponytail: UNION over the value tables stays SQL, Select has no UNION
        $union = implode(' UNION ALL ', array_map(
            static fn ($storage) => "SELECT attribute_id, entity_id, value::text FROM eav_value_{$storage}",
            InputTypeRegistry::STORAGE_TYPES
        ));

        return $this->getConnection()->fetchAll(
            "SELECT a.code, a.storage_type, a.input_type, v.value FROM ({$union}) v
             JOIN eav_attribute a ON a.attribute_id = v.attribute_id
             WHERE a.entity_type_id = :entity_type_id AND v.entity_id = :entity_id",
            ['entity_type_id' => $entityTypeId, 'entity_id' => $entityId]
        );
    }

    /**
     * One transaction for the whole entity: a null value deletes the row.
     *
     * @param array<int, array{attribute_id: int, storage_type: string, value: string|int|null}> $rows
     */
    public function saveValues(int $entityId, array $rows): void
    {
        $this->getConnection()->transaction(function ($db) use ($entityId, $rows): void {
            foreach ($rows as $row) {
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
        foreach (InputTypeRegistry::STORAGE_TYPES as $storage) {
            $this->getConnection()->delete('eav_value_' . $storage, [
                'attribute_id IN (SELECT attribute_id FROM eav_attribute WHERE entity_type_id = ?)' => $entityTypeId,
                'entity_id = ?' => $entityId,
            ]);
        }
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

    public function codeExists(int $entityTypeId, string $code): bool
    {
        $db = $this->getConnection();

        return (bool) $db->fetchOne($db->select()->from('eav_attribute', [new Expr('1')])
            ->where('entity_type_id = ?', $entityTypeId)->where('code = ?', $code));
    }
}
