<?php

declare(strict_types=1);

namespace Iter\Core\GraphQl;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Error\UserError;
use GraphQL\Type\Schema as GraphQLSchema;
use Iter\Admin\Auth;
use Iter\Store\Model\Store as StoreModel;

class Schema
{
    public static function build(): GraphQLSchema
    {
        $load = static function (int $id): ?array {
            $store = (new StoreModel())->load($id);
            return $store->getId() ? $store->getData() : null;
        };

        // null id = create; unknown id = null (updateStore returns null, as before)
        $save = static function (?int $id, array $args): ?array {
            $store = new StoreModel();
            if ($id !== null && !$store->load($id)->getId()) {
                return null;
            }

            $data = [
                'store_name' => $args['storeName'],
                'webview' => $args['webview'],
                'theme' => $args['theme'],
            ];
            // optional: omitted keeps the current group, explicit null ungroups
            if (array_key_exists('groupId', $args)) {
                $data['group_id'] = $args['groupId'] === null ? null : (int) $args['groupId'];
            }

            return $store->addData($data)->save()->getData();
        };

        // ponytail: reads stay public (storefront), writes need an admin
        // session with the Loja resource
        $guard = static fn (callable $resolve) => static function (...$args) use ($resolve) {
            if (!Auth::isAllowed('Iter_Store::store')) {
                throw new UserError('Acesso negado.');
            }

            return $resolve(...$args);
        };

        $storeType = new ObjectType([
            'name' => 'Store',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'store_name' => Type::nonNull(Type::string()),
                'webview' => Type::nonNull(Type::string()),
                'theme' => Type::nonNull(Type::string()),
                'group_id' => Type::id(),
                'created_at' => Type::nonNull(Type::string()),
                'updated_at' => Type::nonNull(Type::string()),
            ],
        ]);

        $queryType = new ObjectType([
            'name' => 'Query',
            'fields' => [
                'store' => [
                    'type' => $storeType,
                    'args' => ['id' => Type::nonNull(Type::id())],
                    'resolve' => fn ($root, array $args) => $load((int) $args['id']),
                ],
                'stores' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($storeType))),
                    'resolve' => fn () => (new StoreModel())->getList(),
                ],
            ],
        ]);

        $mutationType = new ObjectType([
            'name' => 'Mutation',
            'fields' => [
                'createStore' => [
                    'type' => Type::nonNull($storeType),
                    'args' => [
                        'storeName' => Type::nonNull(Type::string()),
                        'webview' => Type::nonNull(Type::string()),
                        'theme' => Type::nonNull(Type::string()),
                        'groupId' => Type::id(),
                    ],
                    'resolve' => $guard(fn ($root, array $args) => $save(null, $args)),
                ],
                'updateStore' => [
                    'type' => $storeType,
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'storeName' => Type::nonNull(Type::string()),
                        'webview' => Type::nonNull(Type::string()),
                        'theme' => Type::nonNull(Type::string()),
                        'groupId' => Type::id(),
                    ],
                    'resolve' => $guard(fn ($root, array $args) => $save((int) $args['id'], $args)),
                ],
                'deleteStore' => [
                    'type' => Type::nonNull(Type::boolean()),
                    'args' => ['id' => Type::nonNull(Type::id())],
                    'resolve' => $guard(static function ($root, array $args): bool {
                        $store = (new StoreModel())->load((int) $args['id']);
                        if (!$store->getId()) {
                            return false;
                        }
                        $store->delete();
                        return true;
                    }),
                ],
            ],
        ]);

        return new GraphQLSchema([
            'query' => $queryType,
            'mutation' => $mutationType,
        ]);
    }
}
