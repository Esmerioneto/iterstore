<?php

declare(strict_types=1);

namespace Iter\Wishlist\GraphQl;

use GraphQL\Error\Error;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Iter\Core\GraphQl\Schema;
use Iter\Customer\Model\CustomerAuth;
use Iter\Wishlist\Model\WishlistService;

/**
 * WISHLIST-PLAN §3: only with the customer's token, always the token's customer (no customerId argument).
 * An admin token has no customer: UNAUTHENTICATED, as without a token.
 */
final class WishlistSchema
{
    /** @return array{query: array, mutation: array} */
    public static function fields(ObjectType $productType, \Closure $preloadFor): array
    {
        $service = new WishlistService();
        $customerId = static fn (): int => (int) (CustomerAuth::customer() ?? throw new Error('Faça login como cliente.', extensions: ['code' => 'UNAUTHENTICATED']))->getId();
        $productId = ['productId' => Type::nonNull(Type::id())];

        return [
            'query' => [
                'myWishlist' => [
                    'complexity' => Schema::paged(),
                    'type' => Type::nonNull(new ObjectType([
                        'name' => 'WishlistPage',
                        'fields' => [
                            'items' => Type::nonNull(Type::listOf(Type::nonNull(new ObjectType([
                                'name' => 'WishlistItem',
                                'fields' => [
                                    'product' => Type::nonNull($productType),
                                    'addedAt' => ['type' => Type::nonNull(Type::string()), 'resolve' => static fn (array $i) => $i['added_at']],
                                ],
                            ])))),
                            'total' => Type::nonNull(Type::int()),
                        ],
                    ])),
                    'description' => 'Os favoritos do cliente do token, o mais recente primeiro. Produto inativo ou fora do website fica de fora (volta se voltar).',
                    'args' => [
                        'page' => ['type' => Type::int(), 'defaultValue' => 1],
                        'pageSize' => ['type' => Type::int(), 'defaultValue' => 20, 'description' => 'Máximo 100.'],
                    ],
                    'resolve' => static function ($root, array $args, $context, ResolveInfo $info) use ($service, $customerId, $preloadFor): array {
                        $result = $service->items($customerId(), (int) $args['page'], (int) $args['pageSize']);
                        $products = $preloadFor(array_column($result['items'], 'product'), $info, 'items.product');

                        return ['items' => array_map(static fn (array $item, array $product) => ['product' => $product] + $item, $result['items'], $products), 'total' => $result['total']];
                    },
                ],
            ],
            'mutation' => [
                'addToWishlist' => [
                    'type' => Type::nonNull(Type::boolean()),
                    'description' => 'Favorita um produto (o pai, num produto com grade). Repetir não duplica. Erros: PRODUCT_NOT_FOUND (também inativo ou de outro website), PRODUCT_IS_VARIANT, WISHLIST_LIMIT (' . WishlistService::LIMIT . ' produtos).',
                    'args' => $productId,
                    'resolve' => static function ($root, array $args) use ($service, $customerId): bool {
                        $service->add($customerId(), (int) $args['productId']);

                        return true;
                    },
                ],
                'removeFromWishlist' => [
                    'type' => Type::nonNull(Type::boolean()),
                    'description' => 'Tira o produto dos favoritos. Produto que não estava lá também dá true.',
                    'args' => $productId,
                    'resolve' => static function ($root, array $args) use ($service, $customerId): bool {
                        $service->remove($customerId(), (int) $args['productId']);

                        return true;
                    },
                ],
            ],
        ];
    }
}
