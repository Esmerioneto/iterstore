<?php

declare(strict_types=1);

namespace Iter\Eav\View;

use Iter\Core\View\ViewComponentInterface;
use Iter\Eav\InputTypeRegistry;
use Iter\Eav\Model\Attribute;
use Iter\Eav\Model\AttributeSet;

class AttributeSetForm implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'eav/attribute-set-form';
    }

    public function getData(): array
    {
        $attributeResource = (new Attribute())->getResource();
        $entityTypes = $attributeResource->getEntityTypes();

        $id = !empty($_GET['id']) ? (int) $_GET['id'] : null;
        $model = new AttributeSet();
        $set = $id && $model->load($id)->getId() ? $model->getData() : null;

        $old = $_SESSION['iter_old_input'] ?? null;
        unset($_SESSION['iter_old_input']);

        // one row per attribute of the entity type: set members first, in display order
        $rows = [];
        if ($set) {
            foreach ($model->getResource()->getItems($id) as $item) {
                $rows[$item['attribute_id']] = $item + ['in' => 1, 'group' => (string) $item['group_name'], 'sort_order' => $item['item_sort_order']];
            }
            foreach ($attributeResource->getByEntityType((int) $set['entity_type_id']) as $attribute) {
                $rows[$attribute['attribute_id']] ??= $attribute + ['in' => 0, 'group' => '', 'sort_order' => ''];
            }
            // after a failed save, show what was typed
            foreach ($old['items'] ?? [] as $attributeId => $posted) {
                if (isset($rows[$attributeId]) && is_array($posted)) {
                    $rows[$attributeId] = ['in' => empty($posted['in']) ? 0 : 1, 'group' => (string) ($posted['group'] ?? ''), 'sort_order' => (string) ($posted['sort_order'] ?? '')] + $rows[$attributeId];
                }
            }
        }
        unset($old['items']);

        return [
            'is_edit' => $set !== null,
            'attribute_set_id' => $id,
            'values' => array_merge(
                ['entity_type' => (string) ($_GET['entity_type'] ?? ''), 'code' => '', 'label' => '', 'is_default' => 0],
                $set ?? [],
                $old ?? [],
                // immutable after creation
                $set ? ['entity_type' => array_column($entityTypes, 'code', 'entity_type_id')[$set['entity_type_id']] ?? '', 'code' => $set['code'], 'is_default' => $set['is_default']] : []
            ),
            'rows' => $rows,
            'groups' => array_values(array_unique(array_filter(array_column($rows, 'group')))),
            'entity_types' => $entityTypes,
            'input_types' => InputTypeRegistry::all(),
        ];
    }
}
