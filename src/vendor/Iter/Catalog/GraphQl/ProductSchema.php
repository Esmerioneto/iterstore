<?php

declare(strict_types=1);

namespace Iter\Catalog\GraphQl;

use GraphQL\Error\UserError;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use Iter\Admin\Auth;
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
 * values, and file types (image) also give their url.
 *
 * Reads are public but only see active products; an admin session with the
 * Produtos resource sees all and can write. Files can't be uploaded here:
 * image attributes are kept as they are.
 */
class ProductSchema
{
    /** @return array{query: array, mutation: array} */
    public static function fields(): array
    {
        $service = new ProductService();
        $isAdmin = static fn (): bool => Auth::isAllowed('Iter_Catalog::product');
        $guard = static fn (callable $resolve) => static function (...$args) use ($resolve, $isAdmin) {
            if (!$isAdmin()) {
                throw new UserError('Acesso negado.');
            }

            return $resolve(...$args);
        };

        $inputTypes = null; // code => input type, loaded once per request
        $attributeType = new ObjectType([
            'name' => 'ProductAttribute',
            'fields' => [
                'code' => Type::nonNull(Type::string()),
                'value' => ['type' => Type::string(), 'description' => 'Valor de atributos de valor único (Sim/Não = "1"/"0").'],
                'values' => ['type' => Type::listOf(Type::nonNull(Type::string())), 'description' => 'Valores de atributos de múltipla seleção.'],
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
                    'resolve' => static function ($p) use ($service, &$inputTypes): array {
                        $inputTypes ??= array_column(
                            (new Attribute())->getResource()->getByEntityType((int) (new Attribute())->getResource()->getEntityTypes()[Product::ENTITY_TYPE]['entity_type_id']),
                            'input_type',
                            'code'
                        );
                        $rows = [];
                        foreach ($service->getValues((int) $p['product_id']) as $code => $value) {
                            $isFile = InputTypeRegistry::has($inputTypes[$code] ?? '') && InputTypeRegistry::get($inputTypes[$code])['accept'];
                            $rows[] = [
                                'code' => $code,
                                'value' => is_array($value) ? null : (is_bool($value) ? ($value ? '1' : '0') : (string) $value),
                                'values' => is_array($value) ? $value : null,
                                'url' => $isFile ? ValueService::MEDIA_URL . $value : null,
                            ];
                        }

                        return $rows;
                    },
                ],
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
        ];

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
                'products' => [
                    'type' => Type::nonNull(new ObjectType([
                        'name' => 'ProductList',
                        'fields' => [
                            'items' => Type::nonNull(Type::listOf(Type::nonNull($productType))),
                            'total' => Type::nonNull(Type::int()),
                        ],
                    ])),
                    'args' => [
                        'search' => ['type' => Type::string(), 'description' => 'Parte do SKU ou do nome.'],
                        'attributeSetId' => Type::id(),
                        'isActive' => ['type' => Type::boolean(), 'description' => 'Só para o admin; fora dele a lista traz apenas produtos ativos.'],
                        'page' => ['type' => Type::int(), 'defaultValue' => 1],
                        'pageSize' => ['type' => Type::int(), 'defaultValue' => 20, 'description' => 'Máximo 100.'],
                    ],
                    'resolve' => static function ($root, array $args) use ($isAdmin): array {
                        $active = $isAdmin() ? ($args['isActive'] ?? null) : true;
                        return (new Product())->getResource()->search([
                            'q' => (string) ($args['search'] ?? ''),
                            'attribute_set_id' => (string) ($args['attributeSetId'] ?? ''),
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
                    ],
                    'resolve' => $guard(static function ($root, array $args) use ($service, $data, $attributes): array {
                        $id = (int) $args['id'];
                        $current = $service->get($id)->getData();
                        // ProductService saves the whole set (missing = empty), so start from the stored values
                        return $service->update($id, $data($args, $current), $attributes($args, (int) $current['attribute_set_id']) + $service->getValues($id))->getData();
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
