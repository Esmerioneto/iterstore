<?php

declare(strict_types=1);

namespace Iter\Eav\Model\ResourceModel;

use Iter\Core\Model\ResourceModel\AbstractDb;
use Iter\Eav\Model\ResourceModel\Attribute\Collection;
use Iter\Eav\InputTypeRegistry;
use PDO;

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
        $rows = $this->getConnection()
            ->query('SELECT code, entity_type_id, label FROM eav_entity_type ORDER BY label')
            ->fetchAll(PDO::FETCH_ASSOC);

        return array_column($rows, null, 'code');
    }

    /** @return array<int, array{value: string, label: string, sort_order: int, is_default: int}> */
    public function getOptions(int $attributeId): array
    {
        $stmt = $this->getConnection()->prepare(
            'SELECT value, label, sort_order, is_default FROM eav_attribute_option
             WHERE attribute_id = :id ORDER BY sort_order, option_id'
        );
        $stmt->execute(['id' => $attributeId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Makes the attribute's options exactly $options, matched by value, so
     * an option keeps its option_id while its label/position change.
     *
     * @param array<int, array{value: string, label: string, sort_order: int, is_default: int}> $options
     */
    public function replaceOptions(int $attributeId, array $options): void
    {
        $pdo = $this->getConnection();
        $pdo->beginTransaction();
        try {
            $values = array_column($options, 'value');
            $keep = $values ? ' AND value NOT IN (' . implode(', ', array_fill(0, count($values), '?')) . ')' : '';
            $pdo->prepare("DELETE FROM eav_attribute_option WHERE attribute_id = ?{$keep}")
                ->execute([$attributeId, ...$values]);

            $upsert = $pdo->prepare(
                'INSERT INTO eav_attribute_option (attribute_id, value, label, sort_order, is_default)
                 VALUES (:attribute_id, :value, :label, :sort_order, :is_default)
                 ON CONFLICT (attribute_id, value) DO UPDATE
                 SET label = EXCLUDED.label, sort_order = EXCLUDED.sort_order, is_default = EXCLUDED.is_default'
            );
            foreach ($options as $option) {
                $upsert->execute(['attribute_id' => $attributeId] + $option);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** @return array<int, array<string, mixed>> the entity type's attributes by position */
    public function getByEntityType(int $entityTypeId): array
    {
        $stmt = $this->getConnection()->prepare(
            'SELECT * FROM eav_attribute WHERE entity_type_id = :id ORDER BY sort_order, code'
        );
        $stmt->execute(['id' => $entityTypeId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int, array{code: string, storage_type: string, input_type: string, value: string}> raw values, as text */
    public function getValues(int $entityTypeId, int $entityId): array
    {
        $union = implode(' UNION ALL ', array_map(
            static fn ($storage) => "SELECT attribute_id, entity_id, value::text FROM eav_value_{$storage}",
            InputTypeRegistry::STORAGE_TYPES
        ));
        $stmt = $this->getConnection()->prepare(
            "SELECT a.code, a.storage_type, a.input_type, v.value FROM ({$union}) v
             JOIN eav_attribute a ON a.attribute_id = v.attribute_id
             WHERE a.entity_type_id = :entity_type_id AND v.entity_id = :entity_id"
        );
        $stmt->execute(['entity_type_id' => $entityTypeId, 'entity_id' => $entityId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * One transaction for the whole entity: a null value deletes the row.
     *
     * @param array<int, array{attribute_id: int, storage_type: string, value: string|int|null}> $rows
     */
    public function saveValues(int $entityId, array $rows): void
    {
        $pdo = $this->getConnection();
        $pdo->beginTransaction();
        try {
            foreach ($rows as $row) {
                $table = $this->valueTable($row['storage_type']);
                $params = ['attribute_id' => $row['attribute_id'], 'entity_id' => $entityId];
                if ($row['value'] === null) {
                    $pdo->prepare("DELETE FROM {$table} WHERE attribute_id = :attribute_id AND entity_id = :entity_id")->execute($params);
                } else {
                    $pdo->prepare(
                        "INSERT INTO {$table} (attribute_id, entity_id, value) VALUES (:attribute_id, :entity_id, :value)
                         ON CONFLICT (attribute_id, entity_id) DO UPDATE SET value = EXCLUDED.value"
                    )->execute($params + ['value' => $row['value']]);
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function deleteValues(int $entityTypeId, int $entityId): void
    {
        foreach (InputTypeRegistry::STORAGE_TYPES as $storage) {
            $this->getConnection()->prepare(
                "DELETE FROM eav_value_{$storage} v USING eav_attribute a
                 WHERE a.attribute_id = v.attribute_id AND a.entity_type_id = :entity_type_id AND v.entity_id = :entity_id"
            )->execute(['entity_type_id' => $entityTypeId, 'entity_id' => $entityId]);
        }
    }

    public function hasValues(int $attributeId, string $storageType): bool
    {
        $stmt = $this->getConnection()->prepare(
            "SELECT 1 FROM {$this->valueTable($storageType)} WHERE attribute_id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $attributeId]);

        return (bool) $stmt->fetchColumn();
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
        $stmt = $this->getConnection()->prepare(
            'SELECT 1 FROM eav_attribute WHERE entity_type_id = :entity_type_id AND code = :code'
        );
        $stmt->execute(['entity_type_id' => $entityTypeId, 'code' => $code]);

        return (bool) $stmt->fetchColumn();
    }
}
