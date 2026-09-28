<?php

declare(strict_types=1);

namespace Iter\Inventory\GraphQl;

use GraphQL\Deferred;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use Iter\Admin\Auth;
use Iter\Catalog\Model\Product;
use Iter\Inventory\Model\InventoryException;
use Iter\Inventory\Model\Source;
use Iter\Inventory\Model\StockService;

/**
 * Stock fields for Iter\Core\GraphQl\Schema: Product.stock (the salable
 * quantity is public; the per-source quantities are admin only, they tell how
 * much each warehouse holds), the sources and setProductStock.
 */
class StockSchema
{
    /** @return array{product: array, query: array, mutation: array} */
    public static function fields(): array
    {
        $service = new StockService();
        $guard = static fn (string $resource, callable $resolve) => static function (...$args) use ($resource, $resolve) {
            if (!Auth::isAllowed($resource)) {
                throw new UserError('Acesso negado.');
            }

            return $resolve(...$args);
        };

        // salable quantities of every product in the response, in one query
        $pending = [];
        $loaded = [];
        $salable = static function (array $stock) use ($service, &$pending, &$loaded): Deferred {
            $id = $stock['product_id'];
            $pending[$id] = $id;

            return new Deferred(static function () use ($id, $service, &$pending, &$loaded): float {
                if (!isset($loaded[$id])) {
                    $loaded = $service->getSalable($pending) + $loaded;
                    $pending = [];
                }

                return (float) $loaded[$id];
            });
        };

        $sourceType = new ObjectType([
            'name' => 'InventorySource',
            'fields' => [
                'id' => ['type' => Type::nonNull(Type::id()), 'resolve' => fn ($s) => $s['source_id']],
                'code' => Type::nonNull(Type::string()),
                'name' => Type::nonNull(Type::string()),
                'is_enabled' => ['type' => Type::nonNull(Type::boolean()), 'resolve' => fn ($s) => (bool) $s['is_enabled']],
                'priority' => ['type' => Type::nonNull(Type::int()), 'description' => 'Menor = usada primeiro.'],
                'description' => Type::string(),
                'postcode' => Type::string(),
                'city' => Type::string(),
                'region' => Type::string(),
                'country_code' => Type::nonNull(Type::string()),
            ],
        ]);
        $stockType = new ObjectType([
            'name' => 'ProductStock',
            'fields' => [
                'salable_quantity' => [
                    'type' => Type::nonNull(Type::float()),
                    'description' => 'Soma das quantidades "em estoque" nas origens ativas.',
                    'resolve' => $salable,
                ],
                'is_in_stock' => [
                    'type' => Type::nonNull(Type::boolean()),
                    'resolve' => static fn ($stock) => $salable($stock)->then(static fn (float $q) => $q > 0),
                ],
                'sources' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull(new ObjectType([
                        'name' => 'ProductStockSource',
                        'fields' => [
                            'code' => Type::nonNull(Type::string()),
                            'name' => Type::nonNull(Type::string()),
                            'is_enabled' => ['type' => Type::nonNull(Type::boolean()), 'resolve' => fn ($i) => (bool) $i['is_enabled']],
                            'quantity' => ['type' => Type::nonNull(Type::float()), 'resolve' => fn ($i) => (float) $i['quantity']],
                            'status' => ['type' => Type::nonNull(Type::int()), 'description' => '1 = em estoque, 0 = fora de estoque.'],
                        ],
                    ])))),
                    'description' => 'Quantidade em cada origem (todas as origens). Só para o admin.',
                    'resolve' => $guard('Iter_Inventory::stock', static fn ($stock) => $service->getItems($stock['product_id'])),
                ],
            ],
        ]);
        $stockInput = new InputObjectType([
            'name' => 'ProductStockInput',
            'fields' => [
                'code' => ['type' => Type::nonNull(Type::string()), 'description' => 'Código da origem.'],
                'quantity' => Type::nonNull(Type::float()),
                'status' => ['type' => Type::int(), 'defaultValue' => 1, 'description' => '1 = em estoque, 0 = fora de estoque.'],
            ],
        ]);

        return [
            'product' => [
                'stock' => ['type' => Type::nonNull($stockType), 'resolve' => static fn ($p) => ['product_id' => (int) $p['product_id']]],
            ],
            'query' => [
                'inventorySources' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($sourceType))),
                    'resolve' => $guard('Iter_Inventory::source', static fn () => (new Source())->getResource()->getList()),
                ],
                'inventorySource' => [
                    'type' => $sourceType,
                    'args' => ['code' => Type::nonNull(Type::string())],
                    'resolve' => $guard('Iter_Inventory::source', static function ($root, array $args): ?array {
                        $source = new Source();
                        $source->getResource()->load($source, $args['code'], 'code');

                        return $source->getId() ? $source->getData() : null;
                    }),
                ],
            ],
            'mutation' => [
                'setProductStock' => [
                    'type' => Type::nonNull($stockType),
                    'description' => 'Só muda as origens enviadas; as outras mantêm o que têm.',
                    'args' => [
                        'sku' => Type::nonNull(Type::string()),
                        'sources' => Type::nonNull(Type::listOf(Type::nonNull($stockInput))),
                    ],
                    'resolve' => $guard('Iter_Inventory::stock', static function ($root, array $args) use ($service, &$loaded): array {
                        $id = (new Product())->getResource()->findIdBySku($args['sku'])
                            ?? throw new InventoryException('PRODUCT_NOT_FOUND', 'Produto não encontrado.');
                        $items = [];
                        foreach ($args['sources'] as $source) {
                            $items[$source['code']] = ['quantity' => (string) $source['quantity'], 'status' => (string) $source['status']];
                        }
                        $service->save($id, $items);
                        unset($loaded[$id]);

                        return ['product_id' => $id];
                    }),
                ],
            ],
        ];
    }
}
