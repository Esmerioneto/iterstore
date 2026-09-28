<?php

declare(strict_types=1);

namespace Iter\Eav\Model\ResourceModel;

use Iter\Core\Model\ResourceModel\AbstractDb;
use PDO;

class AttributeSet extends AbstractDb
{
    protected function _construct(): void
    {
        $this->init('eav_attribute_set', 'attribute_set_id');
    }

    /** @return array<int, array<string, mixed>> sets with entity label and attribute count */
    public function getList(?string $orderBy = null): array
    {
        return $this->getConnection()->query(
            'SELECT s.*, et.code AS entity_type, et.label AS entity_type_label,
                    (SELECT COUNT(*) FROM eav_attribute_set_item i WHERE i.attribute_set_id = s.attribute_set_id) AS attribute_count
             FROM eav_attribute_set s
             JOIN eav_entity_type et ON et.entity_type_id = s.entity_type_id
             ORDER BY et.label, s.is_default DESC, s.label'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The set's attributes in display order: groups ordered by their lowest
     * item position (ungrouped last), then items by position. This is what a
     * form generator walks.
     *
     * @return array<int, array<string, mixed>> eav_attribute rows + group_name, item_sort_order
     */
    public function getItems(int $setId): array
    {
        $stmt = $this->getConnection()->prepare(
            'SELECT a.*, i.group_name, i.sort_order AS item_sort_order
             FROM eav_attribute_set_item i
             JOIN eav_attribute a ON a.attribute_id = i.attribute_id
             WHERE i.attribute_set_id = :id
             ORDER BY i.group_name IS NULL, MIN(i.sort_order) OVER (PARTITION BY i.group_name), i.group_name,
                      i.sort_order, a.code'
        );
        $stmt->execute(['id' => $setId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<int, array{attribute_id: int, group_name: ?string, sort_order: int}> $items */
    public function replaceItems(int $setId, array $items): void
    {
        $pdo = $this->getConnection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM eav_attribute_set_item WHERE attribute_set_id = :id')->execute(['id' => $setId]);
            $insert = $pdo->prepare(
                'INSERT INTO eav_attribute_set_item (attribute_set_id, attribute_id, group_name, sort_order)
                 VALUES (:attribute_set_id, :attribute_id, :group_name, :sort_order)'
            );
            foreach ($items as $item) {
                $insert->execute(['attribute_set_id' => $setId] + $item);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function codeExists(int $entityTypeId, string $code): bool
    {
        $stmt = $this->getConnection()->prepare(
            'SELECT 1 FROM eav_attribute_set WHERE entity_type_id = :entity_type_id AND code = :code'
        );
        $stmt->execute(['entity_type_id' => $entityTypeId, 'code' => $code]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Runs before saving a new default: the partial unique index allows only
     * one default per entity type, so the old one must be cleared first.
     */
    public function clearDefault(int $entityTypeId): void
    {
        $this->getConnection()
            ->prepare('UPDATE eav_attribute_set SET is_default = 0 WHERE entity_type_id = :entity_type_id')
            ->execute(['entity_type_id' => $entityTypeId]);
    }
}
