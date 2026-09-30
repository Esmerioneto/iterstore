<?php

declare(strict_types=1);

namespace Iter\Eav\View;

use Iter\Core\View\ViewComponentInterface;
use Iter\Eav\InputTypeRegistry;
use Iter\Eav\Model\Attribute;
use Iter\Eav\Model\AttributeService;

class AttributeForm implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'eav/attribute-form';
    }

    public function getData(): array
    {
        $model = new Attribute();
        $entityTypes = $model->getResource()->getEntityTypes();

        $id = !empty($_GET['id']) ? (int) $_GET['id'] : null;
        $attribute = null;
        if ($id) {
            $attribute = $model->load($id)->getId() ? $model->getData() : null;
            if ($attribute) {
                $codes = array_column($entityTypes, 'code', 'entity_type_id');
                $attribute['entity_type'] = $codes[$attribute['entity_type_id']] ?? '';
            }
        }

        $old = $_SESSION['iter_old_input'] ?? null;
        unset($_SESSION['iter_old_input']);

        $options = isset($old['options'])
            ? array_filter($old['options'], static fn (array $o) => trim($o['value'] ?? '') !== '' || trim($o['label'] ?? '') !== '')
            : ($attribute ? (new AttributeService())->getOptions($id) : []);
        unset($old['options']);
        // one blank row; "+ Adicionar opção" (admin.js data-add-row) copies it for more
        $options[] = ['value' => '', 'label' => '', 'sort_order' => '', 'is_default' => 0];

        return [
            'is_edit' => $attribute !== null,
            'is_system' => (int) ($attribute['is_system'] ?? 0) === 1,
            'attribute_id' => $id,
            'values' => array_merge(
                [
                    'entity_type' => (string) ($_GET['entity_type'] ?? ''), 'code' => '', 'label' => '', 'input_type' => 'text', 'default_value' => '',
                    'is_required' => 0, 'is_visible' => 1, 'is_searchable' => 0, 'is_filterable' => 0,
                    'is_sortable' => 0, 'is_variant' => 0, 'is_unique' => 0, 'sort_order' => 0, 'storage_type' => '',
                ],
                $attribute ?? [],
                $old ?? [],
                // immutable after creation: old input can't blank them out
                array_intersect_key($attribute ?? [], array_flip(['entity_type', 'code', 'storage_type']))
            ),
            'options' => $options,
            'entity_types' => $entityTypes,
            'input_types' => InputTypeRegistry::all(),
        ];
    }
}
