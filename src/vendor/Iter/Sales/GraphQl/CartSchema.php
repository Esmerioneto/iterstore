<?php

declare(strict_types=1);

namespace Iter\Sales\GraphQl;

use GraphQL\Error\Error;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use Iter\Admin\Auth;
use Iter\Catalog\Model\Product;
use Iter\Core\Config;
use Iter\Core\RateLimit;
use Iter\Customer\Model\CustomerAuth;
use Iter\Payment\Model\PaymentService;
use Iter\Sales\Model\CartService;
use Iter\Sales\Model\OrderService;
use Iter\Store\Model\Website;

/**
 * The storefront's cart (COMMERCE-PLAN §2). A guest keeps the cartId from createCart and sends it in
 * every call; a logged-in customer (Bearer token) omits it and works on their own cart. The rules are
 * in CartService; every mutation returns the cart as `cart` shows it. An admin with Iter_Adminsales::create
 * uses them on the carts of createAdminCart too (placeOrder stays the store's: placeAdminOrder). placeOrder turns the cart into
 * an order (OrderService).
 */
class CartSchema
{
    /** @return array{query: array, mutation: array, types: array{order: ObjectType}} the Order type is reused by OrderSchema */
    public static function fields(ObjectType $productType): array
    {
        $service = new CartService();
        // an admin who builds orders (Iter_Adminsales) also works on their carts with these mutations
        $cart = static fn (?string $cartId) => $service->resolve($cartId, CustomerAuth::customer(), false,
            \isModuleEnabled('iter_adminsales') && Auth::isAllowed('Iter_Adminsales::create'));
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
        // shipping fields only while Iter_Shipping is on (a disabled module's API is off)
        $shipping = \isModuleEnabled('iter_shipping');
        $rateType = new ObjectType([
            'name' => 'ShippingMethod',
            'fields' => [
                'code' => ['type' => Type::nonNull(Type::string()), 'description' => 'tablerate, freeshipping ou pickup.'],
                'label' => Type::nonNull(Type::string()),
                'amount' => Type::nonNull(Type::float()),
                'days' => ['type' => Type::nonNull(Type::int()), 'description' => 'Prazo em dias úteis.'],
            ],
        ]);
        $shippingFields = $shipping ? [
            'availableShippingMethods' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull($rateType))),
                'description' => 'As opções de frete agora, a mais barata primeiro. Frete por tabela e grátis pedem o endereço de entrega.',
                'resolve' => fn ($c) => $c['shipping_methods'],
            ],
            'shippingMethod' => ['type' => $rateType, 'description' => 'O escolhido; null se nenhum ou se deixou de valer (endereço mudou).', 'resolve' => fn ($c) => $c['shipping_method']],
            'shippingAmount' => ['type' => Type::nonNull(Type::float()), 'resolve' => fn ($c) => $c['shipping_amount']],
        ] : [];
        $payment = \isModuleEnabled('iter_payment');
        $paymentType = new ObjectType([
            'name' => 'PaymentMethod',
            'fields' => [
                'code' => ['type' => Type::nonNull(Type::string()), 'description' => 'pix, offline ou, com o Mercado Pago, mercadopago_pix, mercadopago_credit e mercadopago_debit (depois do placeOrder, payOrder cobra).'],
                'label' => Type::nonNull(Type::string()),
                'instructions' => ['type' => Type::string(), 'description' => 'O que o cliente faz para pagar (a chave Pix, por exemplo). Também vai no pedido.'],
            ],
        ]);
        $paymentFields = $payment ? [
            'availablePaymentMethods' => ['type' => Type::nonNull(Type::listOf(Type::nonNull($paymentType))), 'resolve' => fn ($c) => $c['payment_methods']],
            'paymentMethod' => ['type' => $paymentType, 'description' => 'O escolhido; null se nenhum ou se foi desligado.', 'resolve' => fn ($c) => $c['payment_method']],
        ] : [];
        $cartType = new ObjectType([
            'name' => 'Cart',
            'fields' => $shippingFields + $paymentFields + [
                'websiteId' => ['type' => Type::nonNull(Type::id()), 'resolve' => fn ($c) => $c['website_id']],
                'isGuest' => ['type' => Type::nonNull(Type::boolean()), 'resolve' => fn ($c) => $c['customer_id'] === null],
                'email' => Type::string(),
                'firstname' => Type::string(),
                'lastname' => Type::string(),
                'taxvat' => ['type' => Type::string(), 'description' => 'CPF ou CNPJ, só os dígitos.'],
                'items' => Type::nonNull(Type::listOf(Type::nonNull($itemType))),
                'itemsQuantity' => ['type' => Type::nonNull(Type::float()), 'resolve' => fn ($c) => $c['items_quantity']],
                'subtotal' => Type::nonNull(Type::float()),
                'total' => ['type' => Type::nonNull(Type::float()), 'description' => 'Subtotal + frete escolhido.'],
                'hasErrors' => ['type' => Type::nonNull(Type::boolean()), 'description' => 'Algum item com error.', 'resolve' => fn ($c) => $c['has_errors']],
                'shippingAddress' => ['type' => $addressType, 'resolve' => fn ($c) => $c['shipping_address']],
                'billingAddress' => ['type' => $addressType, 'description' => 'Sem um próprio, o de entrega.', 'resolve' => fn ($c) => $c['billing_address']],
                'billingSameAsShipping' => ['type' => Type::nonNull(Type::boolean()), 'resolve' => fn ($c) => $c['billing_same_as_shipping']],
            ],
        ]);
        $orderType = new ObjectType([
            'name' => 'Order',
            'fields' => [
                'id' => ['type' => Type::nonNull(Type::id()), 'resolve' => fn ($o) => $o['order_id']],
                'number' => ['type' => Type::nonNull(Type::string()), 'description' => 'O número que o cliente vê (SP-000001).', 'resolve' => fn ($o) => $o['increment_id']],
                'status' => ['type' => Type::nonNull(Type::string()), 'description' => 'pending_payment, processing, shipped, complete ou canceled.'],
                'statusLabel' => ['type' => Type::nonNull(Type::string()), 'resolve' => fn ($o) => $o['status_label']],
                'createdAt' => ['type' => Type::nonNull(Type::string()), 'resolve' => fn ($o) => $o['created_at']],
                'email' => Type::nonNull(Type::string()),
                'firstname' => Type::nonNull(Type::string()),
                'lastname' => Type::nonNull(Type::string()),
                'taxvat' => ['type' => Type::nonNull(Type::string()), 'description' => 'CPF ou CNPJ, só os dígitos.'],
                'items' => Type::nonNull(Type::listOf(Type::nonNull(new ObjectType([
                    'name' => 'OrderItem',
                    'fields' => [
                        'sku' => Type::nonNull(Type::string()),
                        'name' => Type::nonNull(Type::string()),
                        'options' => ['type' => Type::string(), 'description' => 'A variação comprada ("Cor: Preto, Tamanho: M").'],
                        'quantity' => Type::nonNull(Type::float()),
                        'price' => Type::nonNull(Type::float()),
                        'rowTotal' => ['type' => Type::nonNull(Type::float()), 'resolve' => fn ($i) => $i['row_total']],
                    ],
                ])))),
                'subtotal' => ['type' => Type::nonNull(Type::float()), 'resolve' => fn ($o) => (float) $o['subtotal']],
                'shippingAmount' => ['type' => Type::nonNull(Type::float()), 'resolve' => fn ($o) => (float) $o['shipping_amount']],
                'total' => ['type' => Type::nonNull(Type::float()), 'resolve' => fn ($o) => (float) $o['grand_total']],
                'shippingMethod' => ['type' => Type::string(), 'description' => 'O code do frete escolhido.', 'resolve' => fn ($o) => $o['shipping_method']],
                'shippingLabel' => ['type' => Type::string(), 'resolve' => fn ($o) => $o['shipping_label']],
                'shippingDays' => ['type' => Type::int(), 'description' => 'O prazo em dias úteis prometido no pedido.', 'resolve' => fn ($o) => $o['shipping_days']],
                'paymentMethod' => ['type' => Type::string(), 'description' => 'O code do pagamento escolhido.', 'resolve' => fn ($o) => $o['payment_method']],
                'paymentLabel' => ['type' => Type::string(), 'resolve' => fn ($o) => $o['payment_label']],
                'paymentInstructions' => ['type' => Type::string(), 'description' => 'O que o cliente faz para pagar (a chave Pix, por exemplo), como era no pedido.', 'resolve' => fn ($o) => $o['payment_instructions']],
                'availablePaymentMethods' => ['type' => Type::nonNull(Type::listOf(Type::nonNull($paymentType))), 'description' => 'As opções do website hoje, para changeMyOrderPaymentMethod.',
                    'resolve' => fn ($o) => $payment ? array_values((new PaymentService())->methods($o)) : []],
                'shippingAddress' => ['type' => $addressType, 'resolve' => fn ($o) => $o['shipping_address']],
                'billingAddress' => ['type' => $addressType, 'resolve' => fn ($o) => $o['billing_address']],
                'websiteId' => ['type' => Type::nonNull(Type::id()), 'resolve' => fn ($o) => $o['website_id']],
                'websiteName' => ['type' => Type::string(), 'description' => 'A loja (website) que fechou o pedido.', 'resolve' => fn ($o) => $o['website_name'] ?? null],
                'customerId' => ['type' => Type::id(), 'description' => 'null = visitante.', 'resolve' => fn ($o) => $o['customer_id']],
                'shipment' => ['type' => new ObjectType([
                    'name' => 'OrderShipment',
                    'fields' => [
                        'carrier' => ['type' => Type::string(), 'resolve' => fn ($s) => $s['carrier']],
                        'trackingCode' => ['type' => Type::string(), 'resolve' => fn ($s) => $s['tracking_code']],
                        'createdAt' => ['type' => Type::nonNull(Type::string()), 'resolve' => fn ($s) => $s['created_at']],
                        'sourceName' => ['type' => Type::string(), 'description' => 'De onde saiu (só para o admin com Iter_Sales::order).',
                            'resolve' => fn ($s) => Auth::isAllowed('Iter_Sales::order') ? $s['source_name'] : null],
                    ],
                ]), 'description' => 'A entrega; null antes do envio.', 'resolve' => fn ($o) => $o['shipment']],
                'history' => [
                    'type' => Type::listOf(Type::nonNull(new ObjectType([
                        'name' => 'OrderHistory',
                        'fields' => [
                            'status' => Type::nonNull(Type::string()),
                            'comment' => Type::string(),
                            'customerNotified' => ['type' => Type::nonNull(Type::boolean()), 'resolve' => fn ($h) => (bool) $h['is_customer_notified']],
                            'adminUsername' => ['type' => Type::string(), 'description' => 'Quem fez; null = o próprio pedido (placeOrder).', 'resolve' => fn ($h) => $h['admin_username']],
                            'createdAt' => ['type' => Type::nonNull(Type::string()), 'resolve' => fn ($h) => $h['created_at']],
                        ],
                    ]))),
                    'description' => 'Situações e comentários, o mais novo primeiro. Só para o admin com Iter_Sales::order; para os outros, null.',
                    'resolve' => fn ($o) => Auth::isAllowed('Iter_Sales::order') ? $o['history'] : null,
                ],
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
            ] + ($shipping ? [
                'setCartShippingMethod' => [
                    'type' => Type::nonNull($cartType),
                    'description' => 'Um code de availableShippingMethods.',
                    'args' => $cartIdArg + ['code' => Type::nonNull(Type::string())],
                    'resolve' => $mutate(static fn (int $q, array $a) => $service->setShippingMethod($q, $a['code'])),
                ],
            ] : []) + ($payment ? [
                'setCartPaymentMethod' => [
                    'type' => Type::nonNull($cartType),
                    'description' => 'Um code de availablePaymentMethods.',
                    'args' => $cartIdArg + ['code' => Type::nonNull(Type::string())],
                    'resolve' => $mutate(static fn (int $q, array $a) => $service->setPaymentMethod($q, $a['code'])),
                ],
            ] : []) + [
                'placeOrder' => [
                    'type' => Type::nonNull($orderType),
                    'description' => 'Fecha o pedido: confere o carrinho (o erro diz o que falta), reserva o estoque e envia o e-mail "pedido recebido". Chamar de novo com o mesmo carrinho devolve o mesmo pedido.',
                    'args' => $cartIdArg,
                    'resolve' => static function ($root, array $args) use ($service): array {
                        if (!RateLimit::hit('order_place:' . RateLimit::ip(), (int) Config::get('sales/checkout/place_per_minute'))) {
                            http_response_code(429);
                            throw new Error('Muitos pedidos seguidos. Aguarde um minuto.', extensions: ['code' => 'TOO_MANY_REQUESTS']);
                        }
                        $orders = new OrderService();

                        return $orders->get($orders->place($service->resolve($args['cartId'] ?? null, CustomerAuth::customer(), true)));
                    },
                ],
                'setCartBillingAddress' => [
                    'type' => Type::nonNull($cartType),
                    'description' => 'Como o de entrega; sem address nem customerAddressId, a cobrança é no endereço de entrega.',
                    'args' => $cartIdArg + ['address' => $addressInput, 'customerAddressId' => Type::id()],
                    'resolve' => $mutate(static fn (int $q, array $a) => $service->setAddress($q, 'billing', $addressArg($a), isset($a['customerAddressId']) ? (int) $a['customerAddressId'] : null)),
                ],
            ],
            'types' => ['order' => $orderType],
        ];
    }
}
