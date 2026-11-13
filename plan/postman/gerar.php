<?php

declare(strict_types=1);

/**
 * Gera a collection do Postman (IterStore.postman_collection.json) e o ambiente (IterStore.postman_environment.json)
 * a partir do schema GraphQL dos módulos ligados agora: toda query e mutation entra, com variáveis de exemplo.
 * Rode de novo depois de mudar a API:
 *
 *     php plan/postman/gerar.php
 *
 * Cada operação é validada contra o schema (com os limites de profundidade e complexidade da API) antes de ser gravada.
 */

require_once __DIR__ . '/../../path.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once FUNCTIONS . 'bootstrap.php';

use Iter\Core\Config;
use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;
use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\QueryComplexity;
use GraphQL\Validator\Rules\QueryDepth;

$schema = Iter\Core\GraphQl\Schema::build();

// ---------------------------------------------------------------- pastas e acesso

const FOLDERS = [
    '01 Autenticação' => ['generateAdminToken', 'revokeAdminToken', 'currentAdmin', 'generateCustomerToken', 'refreshCustomerToken', 'revokeCustomerToken', 'registerCustomer', 'confirmCustomerEmail', 'requestCustomerPasswordReset', 'resetCustomerPassword'],
    '02 Loja e vitrine' => ['websites', 'storeInfo', 'webHome', 'mobileHome', 'mobileCategoryProducts', 'route', 'categories', 'category', 'products', 'product', 'attributes', 'mercadopagoSettings', 'subscribeNewsletter', 'confirmNewsletter'],
    '03 Minha conta (cliente)' => ['me', 'updateMe', 'changeMyPassword', 'createMyAddress', 'updateMyAddress', 'setMyDefaultAddress', 'deleteMyAddress', 'myOrders', 'myOrder', 'changeMyOrderPaymentMethod', 'myWishlist', 'addToWishlist', 'removeFromWishlist', 'customerMessages', 'customerUnreadMessages', 'sendCustomerMessage'],
    '04 Carrinho e checkout' => ['createCart', 'cart', 'addToCart', 'updateCartItem', 'removeCartItem', 'mergeCarts', 'setCartGuestInfo', 'shippingQuotes', 'setCartShippingAddress', 'setCartBillingAddress', 'setCartShippingMethod', 'setCartPaymentMethod', 'applyCartCoupon', 'removeCartCoupon', 'placeOrder', 'payOrder', 'guestOrder'],
    '05 Provador virtual' => ['tryOnSettings', 'startTryOn', 'tryOn'],
    '06 Admin - Catálogo' => ['createProduct', 'updateProduct', 'saveVariants', 'uploadProductImage', 'deleteProduct', 'createCategory', 'updateCategory', 'moveCategory', 'setCategoryBanners', 'deleteCategory', 'mobileOrder', 'saveMobileOrder', 'stores', 'store', 'createStore', 'updateStore', 'deleteStore'],
    '07 Admin - Estoque e frete' => ['inventorySources', 'inventorySource', 'setProductStock', 'shippingTableRates', 'saveShippingTableRates'],
    '08 Admin - Clientes' => ['customers', 'customer', 'createCustomer', 'updateCustomer', 'deleteCustomer'],
    '09 Admin - Vendas' => ['orders', 'order', 'salesSummary', 'confirmOrderPayment', 'shipOrder', 'completeOrder', 'cancelOrder', 'addOrderComment', 'createAdminCart', 'placeAdminOrder'],
    '10 Admin - Mensagens' => ['adminMessageInbox', 'adminCustomerMessages', 'adminSendCustomerMessage'],
    '11 Admin - Relatórios' => ['reports', 'report'],
];

// token de cliente; os da pasta 04 aceitam visitante (cartId) ou cliente: vão com o token, que o Postman omite vazio
const CUSTOMER = ['me', 'updateMe', 'changeMyPassword', 'createMyAddress', 'updateMyAddress', 'setMyDefaultAddress', 'deleteMyAddress', 'myOrders', 'myOrder', 'changeMyOrderPaymentMethod', 'myWishlist', 'addToWishlist', 'removeFromWishlist', 'customerMessages', 'customerUnreadMessages', 'sendCustomerMessage', 'refreshCustomerToken', 'revokeCustomerToken', 'mergeCarts', 'startTryOn', 'tryOn', 'tryOnSettings'];
const PUBLIC_OPS = ['generateAdminToken', 'generateCustomerToken', 'registerCustomer', 'confirmCustomerEmail', 'requestCustomerPasswordReset', 'resetCustomerPassword', 'websites', 'storeInfo', 'webHome', 'mobileHome', 'mobileCategoryProducts', 'route', 'categories', 'category', 'products', 'product', 'attributes', 'mercadopagoSettings', 'subscribeNewsletter', 'confirmNewsletter', 'guestOrder'];
const CART_OPS = ['createCart', 'cart', 'addToCart', 'updateCartItem', 'removeCartItem', 'setCartGuestInfo', 'shippingQuotes', 'setCartShippingAddress', 'setCartBillingAddress', 'setCartShippingMethod', 'setCartPaymentMethod', 'applyCartCoupon', 'removeCartCoupon', 'placeOrder', 'payOrder'];

// ---------------------------------------------------------------- exemplos
// op => [nome do exemplo => variáveis]; "{{x}}" vem do ambiente. Sem entrada aqui, os argumentos obrigatórios
// saem de AUTO (pelo nome do argumento).

const AUTO = [
    'websiteId' => '{{websiteId}}', 'cartId' => '{{cartId}}', 'sku' => '{{sku}}', 'productId' => '{{productId}}',
    'categoryId' => '{{categoryId}}', 'customerId' => '{{customerId}}', 'number' => '{{orderNumber}}', 'token' => '{{emailToken}}',
];

$address = [
    'recipient' => 'Maria Silva', 'postcode' => '20040-020', 'street' => 'Avenida Rio Branco', 'number' => '156',
    'complement' => 'Sala 1201', 'district' => 'Centro', 'city' => 'Rio de Janeiro', 'region' => 'RJ', 'telephone' => '(21) 99999-0000',
];

$examples = [
    // autenticação
    'generateAdminToken' => ['' => ['username' => '{{adminUser}}', 'password' => '{{adminPassword}}']],
    'generateCustomerToken' => [
        '' => ['email' => '{{customerEmail}}', 'password' => '{{customerPassword}}'],
        'num website' => ['email' => '{{customerEmail}}', 'password' => '{{customerPassword}}', 'websiteId' => '{{websiteId}}'],
    ],
    'registerCustomer' => [
        'pessoa física' => ['websiteId' => '{{websiteId}}', 'email' => 'maria.silva@example.com', 'firstname' => 'Maria', 'lastname' => 'Silva', 'type' => 'PF', 'taxvat' => '529.982.247-25', 'dob' => '1990-05-20', 'password' => 'Senha@Forte123'],
        'pessoa jurídica' => ['websiteId' => '{{websiteId}}', 'email' => 'compras@empresa-exemplo.com.br', 'firstname' => 'João', 'lastname' => 'Souza', 'type' => 'PJ', 'taxvat' => '11.222.333/0001-81', 'companyName' => 'Empresa Exemplo Ltda', 'tradeName' => 'Exemplo', 'isIeExempt' => true, 'password' => 'Senha@Forte123'],
    ],
    'requestCustomerPasswordReset' => ['' => ['email' => '{{customerEmail}}', 'websiteId' => '{{websiteId}}']],
    'resetCustomerPassword' => ['' => ['token' => '{{emailToken}}', 'password' => 'NovaSenha@123']],

    // vitrine
    'store' => ['' => ['id' => '{{storeId}}']],
    'route' => [
        'produto' => ['url' => '/{{productUrlKey}}', 'websiteId' => '{{websiteId}}'],
        'categoria' => ['url' => '/vestuario', 'websiteId' => '{{websiteId}}'],
    ],
    'categories' => ['' => ['websiteId' => '{{websiteId}}']],
    'category' => [
        'por id' => ['id' => '{{categoryId}}'],
        'por url_key' => ['url_key' => 'vestuario', 'websiteId' => '{{websiteId}}'],
    ],
    'products' => [
        'busca' => ['search' => 'jeans', 'websiteId' => '{{websiteId}}', 'page' => 1, 'pageSize' => 10],
        'por categoria' => ['categoryId' => '{{categoryId}}', 'websiteId' => '{{websiteId}}', 'pageSize' => 10],
        'filtros e preço' => ['websiteId' => '{{websiteId}}', 'filters' => [['code' => 'tamanho', 'values' => ['P', 'M']]], 'priceFrom' => 50, 'priceTo' => 200, 'pageSize' => 10],
    ],
    'product' => [
        'por sku' => ['sku' => '{{sku}}'],
        'por id' => ['id' => '{{productId}}'],
    ],
    'attributes' => [
        'produto' => ['entityType' => 'product'],
        'cliente' => ['entityType' => 'customer'],
        'endereço' => ['entityType' => 'customer_address'],
        'categoria' => ['entityType' => 'category'],
    ],
    'mobileCategoryProducts' => ['' => ['categoryId' => '{{categoryId}}', 'page' => 2, 'pageSize' => 10]],
    'subscribeNewsletter' => ['' => ['email' => 'leitor@example.com', 'websiteId' => '{{websiteId}}']],

    // minha conta
    'updateMe' => [
        'nome' => ['firstname' => 'Maria', 'lastname' => 'Souza Silva'],
        'trocar e-mail' => ['email' => 'novo.email@example.com', 'currentPassword' => '{{customerPassword}}'],
    ],
    'changeMyPassword' => ['' => ['currentPassword' => '{{customerPassword}}', 'newPassword' => 'NovaSenha@123']],
    'createMyAddress' => ['' => ['address' => $address + ['isDefaultShipping' => true, 'isDefaultBilling' => true]]],
    'updateMyAddress' => ['' => ['address' => ['id' => '{{addressId}}'] + $address]],
    'setMyDefaultAddress' => ['' => ['id' => '{{addressId}}', 'shipping' => true, 'billing' => true]],
    'deleteMyAddress' => ['' => ['id' => '{{addressId}}']],
    'myOrders' => ['' => ['page' => 1, 'pageSize' => 10]],
    'changeMyOrderPaymentMethod' => ['' => ['number' => '{{orderNumber}}', 'code' => 'pix']],
    'myWishlist' => ['' => ['page' => 1, 'pageSize' => 20]],
    'addToWishlist' => ['' => ['productId' => '{{productId}}']],
    'removeFromWishlist' => ['' => ['productId' => '{{productId}}']],
    'customerMessages' => ['' => ['limit' => 50]],
    'sendCustomerMessage' => ['' => ['body' => 'Olá! Meu pedido já foi enviado?']],

    // carrinho e checkout (na ordem do fluxo)
    'createCart' => ['' => ['websiteId' => '{{websiteId}}']],
    'cart' => ['visitante' => ['cartId' => '{{cartId}}'], 'cliente logado' => []],
    'addToCart' => [
        'visitante' => ['cartId' => '{{cartId}}', 'sku' => '{{sku}}', 'quantity' => 1],
        'cliente logado' => ['sku' => '{{sku}}', 'quantity' => 2],
    ],
    'updateCartItem' => ['' => ['cartId' => '{{cartId}}', 'itemId' => '{{cartItemId}}', 'quantity' => 3]],
    'removeCartItem' => ['' => ['cartId' => '{{cartId}}', 'itemId' => '{{cartItemId}}']],
    'mergeCarts' => ['' => ['cartId' => '{{cartId}}']],
    'setCartGuestInfo' => ['' => ['cartId' => '{{cartId}}', 'email' => 'visitante@example.com', 'firstname' => 'Ana', 'lastname' => 'Pereira', 'taxvat' => '529.982.247-25']],
    'shippingQuotes' => ['' => ['cartId' => '{{cartId}}', 'destinationCep' => '20040-020']],
    'setCartShippingAddress' => [
        'endereço digitado' => ['cartId' => '{{cartId}}', 'address' => $address],
        'endereço salvo do cliente' => ['customerAddressId' => '{{addressId}}'],
    ],
    'setCartBillingAddress' => [
        'igual à entrega' => ['cartId' => '{{cartId}}'],
        'outro endereço' => ['cartId' => '{{cartId}}', 'address' => ['street' => 'Rua da Assembleia', 'number' => '10'] + $address],
    ],
    'setCartShippingMethod' => [
        'tabela de frete' => ['cartId' => '{{cartId}}', 'code' => 'tablerate'],
        'retirar na loja' => ['cartId' => '{{cartId}}', 'code' => 'pickup'],
        'frete grátis' => ['cartId' => '{{cartId}}', 'code' => 'freeshipping'],
        'Melhor Envio (o número é o serviço; veja availableShippingMethods)' => ['cartId' => '{{cartId}}', 'code' => 'melhorenvio:2'],
    ],
    'setCartPaymentMethod' => [
        'Pix (Mercado Pago)' => ['cartId' => '{{cartId}}', 'code' => 'mercadopago_pix'],
        'cartão de crédito (Mercado Pago)' => ['cartId' => '{{cartId}}', 'code' => 'mercadopago_credit'],
        'cartão de débito (Mercado Pago)' => ['cartId' => '{{cartId}}', 'code' => 'mercadopago_debit'],
        'Pix manual' => ['cartId' => '{{cartId}}', 'code' => 'pix'],
        'combinar com a loja' => ['cartId' => '{{cartId}}', 'code' => 'offline'],
    ],
    'applyCartCoupon' => ['' => ['cartId' => '{{cartId}}', 'code' => '{{couponCode}}']],
    'removeCartCoupon' => ['' => ['cartId' => '{{cartId}}']],
    'placeOrder' => ['' => ['cartId' => '{{cartId}}', 'expectedTotal' => 159.9]],
    'payOrder' => [
        'Pix (cliente logado)' => ['number' => '{{orderNumber}}'],
        'Pix (visitante)' => ['number' => '{{orderNumber}}', 'email' => 'visitante@example.com'],
        'cartão' => ['number' => '{{orderNumber}}', 'deviceId' => '{{mpDeviceId}}', 'card' => ['token' => '{{mpCardToken}}', 'paymentMethodId' => 'visa', 'installments' => 3, 'issuerId' => '25']],
    ],
    'guestOrder' => ['' => ['number' => '{{orderNumber}}', 'email' => 'visitante@example.com']],

    // provador
    'tryOn' => ['' => ['id' => '{{tryOnId}}']],

    // admin: catálogo
    'createProduct' => [
        'simples' => ['sku' => 'CAMISETA-BASICA-BR', 'name' => 'Camiseta Básica Branca', 'websiteIds' => ['{{websiteId}}'], 'categoryIds' => ['{{categoryId}}'], 'attributes' => [['code' => 'price', 'value' => '59.90'], ['code' => 'description', 'value' => 'Camiseta 100% algodão.']]],
        'com grade' => ['sku' => 'CAMISETA-GOLA-V', 'name' => 'Camiseta Gola V', 'type' => 'CONFIGURABLE', 'variantAttributes' => ['tamanho'], 'websiteIds' => ['{{websiteId}}'], 'categoryIds' => ['{{categoryId}}']],
    ],
    'updateProduct' => [
        'preço e status' => ['id' => '{{productId}}', 'isActive' => true, 'attributes' => [['code' => 'price', 'value' => '149.90'], ['code' => 'price_promo', 'value' => '129.90']]],
        'nome e categorias' => ['id' => '{{productId}}', 'name' => 'Bermuda Jeans Destroyed Premium', 'categoryIds' => ['{{categoryId}}']],
    ],
    'saveVariants' => ['' => ['productId' => '{{productId}}', 'variants' => [
        ['options' => [['code' => 'tamanho', 'value' => 'P']], 'price' => '59.90'],
        ['options' => [['code' => 'tamanho', 'value' => 'M']], 'price' => '59.90'],
        ['options' => [['code' => 'tamanho', 'value' => 'G']], 'price' => '64.90', 'isActive' => true],
    ]]],
    'deleteProduct' => ['' => ['id' => '{{productId}}']],
    'createCategory' => [
        'principal' => ['websiteId' => '{{websiteId}}', 'name' => 'Promoções', 'position' => 10],
        'subcategoria' => ['parentId' => '{{categoryId}}', 'name' => 'Bermudas', 'urlKey' => 'bermudas'],
    ],
    'updateCategory' => ['' => ['id' => '{{categoryId}}', 'name' => 'Vestuário', 'isActive' => true, 'position' => 1]],
    'moveCategory' => [
        'para dentro de outra' => ['id' => '{{categoryId}}', 'parentId' => '{{parentCategoryId}}', 'position' => 0],
        'virar principal' => ['id' => '{{categoryId}}', 'position' => 0],
    ],
    'deleteCategory' => ['' => ['id' => '{{categoryId}}']],
    'mobileOrder' => ['' => ['websiteId' => '{{websiteId}}']],
    'saveMobileOrder' => ['' => ['websiteId' => '{{websiteId}}', 'enabled' => true, 'categories' => [
        ['id' => '{{categoryId}}', 'visible' => true, 'perCategory' => 10, 'productIds' => ['{{productId}}'], 'showBanner' => true],
    ]]],
    'createStore' => ['' => ['storeName' => 'Loja Centro', 'webview' => 'centro', 'theme' => 'default']],
    'updateStore' => ['' => ['id' => '{{storeId}}', 'storeName' => 'Loja Centro', 'webview' => 'centro', 'theme' => 'default']],
    'deleteStore' => ['' => ['id' => '{{storeId}}']],

    // admin: estoque e frete
    'inventorySource' => ['' => ['code' => 'default']],
    'setProductStock' => ['' => ['sku' => '{{sku}}', 'sources' => [['code' => 'default', 'quantity' => 25, 'status' => 1]]]],
    'shippingTableRates' => ['' => ['websiteId' => '{{websiteId}}']],
    'saveShippingTableRates' => ['' => ['websiteId' => '{{websiteId}}', 'rates' => [
        ['postcodeFrom' => '20000-000', 'postcodeTo' => '28999-999', 'weightTo' => 5, 'price' => 15.9, 'days' => 2],
        ['postcodeFrom' => '20000-000', 'postcodeTo' => '28999-999', 'weightTo' => null, 'price' => 29.9, 'days' => 3],
        ['postcodeFrom' => '01000-000', 'postcodeTo' => '19999-999', 'weightTo' => null, 'price' => 34.9, 'days' => 5],
    ]]],

    // admin: clientes
    'customers' => [
        'busca' => ['search' => 'silva', 'page' => 1, 'pageSize' => 20],
        'filtros' => ['type' => 'PJ', 'websiteId' => '{{websiteId}}', 'isActive' => true],
    ],
    'customer' => [
        'por id' => ['id' => '{{customerId}}'],
        'por e-mail' => ['email' => '{{customerEmail}}', 'websiteId' => '{{websiteId}}'],
    ],
    'createCustomer' => [
        'pessoa física com endereço' => ['websiteId' => '{{websiteId}}', 'groupId' => '{{customerGroupId}}', 'email' => 'cliente.balcao@example.com', 'firstname' => 'Carlos', 'lastname' => 'Lima', 'type' => 'PF', 'taxvat' => '529.982.247-25', 'addresses' => [$address + ['isDefaultShipping' => true, 'isDefaultBilling' => true]]],
        'pessoa jurídica' => ['websiteId' => '{{websiteId}}', 'groupId' => '{{customerGroupId}}', 'email' => 'financeiro@empresa-exemplo.com.br', 'firstname' => 'Paula', 'lastname' => 'Costa', 'type' => 'PJ', 'taxvat' => '11.222.333/0001-81', 'companyName' => 'Empresa Exemplo Ltda', 'stateRegistration' => '12.345.678'],
    ],
    'updateCustomer' => [
        'dados' => ['id' => '{{customerId}}', 'firstname' => 'Carlos', 'lastname' => 'Lima Neto'],
        'desativar' => ['id' => '{{customerId}}', 'isActive' => false],
    ],
    'deleteCustomer' => ['' => ['id' => '{{customerId}}']],

    // admin: vendas
    'orders' => [
        'recentes' => ['page' => 1, 'pageSize' => 20, 'sort' => 'CREATED_AT', 'sortDir' => 'DESC'],
        'aguardando pagamento' => ['status' => 'pending_payment', 'websiteId' => '{{websiteId}}'],
        'período, canal e pagamento' => ['from' => '2026-10-01', 'to' => '2026-10-31', 'salesChannel' => 'App mobile', 'paymentMethod' => 'mercadopago_pix'],
        'cancelados por motivo' => ['status' => 'canceled', 'cancelReason' => 'UNPAID'],
        'busca' => ['search' => 'silva'],
    ],
    'order' => [
        'por número' => ['number' => '{{orderNumber}}'],
        'por id' => ['id' => '{{orderId}}'],
    ],
    'salesSummary' => ['' => ['websiteId' => '{{websiteId}}', 'from' => '2026-10-01', 'to' => '2026-10-31']],
    'confirmOrderPayment' => ['' => ['id' => '{{orderId}}', 'comment' => 'Comprovante recebido pelo WhatsApp.']],
    'shipOrder' => ['' => ['id' => '{{orderId}}', 'sourceCode' => 'default', 'carrier' => 'Correios', 'trackingCode' => 'AA123456789BR']],
    'completeOrder' => ['' => ['id' => '{{orderId}}']],
    'cancelOrder' => [
        'a pedido do cliente' => ['id' => '{{orderId}}', 'reason' => 'CUSTOMER'],
        'outro motivo' => ['id' => '{{orderId}}', 'reason' => 'OTHER', 'comment' => 'Endereço fora da área de entrega.'],
        'pago, devolvido por fora' => ['id' => '{{orderId}}', 'reason' => 'OUT_OF_STOCK', 'refund' => false, 'refundNote' => 'Devolvido por Pix em 13/11.'],
        'teste, sem avisar' => ['id' => '{{orderId}}', 'reason' => 'ORDER_ERROR', 'notify' => false],
    ],
    'addOrderComment' => ['' => ['id' => '{{orderId}}', 'comment' => 'Cliente pediu embrulho para presente.', 'notifyCustomer' => false]],
    'createAdminCart' => [
        'para cliente' => ['websiteId' => '{{websiteId}}', 'customerId' => '{{customerId}}'],
        'para visitante' => ['websiteId' => '{{websiteId}}'],
    ],
    'placeAdminOrder' => [
        '' => ['cartId' => '{{cartId}}', 'notifyCustomer' => true, 'comment' => 'Venda pelo WhatsApp.'],
        'balcão, já pago' => ['cartId' => '{{cartId}}', 'paymentReceived' => true, 'notifyCustomer' => false],
    ],

    // admin: mensagens e relatórios
    'adminMessageInbox' => ['' => ['unreadOnly' => true, 'page' => 1]],
    'adminCustomerMessages' => ['' => ['customerId' => '{{customerId}}', 'limit' => 50]],
    'adminSendCustomerMessage' => ['' => ['customerId' => '{{customerId}}', 'body' => 'Olá! Seu pedido saiu para entrega.']],
    'report' => [
        'mês atual' => ['code' => 'v1', 'filters' => [['key' => 'preset', 'value' => 'month']], 'page' => 1, 'pageSize' => 50],
        'período e website' => ['code' => 'v1', 'filters' => [['key' => 'preset', 'value' => ''], ['key' => 'from', 'value' => '2026-10-01'], ['key' => 'to', 'value' => '2026-10-31'], ['key' => 'website_id', 'value' => '{{websiteId}}']], 'direction' => 'DESC'],
    ],
];

// salvam no ambiente o que as próximas chamadas usam
const CAPTURE = [
    'generateAdminToken' => ['adminToken' => 'generateAdminToken.token'],
    'generateCustomerToken' => ['customerToken' => 'generateCustomerToken.token', 'customerId' => 'generateCustomerToken.customer.id'],
    'refreshCustomerToken' => ['customerToken' => 'refreshCustomerToken.token'],
    'registerCustomer' => ['customerToken' => 'registerCustomer.token', 'customerId' => 'registerCustomer.customer.id'],
    'confirmCustomerEmail' => ['customerToken' => 'confirmCustomerEmail.token'],
    'createCart' => ['cartId' => 'createCart'],
    'createAdminCart' => ['cartId' => 'createAdminCart'],
    'addToCart' => ['cartItemId' => 'addToCart.items.0.id'],
    'createMyAddress' => ['addressId' => 'createMyAddress.0.id'],
    'placeOrder' => ['orderNumber' => 'placeOrder.number', 'orderId' => 'placeOrder.id'],
    'placeAdminOrder' => ['orderNumber' => 'placeAdminOrder.number', 'orderId' => 'placeAdminOrder.id'],
    'createProduct' => ['productId' => 'createProduct.id'],
    'createCustomer' => ['customerId' => 'createCustomer.id'],
    'startTryOn' => ['tryOnId' => 'startTryOn.id'],
];

// ---------------------------------------------------------------- seleção de campos

// campos (fora da raiz) que exigem token de admin: ficam de fora das requisições de cliente e visitante
const ADMIN_FIELDS = ['ProductStock.sources'];

/** Os campos escalares e, até $depth níveis, os objetos dentro; pula campos com argumento obrigatório. */
function selection(Type $type, int $depth, string $indent, bool $admin): string
{
    $type = Type::getNamedType($type);
    if ($type instanceof UnionType) {
        $out = "__typename";
        foreach ($type->getTypes() as $t) {
            $inner = selection($t, $depth, $indent . '    ', $admin);
            $out .= "\n{$indent}... on {$t->name} {\n{$indent}    {$inner}\n{$indent}}";
        }
        return $out;
    }
    if (!$type instanceof ObjectType && !$type instanceof InterfaceType) {
        return '';
    }
    $lines = [];
    foreach ($type->getFields() as $field) {
        /** @var FieldDefinition $field */
        if (!$admin && in_array("{$type->name}.{$field->name}", ADMIN_FIELDS, true)) {
            continue;
        }
        foreach ($field->args as $arg) {
            if ($arg->getType() instanceof NonNull && !$arg->defaultValueExists()) {
                continue 2;
            }
        }
        $named = Type::getNamedType($field->getType());
        if ($named instanceof ObjectType || $named instanceof InterfaceType || $named instanceof UnionType) {
            if ($depth > 0) {
                $inner = selection($named, $depth - 1, $indent . '  ', $admin);
                if ($inner !== '') {
                    $lines[] = "{$field->name} {\n{$indent}  {$inner}\n{$indent}}";
                }
            }
        } else {
            $lines[] = $field->name;
        }
    }
    return implode("\n{$indent}", $lines);
}

function typeOf(Type $t): string
{
    return $t->toString();
}

$depthRule = new QueryDepth((int) Config::get('system/api/max_depth'));
$limits = ['public' => (int) Config::get('system/api/max_complexity'), 'admin' => (int) Config::get('system/api/max_complexity_admin')];

/** O documento da operação, com a seleção mais rica que passa nos limites da API. */
function document(string $kind, FieldDefinition $field, array $vars, array $rulesBase, int $limit, $schema, bool $admin): string
{
    $decl = $call = [];
    $unknown = array_diff(array_keys($vars), array_map(fn($a) => $a->name, $field->args));
    if ($unknown) {
        fwrite(STDERR, "ERRO em {$field->name}: argumento inexistente " . implode(', ', $unknown) . "\n");
        exit(1);
    }
    foreach ($field->args as $arg) {
        if (array_key_exists($arg->name, $vars)) {
            $decl[] = '$' . $arg->name . ': ' . typeOf($arg->getType());
            $call[] = $arg->name . ': $' . $arg->name;
        }
    }
    $name = ucfirst($field->name);
    $head = $kind . ' ' . $name . ($decl ? '(' . implode(', ', $decl) . ')' : '');
    $callStr = $field->name . ($call ? '(' . implode(', ', $call) . ')' : '');
    foreach ([2, 1, 0] as $depth) {
        $sel = selection($field->getType(), $depth, '    ', $admin);
        $doc = $head . " {\n  " . $callStr . ($sel !== '' ? " {\n    " . $sel . "\n  }" : '') . "\n}\n";
        $complexity = new QueryComplexity($limit);
        $complexity->setRawVariableValues($vars);
        $errors = DocumentValidator::validate($schema, GraphQL\Language\Parser::parse($doc), array_merge($rulesBase, [$complexity]));
        if (!$errors) {
            return $doc;
        }
        $last = $errors;
    }
    fwrite(STDERR, "ERRO em {$field->name}: " . $last[0]->getMessage() . "\n");
    exit(1);
}

// ---------------------------------------------------------------- monta a collection

$folderOf = [];
foreach (FOLDERS as $folder => $ops) {
    foreach ($ops as $op) {
        $folderOf[$op] = $folder;
    }
}

$items = array_fill_keys(array_keys(FOLDERS), []);
$rulesBase = array_merge(DocumentValidator::allRules(), [$depthRule]);
$count = 0;

foreach (['query' => $schema->getQueryType(), 'mutation' => $schema->getMutationType()] as $kind => $root) {
    foreach ($root->getFields() as $field) {
        $op = $field->name;
        if (!isset($folderOf[$op])) {
            fwrite(STDERR, "Sem pasta: {$op} (inclua em FOLDERS)\n");
            exit(1);
        }
        $access = in_array($op, PUBLIC_OPS, true) ? 'public' : (in_array($op, CUSTOMER, true) ? 'customer' : (in_array($op, CART_OPS, true) ? 'cart' : 'admin'));

        $variants = $examples[$op] ?? null;
        if ($variants === null) {
            $vars = [];
            foreach ($field->args as $arg) {
                if ($arg->getType() instanceof NonNull && !$arg->defaultValueExists()) {
                    $vars[$arg->name] = AUTO[$arg->name] ?? (str_ends_with($arg->name, 'Id') || $arg->name === 'id' ? '1' : '');
                }
            }
            $variants = ['' => $vars];
        }

        foreach ($variants as $label => $vars) {
            // uploads vão no multipart: a variável fica null e o arquivo entra pelo map
            $uploads = [];
            foreach ($field->args as $arg) {
                if (Type::getNamedType($arg->getType())->name === 'Upload') {
                    $uploads[$arg->name] = $arg->getType() instanceof GraphQL\Type\Definition\ListOfType
                        || ($arg->getType() instanceof NonNull && $arg->getType()->getWrappedType() instanceof GraphQL\Type\Definition\ListOfType);
                    $vars[$arg->name] = $uploads[$arg->name] ? [null] : null;
                }
            }
            if ($op === 'uploadProductImage') {
                $vars += ['productId' => '{{productId}}', 'code' => 'imagem'];
            }
            if ($op === 'setCategoryBanners') {
                $vars = ['categoryId' => '{{categoryId}}', 'keep' => []] + $vars;
            }
            if ($op === 'startTryOn') {
                $vars += ['productId' => '{{productId}}'];
            }

            $limit = $access === 'admin' ? $limits['admin'] : $limits['public'];
            $query = document($kind, $field, $vars, $rulesBase, $limit, $schema, $access === 'admin');

            $headers = [['key' => 'X-Api-Key', 'value' => '{{apiKey}}', 'description' => 'Sistema → Chaves da API. Dispensada com token de admin.']];
            if ($op === 'placeOrder') {
                $headers[] = ['key' => 'X-Sales-Channel', 'value' => '{{salesChannel}}', 'description' => 'Canal gravado no pedido; sem ele, "App mobile".'];
            }
            $auth = match ($access) {
                'public' => ['type' => 'noauth'],
                'admin' => ['type' => 'bearer', 'bearer' => [['key' => 'token', 'value' => '{{adminToken}}', 'type' => 'string']]],
                default => ['type' => 'bearer', 'bearer' => [['key' => 'token', 'value' => '{{customerToken}}', 'type' => 'string']]],
            };
            if ($op === 'currentAdmin' || $op === 'revokeAdminToken') {
                $auth = ['type' => 'bearer', 'bearer' => [['key' => 'token', 'value' => '{{adminToken}}', 'type' => 'string']]];
            }
            // admin monta o pedido no carrinho dele (createAdminCart): as mutations do carrinho aceitam o token de admin também
            if ($op === 'payOrder' && str_contains($label, 'visitante')) {
                $auth = ['type' => 'noauth'];
            }

            if ($uploads) {
                $map = [];
                $form = [];
                $i = 0;
                foreach ($uploads as $arg => $isList) {
                    $map[(string) $i] = [$isList ? "variables.{$arg}.0" : "variables.{$arg}"];
                    $form[] = ['key' => (string) $i, 'type' => 'file', 'src' => [], 'description' => 'Escolha o arquivo (JPG, PNG, WebP...).'];
                    $i++;
                }
                $body = ['mode' => 'formdata', 'formdata' => array_merge([
                    ['key' => 'operations', 'value' => json_encode(['query' => $query, 'variables' => $vars], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'type' => 'text'],
                    ['key' => 'map', 'value' => json_encode($map), 'type' => 'text'],
                ], $form)];
            } else {
                $body = ['mode' => 'graphql', 'graphql' => [
                    'query' => $query,
                    'variables' => $vars ? json_encode($vars, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '{}',
                ]];
                $headers[] = ['key' => 'Content-Type', 'value' => 'application/json'];
            }

            $events = [];
            if (isset(CAPTURE[$op])) {
                $js = ["const r = pm.response.json();", "const get = (p) => p.split('.').reduce((o, k) => (o == null ? o : o[k]), r.data);"];
                foreach (CAPTURE[$op] as $var => $path) {
                    $js[] = "if (get('{$path}') != null) pm.environment.set('{$var}', String(get('{$path}')));";
                }
                $events[] = ['listen' => 'test', 'script' => ['type' => 'text/javascript', 'exec' => $js]];
            }
            $events[] = ['listen' => 'test', 'script' => ['type' => 'text/javascript', 'exec' => [
                "pm.test('sem errors', () => pm.expect(pm.response.json().errors, JSON.stringify(pm.response.json().errors)).to.be.undefined);",
            ]]];

            $who = ['public' => 'público', 'customer' => 'cliente (token)', 'cart' => 'visitante (cartId) ou cliente (token)', 'admin' => 'admin (token)'][$access];
            $items[$folderOf[$op]][] = [
                'name' => $op . ($label !== '' ? " — {$label}" : ''),
                'event' => $events,
                'request' => [
                    'method' => 'POST',
                    'auth' => $auth,
                    'header' => $headers,
                    'body' => $body,
                    'url' => ['raw' => '{{baseUrl}}/core/graphql/index', 'host' => ['{{baseUrl}}'], 'path' => ['core', 'graphql', 'index']],
                    'description' => trim(($field->description ?? '') . "\n\nAcesso: {$who}."),
                ],
            ];
            $count++;
        }
    }
}

// na ordem de FOLDERS (dentro de cada pasta, a ordem da lista)
$out = [];
foreach (FOLDERS as $folder => $ops) {
    $order = array_flip($ops);
    usort($items[$folder], fn($a, $b) => $order[explode(' — ', $a['name'])[0]] <=> $order[explode(' — ', $b['name'])[0]]);
    $out[] = ['name' => $folder, 'item' => $items[$folder]];
}

$collection = [
    'info' => [
        'name' => 'IterStore API (GraphQL)',
        'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
        'description' => "Todas as queries e mutations do IterStore, com exemplos. Gerada por plan/postman/gerar.php a partir do schema.\n\n"
            . "1. Importe a collection e o ambiente \"IterStore (dev)\" e ajuste baseUrl, apiKey e os logins.\n"
            . "2. Rode \"01 Autenticação → generateAdminToken\" e/ou \"generateCustomerToken\": o token vai para o ambiente.\n"
            . "3. Checkout: createCart → addToCart → setCartGuestInfo (visitante) → setCartShippingAddress → setCartShippingMethod → setCartPaymentMethod → placeOrder → payOrder. cartId, itemId, número e id do pedido são salvos sozinhos.\n\n"
            . "Envio de arquivo (uploadProductImage, setCategoryBanners, startTryOn): body form-data, escolha o arquivo no campo \"0\".",
    ],
    'item' => $out,
];

$env = [
    'name' => 'IterStore (dev)',
    'values' => array_map(fn($k, $v) => ['key' => $k, 'value' => $v, 'type' => str_contains(strtolower($k), 'password') || str_contains(strtolower($k), 'token') || $k === 'apiKey' ? 'secret' : 'default', 'enabled' => true], array_keys($vals = [
        'baseUrl' => 'http://localhost:8080',
        'apiKey' => '',
        'adminUser' => 'admin',
        'adminPassword' => '',
        'adminToken' => '',
        'customerEmail' => 'cliente@example.com',
        'customerPassword' => '',
        'customerToken' => '',
        'websiteId' => '15',
        'storeId' => '1',
        'categoryId' => '224',
        'parentCategoryId' => '6301',
        'productId' => '1977',
        'productUrlKey' => 'bermuda-jeans-destroyed',
        'sku' => 'MODA-BERM-JEANS-AZUL-P',
        'customerId' => '',
        'customerGroupId' => '1',
        'addressId' => '',
        'cartId' => '',
        'cartItemId' => '',
        'couponCode' => 'BEMVINDO10',
        'orderNumber' => '',
        'orderId' => '',
        'salesChannel' => 'Web',
        'mpCardToken' => '',
        'mpDeviceId' => '',
        'tryOnId' => '',
        'emailToken' => '',
    ]), $vals),
];

$dir = __DIR__;
file_put_contents("$dir/IterStore.postman_collection.json", json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
file_put_contents("$dir/IterStore.postman_environment.json", json_encode($env, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
echo "Collection gerada: {$count} requisições em " . count($out) . " pastas.\n";
