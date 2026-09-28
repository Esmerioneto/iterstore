<?php

declare(strict_types=1);

namespace Iter\Eav\Form;

use Iter\Eav\InputTypeRegistry;
use Iter\Eav\Model\Attribute;
use Iter\Eav\Model\AttributeException;
use Iter\Eav\Model\AttributeSet;

/**
 * Turns an attribute set into a neutral form schema (no HTML), in the set's
 * display order. Hidden attributes (is_visible = 0) are left out.
 *
 * $overrides is the form-specific configuration, keyed by attribute code:
 * an array is merged over the field (["cpf" => ["required" => false]]),
 * false removes the field — same convention as the layout JSON.
 */
class FormSchemaGenerator
{
    /**
     * @return array{entity_type: string, attribute_set: int, fields: array<int, array{name: string, type: string,
     *               label: string, required: bool, default: string|array|null, group: ?string, multiple: bool,
     *               options?: array<int, array{value: string, label: string}>, accept?: array<int, string>}>}
     */
    public function generate(string $entityType, ?string $setCode = null, array $overrides = []): array
    {
        $set = new AttributeSet();
        $setId = $set->getResource()->findId($entityType, $setCode);
        if ($setId === null) {
            throw new AttributeException('ATTRIBUTE_SET_NOT_FOUND', "Conjunto '" . ($setCode ?? 'padrão') . "' não encontrado para '{$entityType}'.");
        }

        $fields = [];
        foreach ($set->getResource()->getItems($setId) as $attribute) {
            $override = $overrides[$attribute['code']] ?? [];
            if (!$attribute['is_visible'] || $override === false) {
                continue;
            }
            $fields[] = array_replace($this->field($attribute), $override);
        }

        return ['entity_type' => $entityType, 'attribute_set' => $setId, 'fields' => $fields];
    }

    // ponytail: the spec's FieldFactory (one class per type) is this method; fields are plain arrays
    private function field(array $attribute): array
    {
        $type = InputTypeRegistry::has($attribute['input_type'])
            ? InputTypeRegistry::get($attribute['input_type'])
            : ['supports_options' => false, 'multiple' => false, 'accept' => []]; // type of a disabled module: plain field

        $field = [
            'name' => $attribute['code'],
            'type' => $attribute['input_type'],
            'label' => $attribute['label'],
            'required' => (bool) $attribute['is_required'],
            'default' => $attribute['default_value'],
            'group' => $attribute['group_name'],
            'multiple' => $type['multiple'],
        ];
        if ($type['mask'] ?? '') {
            $field['mask'] = $type['mask'];
        }
        if ($type['accept']) {
            $field['accept'] = array_keys($type['accept']);
        }
        if ($type['supports_options']) {
            $options = (new Attribute())->getResource()->getOptions((int) $attribute['attribute_id']);
            $field['options'] = array_map(static fn ($o) => ['value' => $o['value'], 'label' => $o['label']], $options);
            $defaults = array_column(array_filter($options, static fn ($o) => $o['is_default']), 'value');
            if ($defaults) {
                $field['default'] = $type['multiple'] ? $defaults : $defaults[0];
            }
        }

        return $field;
    }
}
