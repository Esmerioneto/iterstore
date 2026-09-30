<?php

declare(strict_types=1);

namespace Iter\Sales\GraphQl;

use GraphQL\Error\Error;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use Iter\Catalog\Model\Product;
use Iter\Core\Config;
use Iter\Core\RateLimit;
use Iter\Customer\Model\CustomerAuth;
use Iter\Sales\Model\CartService;
use Iter\Store\Model\Website;

/**
 * The storefront's cart (COMMERCE-PLAN §2). A guest keeps the cartId from createCart and sends it in
 * every call; a logged-in customer (Bearer token) omits it and works on their own cart. The rules are
 * in CartService; every mutation returns the cart as `cart` shows it.
 */
class CartSchema
{
    /** @return array{query: array, mutation: array} */
    public static function fields(ObjectType $productType): array
    {
        $service = new CartService();
        $cart = static fn (?string $cartId) => $service->resolve($cartId, CustomerAuth::customer());
        $view = static fn (int $quoteId) => $service->view($quoteId);
        $cartIdArg = ['cartId' => ['type' => Type::string(), 'description' => 'O do createCart (visitante). Cliente logado omite: vale o carrinho dele.']];

        $attributeType = new ObjectType([
            'name' => 'CartAddressAttribute',
            'fields' => ['code' => Type::nonNull(Type::string()), 'value' => Type::string(), 'values' => Type::listOf(Type::nonNull(Type::string()))],
        ]);
        $fixed = ['postcode', 'street', 'number', 'complement', 'district', 'city', 'region', 'recipient', 'telephone'];
        $addressFields = ['attributes' => [
            'type' => Type::nonNull(Type::listOf(Type::nonNull($attributeType))),
            'description' => 'Os campos que o admin criou no conjunto de endereço.',
            'resolve' => static function (array $a) use ($fixed): array {
                $rows = [];
                foreach (array_diff_key($a, array_flip($fixed)) as $code => $value) {
                    if ($value !== '' && $value !== null && $value !== []) {
                        $rows[] = ['code' => $code, 'value' => is_array($value) ? null : (string) $value, 'values' => is_array($value) ? array_map('strval', $value) : null];
                    }
                }
                return $rows;
            },
        ]];
        foreach ($fixed as $code) {
            $addressFields[$code] = ['type' => Type::string(), 'resolve' => static fn (array $a) => ($a[$code] ?? '') === '' ? null : (string) $a[$code]];
        }
        $addressType = new ObjectType(['name' => 'CartAddress', 'fields' => $addressFields]);
        $addressInput = new InputObjectType([
            'name' => 'CartAddressInput',
            'fields' => array_fill_keys($fixed, Type::string()) + [
                'attributes' => ['type' => Type::listOf(Type::nonNull(new InputObjectType([
                    'name' => 'CartAddressAttributeInput',
                    'fields' => ['code' => Type::nonNull(Type::string()), 'value' => Type::string(), 'values' => Type::listOf(Type::nonNull(Type::string()))],
                ]))), 'description' => 'Os campos que o admin criou no conjunto de endereço (attributes(entityType: "customer_address")).'],
            ],
        ]);
        $addressArg = static fn (array $args): ?array => isset($args['address'])
            ? ['attributes' => array_column(array_map(static fn ($a) => ['code' => $a['code'], 'value' => $a['values'] ?? $a['value'] ?? ''], $args['address']['attributes'] ?? []), 'value', 'code')]
                + array_diff_key($args['address'], ['attributes' => 0])
            : null;

        $itemType = new ObjectType([
            'name' => 'CartItem',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'sku' => Type::nonNull(Type::string()),
                'name' => Type::nonNull(Type::string()),
                'quantity' => Type::nonNull(Type::float()),
                'price' => ['type' => Type::float(), 'description' => 'O preço de agora; null sem preço.'],
                'rowTotal' => ['type' => Type::nonNull(Type::float()), 'resolve' => fn ($i) => $i['row_total']],
                'error' => ['type' => Type::string(), 'description' => 'Por que o item não pode ser comprado assim (inativo, sem estoque suficiente...). Com erro, o pedido é recusado.'],
                'errorCode' => ['type' => Type::string(), 'description' => 'PRODUCT_NOT_SALABLE, INSUFFICIENT_STOCK ou PRODUCT_IS_CONFIGURABLE.', 'resolve' => fn ($i) => $i['error_code']],
                'product' => ['type' => $productType, 'resolve' => static fn ($i) => (new Product())->load($i['product_id'])->getData() ?: null],
            ],
        ]);
        $cartType = new ObjectType([
            'name' => 'Cart',
            'fields' => [
                'websiteId' => ['type' => Type::nonNull(Type::id()), 'resolve' => fn ($c) => $c['website_id']],
                'isGuest' => ['type' => Type::nonNull(Type::boolean()), 'resolve' => fn ($c) => $c['customer_id'] === null],
                'email' => Type::string(),
                'firstname' => Type::string(),
                'lastname' => Type::string(),
                'taxvat' => ['type' => Type::string(), 'description' => 'CPF ou CNPJ, só os dígitos.'],
                'items' => Type::nonNull(Type::listOf(Type::nonNull($itemType))),
                'itemsQuantity' => ['type' => Type::nonNull(Type::float()), 'resolve' => fn ($c) => $c['items_quantity']],
                'subtotal' => Type::nonNull(Type::float()),
                'total' => ['type' => Type::nonNull(Type::float()), 'description' => 'Por enquanto igual ao subtotal; o frete entra quando for escolhido.'],
                'hasErrors' => ['type' => Type::nonNull(Type::boolean()), 'description' => 'Algum item com error.', 'resolve' => fn ($c) => $c['has_errors']],
                'shippingAddress' => ['type' => $addressType, 'resolve' => fn ($c) => $c['shipping_address']],
                'billingAddress' => ['type' => $addressType, 'description' => 'Sem um próprio, o de entrega.', 'resolve' => fn ($c) => $c['billing_address']],
                'billingSameAsShipping' => ['type' => Type::nonNull(Type::boolean()), 'resolve' => fn ($c) => $c['billing_same_as_shipping']],
            ],
        ]);
        $mutate = static fn (callable $change) => static function ($root, array $args) use ($cart, $view, $change): array {
            $quoteId = $cart($args['cartId'] ?? null);
            $change($quoteId, $args);

            return $view($quoteId);
        };

        return [
            'query' => [
                'cart' => [
                    'type' => Type::nonNull($cartType),
                    'args' => $cartIdArg,
                    'resolve' => static fn ($root, array $args) => $view($cart($args['cartId'] ?? null)),
                ],
            ],
            'mutation' => [
                'createCart' => [
                    'type' => Type::nonNull(Type::string()),
                    'description' => 'Novo carrinho de visitante; devolve o cartId (guarde-o: ele é a chave do carrinho). O cliente logado não precisa: usa o dele. Depois do login, mergeCarts junta os dois.',
                    'args' => ['websiteId' => ['type' => Type::id(), 'description' => 'Omitido = website padrão.']],
                    'resolve' => static function ($root, array $args) use ($service): string {
                        // ponytail: per-IP limit against empty carts filling the table; bin/quote-clean.php removes the old ones
                        if (!RateLimit::hit('cart_create:' . RateLimit::ip(), (int) Config::get('sales/quote/create_per_minute'))) {
                            http_response_code(429);
                            throw new Error('Muitos carrinhos criados. Aguarde um minuto.', extensions: ['code' => 'TOO_MANY_REQUESTS']);
                        }
                        $websiteId = (int) ($args['websiteId'] ?? (new Website())->getResource()->getDefaultId());
                        if (!(new Website())->load($websiteId)->getId()) {
                            throw new Error('Website não encontrado.', extensions: ['code' => 'WEBSITE_INVALID']);
                        }

                        return $service->create($websiteId);
                    },
                ],
                'addToCart' => [
                    'type' => Type::nonNull($cartType),
                    'description' => 'Soma à quantidade que já estiver no carrinho. O produto com grade não entra: envie o SKU da variação.',
                    'args' => $cartIdArg + ['sku' => Type::nonNull(Type::string()), 'quantity' => ['type' => Type::nonNull(Type::float()), 'defaultValue' => 1]],
                    'resolve' => $mutate(static fn (int $q, array $a) => $service->addItem($q, $a['sku'], $a['quantity'])),
                ],
                'updateCartItem' => [
                    'type' => Type::nonNull($cartType),
                    'description' => 'A nova quantidade do item; 0 remove.',
                    'args' => $cartIdArg + ['itemId' => Type::nonNull(Type::id()), 'quantity' => Type::nonNull(Type::float())],
                    'resolve' => $mutate(static fn (int $q, array $a) => $service->updateItem($q, (int) $a['itemId'], $a['quantity'])),
                ],
                'removeCartItem' => [
                    'type' => Type::nonNull($cartType),
                    'args' => $cartIdArg + ['itemId' => Type::nonNull(Type::id())],
                    'resolve' => $mutate(static fn (int $q, array $a) => $service->removeItem($q, (int) $a['itemId'])),
                ],
                'mergeCarts' => [
                    'type' => Type::nonNull($cartType),
                    'description' => 'Depois do login: os itens do carrinho de visitante (cartId) vão para o do cliente, somando as quantidades, e o de visitante deixa de existir.',
                    'args' => ['cartId' => Type::nonNull(Type::string())],
                    'resolve' => static function ($root, array $args) use ($service, $view): array {
                        $customer = CustomerAuth::customer() ?? throw new Error('Faça login como cliente.', extensions: ['code' => 'UNAUTHENTICATED']);
                        return $view($service->merge($args['cartId'], $customer));
                    },
                ],
                'setCartGuestInfo' => [
                    'type' => Type::nonNull($cartType),
                    'description' => 'Dados do visitante para o pedido. O cliente logado não usa: os dados vêm da conta.',
                    'args' => $cartIdArg + ['email' => Type::nonNull(Type::string()), 'firstname' => Type::nonNull(Type::string()), 'lastname' => Type::nonNull(Type::string()),
                        'taxvat' => ['type' => Type::nonNull(Type::string()), 'description' => 'CPF ou CNPJ, com ou sem máscara.']],
                    'resolve' => $mutate(static fn (int $q, array $a) => $service->setGuestInfo($q, $a['email'], $a['firstname'], $a['lastname'], $a['taxvat'])),
                ],
                'setCartShippingAddress' => [
                    'type' => Type::nonNull($cartType),
                    'description' => 'O endereço digitado (address) ou um salvo do cliente (customerAddressId). As regras são as do endereço do cliente.',
                    'args' => $cartIdArg + ['address' => $addressInput, 'customerAddressId' => Type::id()],
                    'resolve' => $mutate(static fn (int $q, array $a) => $service->setAddress($q, 'shipping', $addressArg($a), isset($a['customerAddressId']) ? (int) $a['customerAddressId'] : null)),
                ],
                'setCartBillingAddress' => [
                    'type' => Type::nonNull($cartType),
                    'description' => 'Como o de entrega; sem address nem customerAddressId, a cobrança é no endereço de entrega.',
                    'args' => $cartIdArg + ['address' => $addressInput, 'customerAddressId' => Type::id()],
                    'resolve' => $mutate(static fn (int $q, array $a) => $service->setAddress($q, 'billing', $addressArg($a), isset($a['customerAddressId']) ? (int) $a['customerAddressId'] : null)),
                ],
            ],
        ];
    }
}
