<?php

declare(strict_types=1);

/**
 * DB-PLAN phase 5 benchmark: statements and time of the lists that load EAV values. Runs the
 * GraphQL in-process as the first active admin with full access (read-only queries).
 *
 *     php bin/db-bench.php [pageSize=100] [runs=5]
 */

require_once __DIR__ . '/../path.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once FUNCTIONS . 'bootstrap.php';

use Iter\Admin\Model\AdminUser;
use Iter\Core\Db\Adapter;
use Iter\Core\Db\Connection;

$pageSize = (int) ($argv[1] ?? 100);
$runs = max(1, (int) ($argv[2] ?? 5));
$db = Connection::get()->adapter();
$adminId = $db->fetchOne("SELECT u.user_id FROM admin_user u JOIN admin_role_rule r ON r.role_id = u.role_id AND r.resource_id = 'Iter_Admin::root' WHERE u.is_active = 1 ORDER BY u.user_id LIMIT 1");
// ponytail: the session-less admin is set straight in Auth's static, as a token would
Closure::bind(static fn () => \Iter\Admin\Auth::$user = (new AdminUser())->load((int) $adminId), null, \Iter\Admin\Auth::class)();

$schema = Iter\Core\GraphQl\Schema::build();
$cases = [
    'products (só a lista)' => "{ products(pageSize: {$pageSize}) { items { sku name } } }",
    'products + attributes' => "{ products(pageSize: {$pageSize}) { items { sku attributes { code value } } } }",
    'products + priceRange + variantOptions' => "{ products(pageSize: {$pageSize}) { items { sku priceRange { min max } variants { variantOptions { label } } } } }",
    'products filtro de preço + aggregations' => "{ products(pageSize: {$pageSize}, priceFrom: 10, priceTo: 200) { total items { sku } aggregations { price { min max } attributes { code options { value count } } } } }",
    'products + websiteIds + categories + variantAttributes' => "{ products(pageSize: {$pageSize}) { items { sku websiteIds categories { id } variantAttributes { code options { value } } } } }",
    'orders (pedido completo)' => "{ orders(pageSize: {$pageSize}) { items { number items { sku } shippingAddress { city } history { status } shipment { carrier } } } }",
    'customers + attributes' => "{ customers(pageSize: {$pageSize}) { items { email attributes { code value } } } }",
];
printf("%-40s %8s %10s %10s\n", 'consulta', 'itens', 'statements', 'ms (média)');
foreach ($cases as $label => $query) {
    $times = [];
    for ($i = 0; $i < $runs; $i++) {
        Adapter::$queryCount = 0;
        $start = hrtime(true);
        $result = GraphQL\GraphQL::executeQuery($schema, $query)->toArray();
        $times[] = (hrtime(true) - $start) / 1e6;
        $count = Adapter::$queryCount;
    }
    if (isset($result['errors'])) {
        printf("%-40s ERRO: %s\n", $label, $result['errors'][0]['message']);
        continue;
    }
    $items = count(reset($result['data'])['items']);
    sort($times);
    printf("%-40s %8d %10d %10.1f\n", $label, $items, $count, $times[intdiv(count($times), 2)]);
}
