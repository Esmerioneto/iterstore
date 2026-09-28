<?php

declare(strict_types=1);

namespace Iter\Eav\Model\ResourceModel;

use Iter\Core\Db\Expr;
use Iter\Core\Model\ResourceModel\AbstractDb;

class AttributeSet extends AbstractDb
{
    protected function _construct(): void
    {
        $this->init('eav_attribute_set', 'attribute_set_id');
    }

    /** @return array<int, array<string, mixed>> sets with entity label and attribute count */
    public function getList(?string $orderBy = null): array
    {
        $db = $this->getConnection();

        return $db->fetchAll($db->select()
            ->from(['s' => 'eav_attribute_set'])
            ->join(['et' => 'eav_entity_type'], 'et.entity_type_id = s.entity_type_id', ['entity_type' => 'code', 'entity_type_label' => 'label'])
            ->columns(['attribute_count' => '(SELECT COUNT(*) FROM eav_attribute_set_item i WHERE i.attribute_set_id = s.attribute_set_id)'])
            ->order('et.label, s.is_default DESC, s.label'));
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
        $db = $this->getConnection();

        return $db->fetchAll($db->select()
            ->from(['i' => 'eav_attribute_set_item'], ['group_name', 'item_sort_order' => 'sort_order'])
            ->join(['a' => 'eav_attribute'], 'a.attribute_id = i.attribute_id', [])
            ->columns(['a.*'])
            ->where('i.attribute_set_id = ?', $setId)
            ->order(new Expr('i.group_name IS NULL, MIN(i.sort_order) OVER (PARTITION BY i.group_name), i.group_name, i.sort_order, a.code')));
    }

    /** @param array<int, array{attribute_id: int, group_name: ?string, sort_order: int}> $items */
    public function replaceItems(int $setId, array $items): void
    {
        $this->getConnection()->transaction(static function ($db) use ($setId, $items): void {
            $db->delete('eav_attribute_set_item', ['attribute_set_id = ?' => $setId]);
            $db->insertMultiple('eav_attribute_set_item', array_map(static fn ($item) => ['attribute_set_id' => $setId] + $item, $items));
        });
    }

    /** the set $code of the entity type, or its default set when $code is null */
    public function findId(string $entityType, ?string $code): ?int
    {
        $db = $this->getConnection();
        $select = $db->select()->from(['s' => 'eav_attribute_set'], ['attribute_set_id'])
            ->join(['et' => 'eav_entity_type'], 'et.entity_type_id = s.entity_type_id', [])
            ->where('et.code = ?', $entityType);
        $code === null ? $select->where('s.is_default = 1') : $select->where('s.code = ?', $code);
        $id = $db->fetchOne($select);

        return $id === false ? null : (int) $id;
    }

    public function codeExists(int $entityTypeId, string $code): bool
    {
        $db = $this->getConnection();

        return (bool) $db->fetchOne($db->select()->from('eav_attribute_set', [new Expr('1')])
            ->where('entity_type_id = ?', $entityTypeId)->where('code = ?', $code));
    }

    /**
     * Runs before saving a new default: the partial unique index allows only
     * one default per entity type, so the old one must be cleared first.
     */
    public function clearDefault(int $entityTypeId): void
    {
        $this->getConnection()->update('eav_attribute_set', ['is_default' => 0], ['entity_type_id = ?' => $entityTypeId]);
    }
}
