<?php

declare(strict_types=1);

namespace Iter\Catalog\GraphQl;

use GraphQL\Error\UserError;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use Iter\Admin\Auth;
use Iter\Catalog\Model\Category;
use Iter\Catalog\Model\Product;
use Iter\Catalog\Model\ProductService;
use Iter\Eav\Form\FormSchemaGenerator;
use Iter\Eav\InputTypeRegistry;
use Iter\Eav\Model\Attribute;
use Iter\Eav\Model\AttributeException;
use Iter\Eav\Model\AttributeSet;
use Iter\Eav\Model\ValueService;

/**
 * Product fields for Iter\Core\GraphQl\Schema. EAV values go as a list of
 * {code, value | values}: single-value types use value, multiple ones (multiselect)
 * values; option types also give the options' labels, file types (image) their url.
 *
 * Reads are public but only see active products; an admin session with the
 * Produtos resource sees all and can write. Files can't be uploaded here:
 * image attributes are kept as they are.
 *
 * Categories are read only here (edited in the admin); a guest sees a category
 * when it and all its parents are active.
 */
class ProductSchema
{
    /**
     * @param array $productFields extra fields of the Product type, from other modules (e.g. Inventory's stock)
     * @return array{query: array, mutation: array}
     */
    public static function fields(array $productFields = []): array
    {
        $service = new ProductService();
        $isAdmin = static fn (): bool => Auth::isAllowed('Iter_Catalog::product');
        $guard = static fn (callable $resolve) => static function (...$args) use ($resolve, $isAdmin) {
            if (!$isAdmin()) {
                throw new UserError('Acesso negado.');
            }

            return $resolve(...$args);
        };

        // input type and options by attribute code, loaded once per request
        $attributeRows = null;
        $optionLabels = [];
        $typeOf = static function (string $code) use (&$attributeRows): ?array {
            $resource = (new Attribute())->getResource();
            $attributeRows ??= array_column($resource->getByEntityType((int) $resource->getEntityTypes()[Product::ENTITY_TYPE]['entity_type_id']), null, 'code');
            $inputType = $attributeRows[$code]['input_type'] ?? '';

            return InputTypeRegistry::has($inputType) ? InputTypeRegistry::get($inputType) : null;
        };
        /** @return ?array value => label, null when the attribute has no options */
        $optionsOf = static function (string $code) use ($typeOf, &$attributeRows, &$optionLabels): ?array {
            if (!($typeOf($code)['supports_options'] ?? false)) {
                return null;
            }
            $id = (int) $attributeRows[$code]['attribute_id'];

            return $optionLabels[$id] ??= array_column((new Attribute())->getResource()->getOptions($id), 'label', 'value');
        };
        // the category tree, loaded once per request: id => row + children ids
        $tree = null;
        $categories = static function () use (&$tree, $isAdmin): array {
            if ($tree === null) {
                $tree = [];
                foreach ((new Category())->getResource()->getTree() as $row) { // parents come before their children
                    $parent = $row['parent_id'] === null ? null : (int) $row['parent_id'];
                    if (($parent !== null && !isset($tree[$parent])) || (!$row['is_active'] && !$isAdmin())) {
                        continue;
                    }
                    $tree[(int) $row['category_id']] = $row + ['children' => []];
                    if ($parent !== null) {
                        $tree[$parent]['children'][] = (int) $row['category_id'];
                    }
                }
            }

            return $tree;
        };
        $productList = null; // ProductList, set below; Category.products uses it
        $categoryType = new ObjectType([
            'name' => 'Category',
            'fields' => static function () use (&$categoryType, &$productList, $categories, $isAdmin): array {
                return [
                    'id' => ['type' => Type::nonNull(Type::id()), 'resolve' => fn ($c) => $c['category_id']],
                    'name' => Type::nonNull(Type::string()),
                    'url_key' => Type::nonNull(Type::string()),
                    'is_active' => ['type' => Type::nonNull(Type::boolean()), 'resolve' => fn ($c) => (bool) $c['is_active']],
                    'position' => Type::nonNull(Type::int()),
                    'level' => ['type' => Type::nonNull(Type::int()), 'description' => '0 = categoria principal.'],
                    'parent_id' => Type::id(),
                    'children' => [
                        'type' => Type::nonNull(Type::listOf(Type::nonNull($categoryType))),
                        'resolve' => static fn ($c) => array_map(static fn ($id) => $categories()[$id], $c['children']),
                    ],
                    'products' => [
                        'type' => Type::nonNull($productList),
                        'description' => 'Inclui os produtos das subcategorias.',
                        'args' => [
                            'page' => ['type' => Type::int(), 'defaultValue' => 1],
                            'pageSize' => ['type' => Type::int(), 'defaultValue' => 20, 'description' => 'Máximo 100.'],
                        ],
                        'resolve' => static fn ($c, array $args) => (new Product())->getResource()->search([
                            'category_id' => (string) $c['category_id'],
                            'is_active' => $isAdmin() ? '' : '1',
                        ], max(1, $args['page']), min(100, max(1, $args['pageSize']))),
                    ],
                    'created_at' => Type::nonNull(Type::string()),
                    'updated_at' => Type::nonNull(Type::string()),
                ];
            },
        ]);
        $attributeType = new ObjectType([
            'name' => 'ProductAttribute',
            'fields' => [
                'code' => Type::nonNull(Type::string()),
                'value' => ['type' => Type::string(), 'description' => 'Valor de atributos de valor único (Sim/Não = "1"/"0").'],
                'values' => ['type' => Type::listOf(Type::nonNull(Type::string())), 'description' => 'Valores de atributos de múltipla seleção.'],
                'option_label' => ['type' => Type::string(), 'description' => 'Rótulo da opção escolhida, em atributos de opções (Seleção, Opções).'],
                'option_labels' => ['type' => Type::listOf(Type::nonNull(Type::string())), 'description' => 'Rótulos das opções escolhidas, na mesma ordem de values (Múltipla seleção).'],
                'url' => ['type' => Type::string(), 'description' => 'URL do arquivo, em atributos de arquivo (imagem).'],
            ],
        ]);
        $productType = new ObjectType([
            'name' => 'Product',
            'fields' => [
                'id' => ['type' => Type::nonNull(Type::id()), 'resolve' => fn ($p) => $p['product_id']],
                'sku' => Type::nonNull(Type::string()),
                'name' => Type::nonNull(Type::string()),
                'is_active' => ['type' => Type::nonNull(Type::boolean()), 'resolve' => fn ($p) => (bool) $p['is_active']],
                'attribute_set_id' => Type::nonNull(Type::id()),
                'created_at' => Type::nonNull(Type::string()),
                'updated_at' => Type::nonNull(Type::string()),
                'attributes' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($attributeType))),
                    // ponytail: one values query per product (N+1), capped by pageSize; batch if lists get slow
                    'resolve' => static function ($p) use ($service, $typeOf, $optionsOf): array {
                        $rows = [];
                        foreach ($service->getValues((int) $p['product_id']) as $code => $value) {
                            $options = $optionsOf($code);
                            // an option removed after being chosen shows its value
                            $labels = $options === null ? null : array_map(static fn ($v) => $options[$v] ?? (string) $v, (array) $value);
                            $rows[] = [
                                'code' => $code,
                                'value' => is_array($value) ? null : (is_bool($value) ? ($value ? '1' : '0') : (string) $value),
                                'values' => is_array($value) ? $value : null,
                                'option_label' => $labels !== null && !is_array($value) ? $labels[0] : null,
                                'option_labels' => $labels !== null && is_array($value) ? $labels : null,
                                'url' => ($typeOf($code)['accept'] ?? false) ? ValueService::MEDIA_URL . $value : null,
                            ];
                        }

                        return $rows;
                    },
                ],
                'categories' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($categoryType))),
                    // ponytail: one query per product (N+1), capped by pageSize
                    'resolve' => static fn ($p) => array_values(array_intersect_key($categories(), array_flip($service->getCategoryIds((int) $p['product_id'])))),
                ],
            ] + $productFields,
        ]);
        $productList = new ObjectType([
            'name' => 'ProductList',
            'fields' => [
                'items' => Type::nonNull(Type::listOf(Type::nonNull($productType))),
                'total' => Type::nonNull(Type::int()),
            ],
        ]);
        $attributeInput = new InputObjectType([
            'name' => 'ProductAttributeInput',
            'fields' => [
                'code' => Type::nonNull(Type::string()),
                'value' => ['type' => Type::string(), 'description' => 'Valor único; vazio ou null apaga.'],
                'values' => ['type' => Type::listOf(Type::nonNull(Type::string())), 'description' => 'Para múltipla seleção; [] apaga.'],
            ],
        ]);

        // null = not found, or inactive for a public read
        $load = static function (int $id) use ($isAdmin): ?array {
            $product = (new Product())->load($id);
            return $product->getId() && ($product->getData('is_active') || $isAdmin()) ? $product->getData() : null;
        };
        // the admin form only posts the set's fields; here a code outside the set is a client error, not ignored
        $attributes = static function (array $args, int $setId): array {
            $values = array_column(array_map(
                static fn ($a) => ['code' => $a['code'], 'value' => $a['values'] ?? $a['value'] ?? ''],
                $args['attributes'] ?? []
            ), 'value', 'code');
            $set = (new AttributeSet())->load($setId);
            if ($values && $set->getId()) { // invalid set: ProductService reports it
                $fields = array_column((new FormSchemaGenerator())->generate(Product::ENTITY_TYPE, $set->getData('code'))['fields'], 'name');
                $unknown = array_diff(array_keys($values), $fields);
                if ($unknown) {
                    throw new AttributeException('ATTRIBUTE_NOT_FOUND', 'Atributo fora do conjunto do produto: ' . implode(', ', $unknown) . '.');
                }
            }

            return $values;
        };
        $data = static fn (array $args, array $current = []): array => [
            'sku' => $args['sku'] ?? $current['sku'] ?? '',
            'name' => $args['name'] ?? $current['name'] ?? '',
            'is_active' => $args['isActive'] ?? $current['is_active'] ?? true,
        ] + (isset($args['categoryIds']) ? ['category_ids' => $args['categoryIds']] : []); // omitted = categories unchanged

        return [
            'query' => [
                'product' => [
                    'type' => $productType,
                    'description' => 'Por id ou por SKU (sem diferenciar maiúsculas); informe um dos dois.',
                    'args' => ['id' => Type::id(), 'sku' => Type::string()],
                    'resolve' => static function ($root, array $args) use ($load): ?array {
                        if (isset($args['id']) === isset($args['sku'])) {
                            throw new UserError('Informe id ou sku.');
                        }
                        $id = isset($args['id']) ? (int) $args['id'] : (new Product())->getResource()->findIdBySku($args['sku']);

                        return $id === null ? null : $load($id);
                    },
                ],
                'categories' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($categoryType))),
                    'description' => 'As categorias principais; as outras vêm em children.',
                    'resolve' => static fn () => array_values(array_filter($categories(), static fn ($c) => $c['parent_id'] === null)),
                ],
                'category' => [
                    'type' => $categoryType,
                    'description' => 'Por id ou por url_key; informe um dos dois.',
                    'args' => ['id' => Type::id(), 'url_key' => Type::string()],
                    'resolve' => static function ($root, array $args) use ($categories): ?array {
                        if (isset($args['id']) === isset($args['url_key'])) {
                            throw new UserError('Informe id ou url_key.');
                        }
                        $id = isset($args['id']) ? (int) $args['id'] : (new Category())->getResource()->findIdByUrlKey($args['url_key']);

                        return $categories()[$id] ?? null;
                    },
                ],
                'products' => [
                    'type' => Type::nonNull($productList),
                    'args' => [
                        'search' => ['type' => Type::string(), 'description' => 'Parte do SKU ou do nome.'],
                        'attributeSetId' => Type::id(),
                        'categoryId' => ['type' => Type::id(), 'description' => 'Inclui as subcategorias.'],
                        'isActive' => ['type' => Type::boolean(), 'description' => 'Só para o admin; fora dele a lista traz apenas produtos ativos.'],
                        'page' => ['type' => Type::int(), 'defaultValue' => 1],
                        'pageSize' => ['type' => Type::int(), 'defaultValue' => 20, 'description' => 'Máximo 100.'],
                    ],
                    'resolve' => static function ($root, array $args) use ($isAdmin): array {
                        $active = $isAdmin() ? ($args['isActive'] ?? null) : true;
                        return (new Product())->getResource()->search([
                            'q' => (string) ($args['search'] ?? ''),
                            'attribute_set_id' => (string) ($args['attributeSetId'] ?? ''),
                            'category_id' => (string) ($args['categoryId'] ?? ''),
                            'is_active' => $active === null ? '' : ($active ? '1' : '0'),
                        ], max(1, $args['page']), min(100, max(1, $args['pageSize'])));
                    },
                ],
            ],
            'mutation' => [
                'createProduct' => [
                    'type' => Type::nonNull($productType),
                    'args' => [
                        'sku' => Type::nonNull(Type::string()),
                        'name' => Type::nonNull(Type::string()),
                        'attributeSetId' => ['type' => Type::id(), 'description' => 'Omitido = conjunto padrão de produto.'],
                        'isActive' => ['type' => Type::boolean(), 'defaultValue' => true],
                        'attributes' => Type::listOf(Type::nonNull($attributeInput)),
                        'categoryIds' => Type::listOf(Type::nonNull(Type::id())),
                    ],
                    'resolve' => $guard(static function ($root, array $args) use ($service, $data, $attributes): array {
                        $setId = (int) ($args['attributeSetId'] ?? (new AttributeSet())->getResource()->findId(Product::ENTITY_TYPE, null));
                        return $service->create($data($args) + ['attribute_set_id' => $setId], $attributes($args, $setId))->getData();
                    }),
                ],
                'updateProduct' => [
                    'type' => Type::nonNull($productType),
                    'description' => 'Só muda o que for enviado; atributos omitidos mantêm o valor. O conjunto não muda.',
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'sku' => Type::string(),
                        'name' => Type::string(),
                        'isActive' => Type::boolean(),
                        'attributes' => Type::listOf(Type::nonNull($attributeInput)),
                        'categoryIds' => ['type' => Type::listOf(Type::nonNull(Type::id())), 'description' => 'Substitui as categorias; [] tira de todas.'],
                    ],
                    'resolve' => $guard(static function ($root, array $args) use ($service, $data, $attributes, $optionsOf): array {
                        $id = (int) $args['id'];
                        $current = $service->get($id)->getData();
                        // ProductService saves the whole set (missing = empty), so start from the stored values,
                        // minus options removed since they were chosen (as the admin form does, which doesn't show them)
                        $stored = $service->getValues($id);
                        foreach ($stored as $code => $value) {
                            $options = $optionsOf($code);
                            if ($options !== null) {
                                $stored[$code] = is_array($value) ? array_values(array_filter($value, static fn ($v) => isset($options[$v]))) : (isset($options[$value]) ? $value : '');
                            }
                        }

                        return $service->update($id, $data($args, $current), $attributes($args, (int) $current['attribute_set_id']) + $stored)->getData();
                    }),
                ],
                'deleteProduct' => [
                    'type' => Type::nonNull(Type::boolean()),
                    'args' => ['id' => Type::nonNull(Type::id())],
                    'resolve' => $guard(static function ($root, array $args) use ($service): bool {
                        $service->delete((int) $args['id']);
                        return true;
                    }),
                ],
            ],
        ];
    }
}
