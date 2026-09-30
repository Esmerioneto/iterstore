<?php

declare(strict_types=1);

namespace Iter\Core\GraphQl;

use GraphQL\Type\Definition\CustomScalarType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Error\Error;
use GraphQL\Type\Schema as GraphQLSchema;
use Iter\Admin\Auth;
use Iter\Admin\GraphQl\AdminSchema;
use Iter\Core\Config;
use Iter\Catalog\GraphQl\ProductSchema;
use Iter\Customer\GraphQl\CustomerSchema;
use Iter\Eav\GraphQl\AttributeSchema;
use Iter\Inventory\GraphQl\StockSchema;
use Iter\Store\Model\Store as StoreModel;

class Schema
{
    /**
     * Wraps an admin-only resolver: without login the error code is UNAUTHENTICATED,
     * logged in without the ACL resource it is FORBIDDEN (extensions.code, for the clients' logic)
     */
    public static function guard(string $resource, callable $resolve): \Closure
    {
        return static function (...$args) use ($resource, $resolve) {
            if (!Auth::isAllowed($resource)) {
                throw new Error('Acesso negado.', extensions: ['code' => Auth::user() === null ? 'UNAUTHENTICATED' : 'FORBIDDEN']);
            }

            return $resolve(...$args);
        };
    }

    /**
     * A file sent by the GraphQL multipart request spec (Controllers/Graphql.php puts it in the variables);
     * the value is its $_FILES entry (error, name, tmp_name, size), which Eav\Model\ValueService::uploaded() checks.
     * One instance: a schema can't have two types with one name.
     */
    public static function upload(): CustomScalarType
    {
        static $type = null;

        return $type ??= new CustomScalarType([
            'name' => 'Upload',
            'description' => 'Arquivo enviado pelo GraphQL multipart request spec (multipart/form-data com operations, map e o arquivo). Exige o token Bearer.',
            'serialize' => static fn () => throw new Error('Upload só é aceito como entrada.'),
            'parseValue' => static fn ($value) => is_array($value) && isset($value['tmp_name'], $value['error'])
                ? $value
                : throw new Error('Envie o arquivo pelo multipart request spec (operations + map).', extensions: ['code' => 'BAD_REQUEST']),
            'parseLiteral' => static fn () => throw new Error('Upload vem numa variável, não escrito na consulta.', extensions: ['code' => 'BAD_REQUEST']),
        ]);
    }

    /** null when no enabled module has API fields (a schema needs at least one query) */
    public static function build(): ?GraphQLSchema
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
        $guard = static fn (callable $resolve) => self::guard('Iter_Store::store', $resolve);

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

        // store data from Configurações (store/info/*, store/address/*), website value over default;
        // the root handed to these fields is ['id' => website id, null = default scope]
        $configFields = static fn (string $prefix, array $fields) => array_map(static fn (string $key) => [
            'type' => Type::string(),
            'resolve' => static fn (array $root) => (string) Config::get($prefix . $key, $root['id']) ?: null,
        ], $fields);
        $storeInfoType = new ObjectType([
            'name' => 'StoreInfo',
            'fields' => $configFields('store/info/', ['name' => 'name', 'taxId' => 'tax_id', 'phone' => 'phone', 'email' => 'email', 'details' => 'details']) + [
                'logoUrl' => ['type' => Type::string(), 'resolve' => static fn (array $root) => Config::mediaUrl(Config::get('store/info/logo', $root['id'])) ?: null],
                'address' => ['type' => Type::nonNull(new ObjectType([
                    'name' => 'StoreAddress',
                    'fields' => $configFields('store/address/', [
                        'postcode' => 'postcode', 'street' => 'street', 'number' => 'number', 'complement' => 'complement',
                        'district' => 'district', 'city' => 'city', 'region' => 'region', 'country' => 'country',
                    ]),
                ])), 'resolve' => static fn (array $root) => $root],
            ],
        ]);

        // a disabled module's API is off too
        // Inventory depends on Catalog, so it's only on when Catalog is
        $stock = \isModuleEnabled('iter_inventory') ? StockSchema::fields() : ['product' => [], 'query' => [], 'mutation' => []];
        $product = \isModuleEnabled('iter_catalog') ? ProductSchema::fields($stock['product']) : ['query' => [], 'mutation' => []];
        $stores = \isModuleEnabled('iter_store');
        $customer = \isModuleEnabled('iter_customer') ? CustomerSchema::fields() : ['query' => [], 'mutation' => []];
        $admin = AdminSchema::fields(); // Admin is a required module
        $eav = \isModuleEnabled('iter_eav') ? AttributeSchema::fields() : ['query' => [], 'mutation' => []];

        $queryType = new ObjectType([
            'name' => 'Query',
            'fields' => ($stores ? [
                'store' => [
                    'type' => $storeType,
                    'args' => ['id' => Type::nonNull(Type::id())],
                    'resolve' => fn ($root, array $args) => $load((int) $args['id']),
                ],
                'stores' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($storeType))),
                    'resolve' => fn () => (new StoreModel())->getList(),
                ],
                'storeInfo' => [
                    'type' => $storeInfoType,
                    'description' => 'Dados da loja. Sem websiteId, os do escopo Padrão; website desconhecido dá null.',
                    'args' => ['websiteId' => Type::id()],
                    'resolve' => static function ($root, array $args) {
                        $id = isset($args['websiteId']) ? (int) $args['websiteId'] : null;
                        return $id === null || Config::websiteExists($id) ? ['id' => $id] : null;
                    },
                ],
            ] : []) + $product['query'] + $stock['query'] + $customer['query'] + $eav['query'] + $admin['query'],
        ]);

        $mutationType = new ObjectType([
            'name' => 'Mutation',
            'fields' => ($stores ? [
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
            ] : []) + $product['mutation'] + $stock['mutation'] + $customer['mutation'] + $admin['mutation'],
        ]);

        if (!$queryType->getFields()) {
            return null;
        }

        return new GraphQLSchema([
            'query' => $queryType,
            'mutation' => $mutationType->getFields() ? $mutationType : null,
        ]);
    }
}
