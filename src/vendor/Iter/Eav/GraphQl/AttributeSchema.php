<?php

declare(strict_types=1);

namespace Iter\Eav\GraphQl;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use Iter\Admin\Auth;
use Iter\Eav\InputTypeRegistry;
use Iter\Eav\Model\Attribute;
use Iter\Eav\Model\AttributeException;

/**
 * Attribute definitions for the storefront (labels, input types, options), so it can build its
 * forms and filters without hardcoding them. Public: only definitions, never values. Hidden
 * attributes (is_visible = 0) only reach an admin with the Atributos resource.
 */
class AttributeSchema
{
    /** @return array{query: array, mutation: array} */
    public static function fields(): array
    {
        $optionType = new ObjectType([
            'name' => 'AttributeOption',
            'fields' => ['value' => Type::nonNull(Type::string()), 'label' => Type::nonNull(Type::string()), 'isDefault' => Type::nonNull(Type::boolean())],
        ]);
        $attributeType = new ObjectType([
            'name' => 'AttributeDefinition',
            'fields' => [
                'code' => Type::nonNull(Type::string()),
                'label' => Type::nonNull(Type::string()),
                'inputType' => ['type' => Type::nonNull(Type::string()), 'description' => 'text, textarea, select, multiselect, boolean, date, phone, cep, email, cpf_cnpj, image...'],
                'multiple' => ['type' => Type::nonNull(Type::boolean()), 'description' => 'true: o valor é uma lista (values).'],
                'mask' => ['type' => Type::string(), 'description' => 'Máscara do campo: phone, cep, cpf_cnpj ou email.'],
                'defaultValue' => Type::string(),
                'isRequired' => Type::nonNull(Type::boolean()),
                'isVisible' => Type::nonNull(Type::boolean()),
                'isSearchable' => Type::nonNull(Type::boolean()),
                'isFilterable' => Type::nonNull(Type::boolean()),
                'isSortable' => Type::nonNull(Type::boolean()),
                'isVariant' => ['type' => Type::nonNull(Type::boolean()), 'description' => '"Usar na grade" (produtos com variações).'],
                'isSystem' => Type::nonNull(Type::boolean()),
                'sortOrder' => Type::nonNull(Type::int()),
                'options' => ['type' => Type::nonNull(Type::listOf(Type::nonNull($optionType))), 'description' => 'Vazio nos tipos sem opções.'],
            ],
        ]);

        return [
            'query' => [
                'attributes' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($attributeType))),
                    'description' => 'Os atributos de uma entidade (product, customer, customer_address, category), na ordem de posição. Os ocultos só aparecem para o admin.',
                    'args' => ['entityType' => Type::nonNull(Type::string())],
                    'resolve' => static function ($root, array $args): array {
                        $resource = (new Attribute())->getResource();
                        $entityType = $resource->getEntityTypes()[$args['entityType']] ?? throw new AttributeException('ENTITY_TYPE_INVALID', "Entidade '{$args['entityType']}' não existe.");
                        $admin = Auth::isAllowed('Iter_Eav::attribute');
                        $rows = [];
                        foreach ($resource->getByEntityType((int) $entityType['entity_type_id']) as $a) {
                            if (!$a['is_visible'] && !$admin) {
                                continue;
                            }
                            $type = InputTypeRegistry::has($a['input_type']) ? InputTypeRegistry::get($a['input_type']) : ['supports_options' => false, 'multiple' => false];
                            $rows[] = [
                                'code' => $a['code'],
                                'label' => $a['label'],
                                'inputType' => $a['input_type'],
                                'multiple' => (bool) $type['multiple'],
                                'mask' => ($type['mask'] ?? '') ?: null,
                                'defaultValue' => $a['default_value'],
                                'isRequired' => (bool) $a['is_required'],
                                'isVisible' => (bool) $a['is_visible'],
                                'isSearchable' => (bool) $a['is_searchable'],
                                'isFilterable' => (bool) $a['is_filterable'],
                                'isSortable' => (bool) $a['is_sortable'],
                                'isVariant' => (bool) ($a['is_variant'] ?? false),
                                'isSystem' => (bool) $a['is_system'],
                                'sortOrder' => (int) $a['sort_order'],
                                'options' => $type['supports_options'] ? array_map(static fn ($o) => ['value' => $o['value'], 'label' => $o['label'], 'isDefault' => (bool) $o['is_default']],
                                    $resource->getOptions((int) $a['attribute_id'])) : [],
                            ];
                        }

                        return $rows;
                    },
                ],
            ],
            'mutation' => [],
        ];
    }
}
