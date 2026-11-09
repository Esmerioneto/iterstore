<?php
// Runs the technical manual's API examples against the real DB inside a transaction that is rolled back,
// and writes each request and response (trimmed) to api-examples.json.
declare(strict_types=1);
require '/home/esmerio/projetos/iterstore/path.php';
require '/home/esmerio/projetos/iterstore/vendor/autoload.php';
require FUNCTIONS . 'bootstrap.php';

use Iter\Admin\Auth;
use Iter\Admin\Model\AdminUser;
use Iter\Core\Db\Connection;
use Iter\Customer\Model\CustomerAuth;

$db = Connection::get()->adapter();
$pdo = Connection::get()->connect();
$db->beginTransaction();
register_shutdown_function(static fn () => $db->inTransaction() && $db->rollBack());

$schema = Iter\Core\GraphQl\Schema::build();
$reset = static function (): void {
    foreach ([[Auth::class, 'user'], [Auth::class, 'token'], [CustomerAuth::class, 'customer'], [CustomerAuth::class, 'token']] as [$c, $p]) {
        (new ReflectionProperty($c, $p))->setValue(null, null);
    }
    $_SESSION = [];
};
$out = [];
$vars = [];
/** runs one example as the given token; $key names it in the json */
$run = static function (string $key, string $query, array $variables = [], ?string $token = null, array $headers = []) use ($schema, $reset, &$out): array {
    $reset();
    if ($token !== null && !Auth::useToken($token)) {
        CustomerAuth::useToken($token);
    }
    foreach ($headers as $h => $v) {
        $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $h))] = $v;
    }
    $result = GraphQL\GraphQL::executeQuery($schema, $query, null, null, $variables ?: null)->toArray();
    foreach ($headers as $h => $v) {
        unset($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $h))]);
    }
    $out[$key] = ['query' => $query, 'variables' => $variables, 'token' => $token !== null, 'headers' => $headers, 'response' => $result];
    if (isset($result['errors'])) {
        fwrite(STDERR, "{$key}: " . json_encode($result['errors'], JSON_UNESCAPED_UNICODE) . "\n");
    }

    return $result;
};

// a temporary admin (rolled back with everything else)
$role = (int) $pdo->query("SELECT role_id FROM admin_role_rule WHERE resource_id = 'Iter_Admin::root' ORDER BY role_id LIMIT 1")->fetchColumn();
(new AdminUser())->addData(['username' => 'integracao', 'firstname' => 'Integração', 'lastname' => 'ERP', 'email' => 'integracao@exemplo.com.br', 'is_active' => 1, 'role_id' => $role])->setPassword('SenhaForte123')->save();

// --- admin login --------------------------------------------------------------------------------------------
$r = $run('adminToken', 'mutation {
  generateAdminToken(username: "integracao", password: "SenhaForte123") {
    token
    expires_at
    user { username role }
  }
}');
$admin = $r['data']['generateAdminToken']['token'];
$run('adminWrong', 'mutation { generateAdminToken(username: "integracao", password: "errada") { token } }');
$run('currentAdmin', '{ currentAdmin { id username firstname role } }', [], $admin);

// --- store and catalog (public) -----------------------------------------------------------------------------
$run('websites', '{ websites { id name isDefault } storeInfo { name email phone address { city region } } }');
$r = $run('categories', '{ categories { id name url_key level children { id name url_key } } }');
$r = $run('products', 'query Produtos($busca: String, $pagina: Int) {
  products(search: $busca, isActive: true, page: $pagina, pageSize: 3) {
    total
    items {
      id sku name type url_key
      priceRange { min max }
      stock { salable_quantity is_in_stock }
    }
    aggregations { price { min max } }
  }
}', ['busca' => 'blusa', 'pagina' => 1]);
// a simple product with stock, for the cart
$simple = $db->fetchRow("SELECT p.product_id, p.sku FROM catalog_product p WHERE p.type = 'simple' AND p.is_active = 1
    AND EXISTS (SELECT 1 FROM inventory_source_item i WHERE i.product_id = p.product_id AND i.quantity >= 3 AND i.status = 1) ORDER BY p.product_id LIMIT 1");
$configurable = $db->fetchOne("SELECT sku FROM catalog_product WHERE type = 'configurable' AND is_active = 1 ORDER BY product_id LIMIT 1");
$run('productBySku', 'query Produto($sku: String!) {
  product(sku: $sku) {
    sku name type
    priceRange { min max }
    variantAttributes { code label }
    variants { sku name stock { salable_quantity } attributes { code option_label } }
  }
}', ['sku' => $configurable]);
$run('route', '{ route(url: "/' . $db->fetchOne("SELECT url_key FROM catalog_product WHERE sku = ?", [$configurable]) . '") { type product { sku name } category { name } } }');

// --- guest checkout -----------------------------------------------------------------------------------------
$r = $run('createCart', 'mutation { createCart }');
$cart = $r['data']['createCart'];
$cartFields = 'items { id sku name quantity price rowTotal error } subtotal discountAmount shippingAmount total hasErrors';
$run('addToCart', 'mutation Adicionar($cartId: String!, $sku: String!) {
  addToCart(cartId: $cartId, sku: $sku, quantity: 2) { ' . $cartFields . ' }
}', ['cartId' => $cart, 'sku' => $simple['sku']]);
$run('addConfigurable', 'mutation { addToCart(cartId: "' . $cart . '", sku: "' . $configurable . '", quantity: 1) { total } }');
$run('guestInfo', 'mutation Visitante($cartId: String!) {
  setCartGuestInfo(cartId: $cartId, email: "maria@exemplo.com.br", firstname: "Maria", lastname: "Souza", taxvat: "529.982.247-25") { email firstname taxvat }
}', ['cartId' => $cart]);
$run('shippingQuotes', '{ shippingQuotes(cartId: "' . $cart . '", destinationCep: "20040-020") { code label amount days } }');
$r = $run('shippingAddress', 'mutation Endereco($cartId: String!, $endereco: CartAddressInput!) {
  setCartShippingAddress(cartId: $cartId, address: $endereco) {
    shippingAddress { postcode city region }
    availableShippingMethods { code label amount days }
  }
}', ['cartId' => $cart, 'endereco' => ['recipient' => 'Maria Souza', 'postcode' => '20040-020', 'street' => 'Avenida Rio Branco', 'number' => '1', 'district' => 'Centro', 'city' => 'Rio de Janeiro', 'region' => 'RJ', 'telephone' => '(21) 99999-0000']]);
$methods = $r['data']['setCartShippingAddress']['availableShippingMethods'] ?? [];
$shipping = $methods[0]['code'] ?? 'pickup';
foreach ($methods as $m) {
    if ($m['code'] === 'pickup') { $shipping = 'pickup'; }
}
$r = $run('shippingMethod', 'mutation { setCartShippingMethod(cartId: "' . $cart . '", code: "' . $shipping . '") { shippingMethod { code label amount } total availablePaymentMethods { code label } } }');
$payments = array_column($r['data']['setCartShippingMethod']['availablePaymentMethods'] ?? [], 'code');
$payment = in_array('pix', $payments, true) ? 'pix' : (in_array('offline', $payments, true) ? 'offline' : ($payments[0] ?? 'pix'));
$r = $run('paymentMethod', 'mutation { setCartPaymentMethod(cartId: "' . $cart . '", code: "' . $payment . '") { paymentMethod { code label } total } }');
$total = $r['data']['setCartPaymentMethod']['total'] ?? 0;
$run('totalChanged', 'mutation { placeOrder(cartId: "' . $cart . '", expectedTotal: 1.00) { number } }');
$r = $run('placeOrder', 'mutation Fechar($cartId: String!, $total: Float!) {
  placeOrder(cartId: $cartId, expectedTotal: $total) {
    id number status statusLabel total salesChannel
    paymentMethod paymentInstructions
    items { sku name quantity price rowTotal }
  }
}', ['cartId' => $cart, 'total' => $total], null, ['X-Sales-Channel' => 'Site']);
$order = $r['data']['placeOrder'];
$run('guestOrder', '{ guestOrder(number: "' . $order['number'] . '", email: "maria@exemplo.com.br") { number statusLabel total } }');

// --- customer ------------------------------------------------------------------------------------------------
$pdo->exec("INSERT INTO core_config_data (scope, scope_id, path, value) VALUES ('default', 0, 'customer/account/confirm_email', '0') ON CONFLICT (scope, scope_id, path) DO UPDATE SET value = '0'"); // the example shows the login right away
$r = $run('register', 'mutation Cadastro($senha: String!) {
  registerCustomer(email: "joao@exemplo.com.br", firstname: "João", lastname: "Lima", type: PF, taxvat: "111.444.777-35", password: $senha) {
    token expires_at confirmationRequired
    customer { id email firstname }
  }
}', ['senha' => 'SenhaDoJoao123']);
$r = $run('customerToken', 'mutation {
  generateCustomerToken(email: "joao@exemplo.com.br", password: "SenhaDoJoao123") { token expires_at customer { id firstname } }
}');
$customer = $r['data']['generateCustomerToken']['token'] ?? '';
$run('me', '{ me { id email firstname lastname type taxvat addresses { id city is_default_shipping } } }', [], $customer);
$run('createAddress', 'mutation {
  createMyAddress(address: { recipient: "João Lima", postcode: "01310-100", street: "Avenida Paulista", number: "1000", district: "Bela Vista", city: "São Paulo", region: "SP", isDefaultShipping: true, isDefaultBilling: true }) {
    id city region is_default_shipping
  }
}', [], $customer);
$r = $run('customerCart', '{ cart { isGuest email items { sku quantity } total } }', [], $customer);
$guestCart = $run('createCart2', 'mutation { createCart }')['data']['createCart'];
$run('addGuest2', 'mutation { addToCart(cartId: "' . $guestCart . '", sku: "' . $simple['sku'] . '", quantity: 1) { total } }');
$run('mergeCarts', 'mutation { mergeCarts(cartId: "' . $guestCart . '") { isGuest items { sku quantity } total } }', [], $customer);
$run('myOrders', '{ myOrders { total items { number statusLabel total } } }', [], $customer);
$run('noToken', '{ me { id } }');

// --- admin: orders, catalog, stock, reports ----------------------------------------------------------------------
$run('orders', '{
  orders(status: pending_payment, page: 1, pageSize: 2) {
    total amount
    items { number createdAt statusLabel total salesChannel paymentLabel }
  }
}', [], $admin);
$run('ordersForbidden', '{ orders { total } }', [], $customer);
$run('confirmPayment', 'mutation { confirmOrderPayment(id: "' . $order['id'] . '", comment: "Pix conferido no extrato") { number status statusLabel history { status comment createdAt } } }', [], $admin);
$run('shipOrder', 'mutation { shipOrder(id: "' . $order['id'] . '", carrier: "Correios", trackingCode: "AA123456789BR") { number statusLabel shipment { carrier trackingCode } } }', [], $admin);
$run('salesSummary', '{ salesSummary(from: "' . date('Y-m-01') . '", to: "' . date('Y-m-d') . '") { status statusLabel count amount } }', [], $admin);
$setId = $db->fetchOne("SELECT attribute_set_id FROM eav_attribute_set WHERE entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE code = 'product') ORDER BY attribute_set_id LIMIT 1");
$website = $db->fetchOne('SELECT website_id FROM store_website ORDER BY website_id LIMIT 1');
$category = $db->fetchOne('SELECT category_id FROM catalog_category WHERE website_id = ? AND parent_id IS NOT NULL ORDER BY category_id LIMIT 1', [$website]);
$r = $run('createProduct', 'mutation Novo($attrs: [ProductAttributeInput!]) {
  createProduct(sku: "CANECA-001", name: "Caneca de cerâmica", attributeSetId: "' . $setId . '", websiteIds: ["' . $website . '"], categoryIds: ["' . $category . '"], attributes: $attrs) {
    id sku name url_key is_active
    attributes { code value }
  }
}', ['attrs' => [['code' => 'price', 'value' => '49.90'], ['code' => 'cost', 'value' => '18.50'], ['code' => 'weight', 'value' => '0.4']]], $admin);
$source = $db->fetchOne('SELECT code FROM inventory_source WHERE website_id = ? AND is_enabled = 1 ORDER BY priority, source_id LIMIT 1', [$website]);
$run('setStock', 'mutation { setProductStock(sku: "CANECA-001", sources: [{ code: "' . $source . '", quantity: 25 }]) { salable_quantity is_in_stock sources { code quantity } } }', [], $admin);
$run('updateProduct', 'mutation { updateProduct(id: "' . ($r['data']['createProduct']['id'] ?? 0) . '", attributes: [{ code: "price", value: "44.90" }]) { sku attributes { code value } } }', [], $admin);
$run('reports', '{ reports { code groupLabel title filters { key type default options { value label } } } }', [], $admin);
$run('report', 'query {
  report(code: "v1", filters: [{ key: "preset", value: "month" }, { key: "group_by", value: "day" }], pageSize: 5) {
    columns { key label type }
    rows
    total
    totals
    tiles { label value note }
  }
}', [], $admin);
$run('attributes', '{ attributes(entityType: "product") { code label inputType isRequired isFilterable } }', [], $admin);
$run('revokeAdmin', 'mutation { revokeAdminToken }', [], $admin);

$db->rollBack();
file_put_contents(__DIR__ . '/api-examples.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo count($out), " exemplos\n";
