<?php

declare(strict_types=1);

// Relatórios (REPORT-PLAN deliveries 1 to 5) against the real DB, inside a transaction that is rolled back: orders in
// March 2001 (no real order is that old), V1 against the sales dashboard, the margin, the cost kept from who
// may not see it (screen, files, API), the three files, the stock, catalog, customer, channel, region, promotion, shipping, service, shipment, finance and margin reports, the export log, the 366-day limit and the 403.

require_once __DIR__ . '/../path.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once FUNCTIONS . 'bootstrap.php';

use Iter\Admin\Auth;
use Iter\Admin\Model\AdminRole;
use Iter\Admin\Model\AdminUser;
use Iter\Catalog\Model\Product;
use Iter\Core\Db\Connection;
use Iter\Report\Model\Export;
use Iter\Report\Model\Format;
use Iter\Report\Model\ReportService;
use Iter\Report\View\ReportView;
use Iter\Sales\Model\DashboardService;

function check(bool $condition, string $message, mixed $got = null): void
{
    if (!$condition) {
        fwrite(STDERR, "FALHOU: {$message}\n" . ($got === null ? '' : 'obtido: ' . var_export($got, true) . "\n"));
        exit(1);
    }
    echo "OK: {$message}\n";
}

if (!\isModuleEnabled('iter_report')) {
    echo "PULADO: Relatórios (módulo desligado)\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/iter-report-selftest-' . bin2hex(random_bytes(4)) . '/';
mkdir($tmp);
putenv('ITER_DASHBOARD_DIR=' . $tmp . 'dashboard/'); // the sales dashboard's cache, away from var/cache
putenv('ITER_LOG_DIR=' . $tmp . 'log/');
$pdo = Connection::get()->connect();
$db = Connection::get()->adapter();
$websiteId = (int) $pdo->query('SELECT website_id FROM store_website ORDER BY website_id LIMIT 1')->fetchColumn();
$service = new ReportService();
$v1 = ReportService::get('v1');

// --- V1 against the sales dashboard: the store's real last 7 days (the dashboard runs its own transaction) ---
[$week] = ReportService::filters($v1, ['preset' => '7d', 'website_id' => (string) $websiteId, 'group_by' => 'day'], true);
$real = $service->totals($v1, $week);
$dashboard = (new DashboardService())->data($websiteId, 7)['totals'];
check([$dashboard['paid']['orders'], $dashboard['paid']['revenue'], $dashboard['pending']['orders'], $dashboard['canceled']['orders']] === [(int) $real['orders'], round((float) $real['total'], 2), (int) $real['pending'], (int) $real['canceled']],
    'V1 bate com o Painel de vendas nos últimos 7 dias (pagos e faturamento, aguardando, cancelados)', [$dashboard, $real]);

$db->beginTransaction(); // through the Adapter: the models' own transactions nest in it
register_shutdown_function(static function () use ($db, $tmp): void {
    $db->inTransaction() && $db->rollBack();
    exec('rm -rf ' . escapeshellarg($tmp));
});

// --- data: two products, an admin per permission, four orders ----------------------------------------------
$products = $pdo->query('SELECT product_id, sku FROM catalog_product WHERE parent_id IS NULL AND is_active = 1 ORDER BY product_id LIMIT 2')->fetchAll(PDO::FETCH_KEY_PAIR);
if (!$websiteId || count($products) < 2) {
    fwrite(STDERR, "É necessário um website e dois produtos ativos para o teste de relatórios.\n");
    exit(2);
}
[$x, $y] = array_keys($products);
$costAttribute = (int) $pdo->query("SELECT attribute_id FROM eav_attribute WHERE code = '" . Product::COST . "' AND entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE code = '" . Product::ENTITY_TYPE . "')")->fetchColumn();
$pdo->prepare('DELETE FROM eav_value_decimal WHERE attribute_id = ? AND entity_id IN (?, ?)')->execute([$costAttribute, $x, $y]);
$pdo->prepare('INSERT INTO eav_value_decimal (attribute_id, entity_id, value) VALUES (?, ?, 30)')->execute([$costAttribute, $x]);

$admin = static function (string $name, array $resources): int {
    $role = (new AdminRole())->setData('role_name', 'report_selftest_' . $name)->setResources($resources);
    $role->save();
    $user = (new AdminUser())->addData(['username' => 'report_selftest_' . $name, 'email' => $name . '@selftest.local', 'is_active' => 1, 'role_id' => (int) $role->getId()])->setPassword('Selftest123');
    $user->save();

    return (int) $user->getId();
};
$full = $admin('full', ['Iter_Admin::root']);
$noCost = $admin('nocost', ['Iter_Report::sales', 'Iter_Catalog::product']);
$noReport = $admin('noreport', ['Iter_Admin::dashboard']);
$_SERVER['REQUEST_METHOD'] = 'GET';
$as = static function (?int $userId): void { // Auth keeps the user of the request
    $_SESSION = $userId === null ? [] : ['iter_admin_user_id' => $userId, 'iter_admin_seen' => time()];
    (new ReflectionProperty(Auth::class, 'user'))->setValue(null, null);
};

$order = $pdo->prepare("INSERT INTO sales_order (increment_id, website_id, email, firstname, lastname, taxvat, status, subtotal, discount_amount, shipping_amount, grand_total,
    payment_method, payment_label, created_at, updated_at, cancel_reason) VALUES (:n, :w, 'rel@selftest.local', 'Rel', 'Teste', '52998224725', :status, :subtotal, :discount, :shipping, :total,
    'pix', 'Pix', :at, :at, :reason) RETURNING order_id");
$item = $pdo->prepare('INSERT INTO sales_order_item (order_id, product_id, sku, name, quantity, price, row_total, discount_amount, cost) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
$place = static function (string $number, string $status, string $at, array $totals, array $items, ?string $reason = null) use ($order, $item, $websiteId, $pdo): int {
    $order->execute(['n' => $number, 'w' => $websiteId, 'status' => $status, 'at' => $at, 'reason' => $reason] + $totals);
    $id = (int) $order->fetchColumn();
    foreach ($items as [$productId, $qty, $price, $discount, $cost]) {
        $item->execute([$id, $productId, 'RPT-' . $productId, 'Produto ' . $productId, $qty, $price, $qty * $price, $discount, $cost]);
    }
    $pdo->prepare('INSERT INTO sales_order_status_history (order_id, status, created_at) VALUES (?, ?, ?)')->execute([$id, $status, $at]);

    return $id;
};
// A: paid, a 10% discount on each item; Y has no cost. B: complete. C: waiting. D: canceled. Neither counts in the totals.
$place('RPTST-01', 'processing', '2001-03-10 10:00:00', ['subtotal' => 200, 'discount' => 20, 'shipping' => 15, 'total' => 195], [[$x, 2, 50, 10, 30], [$y, 1, 100, 10, null]]);
$place('RPTST-02', 'complete', '2001-03-12 15:00:00', ['subtotal' => 50, 'discount' => 0, 'shipping' => 10, 'total' => 60], [[$x, 1, 50, 0, 30]]);
$waiting = $place('RPTST-03', 'pending_payment', '2001-03-12 16:00:00', ['subtotal' => 80, 'discount' => 0, 'shipping' => 0, 'total' => 80], [[$y, 1, 80, 0, null]]);
$place('RPTST-04', 'canceled', '2001-03-14 09:00:00', ['subtotal' => 40, 'discount' => 0, 'shipping' => 0, 'total' => 40], [[$x, 1, 40, 0, 30]], 'unpaid');

$in = ['preset' => '', 'from' => '2001-03-09', 'to' => '2001-03-15', 'website_id' => (string) $websiteId];
[$f] = ReportService::filters($v1, $in + ['group_by' => 'day'], true);
$t = $service->totals($v1, $f);

// --- V1: the totals and the margin ------------------------------------------------------
check([2, 4.0, 250.0, 20.0, 25.0, 255.0, 127.5, 1, 1] === [(int) $t['orders'], (float) $t['items'], (float) $t['subtotal'], (float) $t['discount'], (float) $t['shipping'], (float) $t['total'], $t['ticket'], (int) $t['pending'], (int) $t['canceled']],
    'V1: só os pedidos pagos somam (pedidos, itens, subtotal, descontos, frete, total, ticket); aguardando e cancelado à parte', $t);
check([230.0, 140.0, 90.0, 50.0] === [(float) $t['net'], (float) $t['net_cost'], (float) $t['cost'], $t['margin']] && abs($t['margin_pct'] - 50 / 140) < 1e-9,
    'V1: margem = itens com custo já com o desconto (140) − custo congelado no pedido (90); o item sem custo fica fora', $t);
check(str_contains($v1->note($t, $f), 'R$ 90,00 (39,1% do faturamento) foram de itens sem custo'), 'V1: a nota diz quanto do faturamento ficou sem custo');
$rows = $service->rows($v1, $f, 'period', 'asc')['rows'];
check(count($rows) === 7 && $rows[0]['period'] === '09/03/2001' && (int) $rows[0]['orders'] === 0 && (float) $rows[1]['total'] === 195.0, 'V1: um dia por linha, os dias sem pedido com zero', array_column($rows, 'total', 'period'));
[$byWeek] = ReportService::filters($v1, $in + ['group_by' => 'week'], true);
check((float) $service->totals($v1, $byWeek)['total'] === 255.0, 'V1 por semana: o mesmo total');

// --- V2, V7, O1 ----------------------------------------------------------------------------------------------
$v2 = ReportService::get('v2');
[$f2] = ReportService::filters($v2, $in + ["parent" => "1"], true); // summed on the product: the catalog SKU
$byProduct = array_column($service->rows($v2, $f2, 'revenue', 'desc')['rows'], null, 'sku');
$px = $byProduct[$products[$x]] ?? [];
check([3.0, 140.0, 90.0, 50.0] === [(float) ($px['qty'] ?? 0), (float) ($px['revenue'] ?? 0), (float) ($px['cost'] ?? 0), (float) ($px['margin'] ?? 0)], 'V2: produto com custo (3 un., R$ 140 já com desconto, custo 90, margem 50)', $px);
check(array_key_exists('margin', $byProduct[$products[$y]] ?? []) && $byProduct[$products[$y]]['margin'] === null, 'V2: produto sem custo fica sem margem', $byProduct[$products[$y]] ?? null);
$v7 = ReportService::get('v7');
[$f7] = ReportService::filters($v7, $in, true);
$t7 = $service->totals($v7, $f7);
check([1, 40.0] === [(int) $t7['orders'], (float) $t7['total']], 'V7: o cancelado do período, com o valor', $t7);
$o1 = ReportService::get('o1');
[$fo] = ReportService::filters($o1, ['website_id' => (string) $websiteId], true);
check(in_array($waiting, array_map('intval', array_column($service->rows($o1, $fo, 'age', 'desc')['rows'], 'order_id')), true), 'O1: o pedido aguardando pagamento há dias está na lista');

// --- the cost: who may not see it ----------------------------------------------------------------------------
[$hidden] = ReportService::filters($v1, $in + ['group_by' => 'day'], false);
check(!array_intersect(['margin', 'margin_pct'], array_keys(ReportService::columns($v1, $hidden))) && !array_intersect(['cost', 'margin'], array_keys(ReportService::columns($v2, ReportService::filters($v2, $in, false)[0]))),
    'sem Iter_Catalog::cost: as colunas de custo e margem saem de V1 e V2');
check(!str_contains(implode(' ', array_column($v1->tiles($service->totals($v1, $hidden), $hidden, static fn ($o) => $service->totals($v1, $o)), 'label')), 'Margem') && !str_contains($v1->note($t, $hidden), 'Margem'),
    'sem Iter_Catalog::cost: nem o indicador nem a nota falam de margem');
$schema = Iter\Core\GraphQl\Schema::build();
$codes = static function () use ($schema, $x): array {
    $r = GraphQL\GraphQL::executeQuery($schema, '{ product(id: "' . $x . '") { attributes { code } } }')->toArray();
    return array_column($r['data']['product']['attributes'] ?? [], 'code');
};
$as(null);
$public = $codes();
$as($noCost);
$withoutPermission = $codes();
$as($full);
check(!in_array('cost', $public, true) && !in_array('cost', $withoutPermission, true) && in_array('cost', $codes(), true) && $public !== [],
    'API: o custo não sai para o visitante nem para o admin sem Iter_Catalog::cost; sai para quem tem', [$public, $withoutPermission]);

// --- the files -----------------------------------------------------------------------------------------------
$file = static function (string $format, array $f) use ($v1, $tmp): string {
    $export = ReportView::export($v1, $f, 'gerado por selftest');
    $path = $tmp . 'v1.' . $format;
    $export->write($format, $path);
    return $path;
};
$csv = (string) file_get_contents($file('csv', $f));
$lines = explode("\n", $csv);
check(str_starts_with($csv, "\xEF\xBB\xBF") && str_contains($lines[1], 'Dia;Pedidos;Itens;Subtotal') && str_contains($csv, '10/03/2001;1;3;200,00;20,00;15,00;195,00;') && str_contains($csv, 'Total;2;4;250,00;20,00;25,00;255,00;50,00;35,7'),
    'CSV: BOM, ";", vírgula decimal, linha de total com a margem', array_slice($lines, 0, 4));
check(!str_contains((string) file_get_contents($file('csv', $hidden)), 'Margem'), 'CSV sem Iter_Catalog::cost: sem a margem');
$zip = new ZipArchive();
$zip->open($file('xlsx', $f));
$sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
$zip->close();
check(preg_match('#<c r="G6"[^>]*><v>195</v></c>#', $sheet) === 1 && str_contains($sheet, '<f>SUM(G5:G11)</f>'), 'XLSX: o valor como número e o total como fórmula (SOMA)', substr($sheet, 0, 300));
$pdf = (string) file_get_contents($file('pdf', $f));
check(str_starts_with($pdf, '%PDF-') && preg_match('#/Type\s*/Page\b#', $pdf) === 1, 'PDF: um documento com página');
if (trim((string) shell_exec('command -v pdftotext'))) {
    check(str_contains((string) shell_exec('pdftotext ' . escapeshellarg($tmp . 'v1.pdf') . ' - 2>/dev/null'), 'R$ 255,00'), 'PDF: traz o total');
}
$many = new Export($v1, ReportService::columns($v1, $f), array_fill(0, Export::PDF_MAX_ROWS + 1, []), [], [], '', '', '');
try {
    $many->write('pdf', $tmp . 'grande.pdf');
    check(false, 'PDF acima do limite de linhas é recusado');
} catch (RuntimeException $e) {
    check(str_contains($e->getMessage(), 'Use o Excel'), 'PDF acima de ' . Export::PDF_MAX_ROWS . ' linhas é recusado, indicando o Excel');
}

// --- the automatic e-mail (Configurações → Relatórios) ------------------------------------------------------
$config = static function (array $values) use ($pdo): void {
    $save = $pdo->prepare("INSERT INTO core_config_data (scope, scope_id, path, value) VALUES ('default', 0, ?, ?) ON CONFLICT (scope, scope_id, path) DO UPDATE SET value = EXCLUDED.value");
    foreach ($values as $path => $value) {
        $save->execute([$path, $value]);
    }
    (new ReflectionProperty(Iter\Core\Config::class, 'values'))->setValue(null, null); // read again
};
$mail = new Iter\Report\Model\ReportMail();
$config(['report/email/enabled' => '0']);
check($mail->run(strtotime('2001-03-19')) === null, 'e-mail: desligado, não envia');
$config(['report/email/enabled' => '1', 'report/email/frequency' => 'weekly', 'report/email/reports' => 'sales', 'report/email/format' => 'csv', 'report/email/recipients' => "relatorios@selftest.local\nnao-e-email"]);
check($mail->run(strtotime('2001-03-20')) === null, 'e-mail semanal: numa terça, não envia');
$sent = $mail->run(strtotime('2001-03-19')); // a Monday: the week of 12 to 18/03, with order B (R$ 60) and not A (10/03)
$queued = $pdo->query('SELECT * FROM mail_queue WHERE message_id = ' . (int) ($sent['message_id'] ?? 0))->fetch(PDO::FETCH_ASSOC) ?: [];
$attachments = json_decode($queued['attachments'] ?? '[]', true);
$csvs = array_map(static fn ($a) => (string) file_get_contents($a['path']), $attachments);
array_map(static fn ($a) => @unlink($a['path']) && @rmdir(dirname($a['path'])), $attachments); // the rollback doesn't take the copied files
check([$sent['from'] ?? null, $sent['to'] ?? null, $queued['to_email'] ?? null, array_column($attachments, 'name')] === ['2001-03-12', '2001-03-18', 'relatorios@selftest.local', ['vendas-por-periodo_2001-03-12_a_2001-03-18.csv', 'vendas-por-produto_2001-03-12_a_2001-03-18.csv']],
    'e-mail semanal: na segunda, a semana anterior, V1 e V2 anexados, só os destinatários válidos', [$sent, $queued['to_email'] ?? null, $attachments]);
check(str_contains((string) $queued['body_html'], 'R$ 60,00') && str_contains((string) $queued['subject'], 'de 12/03/2001 a 18/03/2001') && !str_contains(implode('', $csvs), 'Margem') && !str_contains(implode('', $csvs), 'Custo'),
    'e-mail: o faturamento da semana no texto; os anexos sem custo nem margem');
$config(['report/email/frequency' => 'monthly']);
check($mail->run(strtotime('2001-03-19')) === null && ($mail->run(strtotime('2001-04-01'))['from'] ?? null) === '2001-03-01', 'e-mail mensal: só no dia 1º, com o mês anterior');
foreach ($pdo->query("SELECT attachments FROM mail_queue WHERE to_email = 'relatorios@selftest.local'")->fetchAll(PDO::FETCH_COLUMN) as $json) {
    array_map(static fn ($a) => @unlink($a['path']) && @rmdir(dirname($a['path'])), json_decode($json, true));
}

// --- delivery 2: stock and catalog ---------------------------------------------------------------------------
// Products RPTST-A (in stock, with everything filled in), RPTST-B (nothing filled in, no stock) and RPTST-C
// (inactive, with stock), a source of their own, the reservations of an order, a cancellation and a shipment,
// a manual change in the operation log, a paid order in March and one two days ago.
$setId = (int) $pdo->query('SELECT attribute_set_id FROM catalog_product WHERE product_id = ' . (int) $x)->fetchColumn();
$newProduct = $pdo->prepare('INSERT INTO catalog_product (attribute_set_id, sku, name, is_active) VALUES (?, ?, ?, ?) RETURNING product_id');
$sku = [];
foreach (['A' => 1, 'B' => 1, 'C' => 0] as $letter => $active) {
    $newProduct->execute([$setId, 'RPTST-' . $letter, 'Relatório teste ' . $letter, $active]);
    $sku[$letter] = (int) $newProduct->fetchColumn();
    $pdo->prepare('INSERT INTO catalog_product_website (product_id, website_id) VALUES (?, ?)')->execute([$sku[$letter], $websiteId]);
}
$attribute = static fn (string $where) => (int) $pdo->query("SELECT attribute_id FROM eav_attribute WHERE {$where} AND entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE code = 'product') ORDER BY attribute_id LIMIT 1")->fetchColumn();
$value = static fn (string $storage, int $attributeId, int $productId, string $v) => $pdo->prepare("INSERT INTO eav_value_{$storage} (attribute_id, entity_id, value) VALUES (?, ?, ?)")->execute([$attributeId, $productId, $v]);
$value('decimal', $attribute("code = 'price'"), $sku['A'], '20');
$value('decimal', $costAttribute, $sku['A'], '4');
$value('decimal', $attribute("code = 'weight'"), $sku['A'], '0.5');
$value('text', $attribute("code = 'description'"), $sku['A'], 'Descrição');
$value('varchar', $attribute("input_type = 'image'"), $sku['A'], 'rptst.jpg');
$value('decimal', $attribute("code = 'price'"), $sku['C'], '15');
$source = (int) $pdo->query("INSERT INTO inventory_source (code, name, website_id) VALUES ('rptst_selftest', 'RPT Origem', {$websiteId}) RETURNING source_id")->fetchColumn();
$pdo->exec("INSERT INTO inventory_source_item (source_id, product_id, quantity) VALUES ({$source}, {$sku['A']}, 10), ({$source}, {$sku['C']}, 2)");
$reserve = $pdo->prepare('INSERT INTO inventory_reservation (product_id, website_id, quantity, reason, reference, created_at) VALUES (?, ?, ?, ?, ?, ?)');
$reserve->execute([$sku['A'], $websiteId, -3, 'order', 'RPTST-05', '2001-03-10 10:00:00']);
$reserve->execute([$sku['A'], $websiteId, 1, 'canceled', 'RPTST-05', '2001-03-11 10:00:00']);
$reserve->execute([$sku['A'], $websiteId, 1, 'shipped', 'RPTST-05', '2001-03-12 10:00:00']);
$pdo->prepare("INSERT INTO log_operation (created_at, admin_name, source, action, entity_type, entity_id, entity_label, changes) VALUES ('2001-03-11 12:00:00', 'Rel Teste', 'admin', 'update', 'product', ?, 'RPTST-A', ?)")
    ->execute([$sku['A'], json_encode(['Estoque — RPT Origem' => ['5', '10'], 'Nome' => ['x', 'y']], JSON_UNESCAPED_UNICODE)]);
$place('RPTST-05', 'processing', '2001-03-13 10:00:00', ['subtotal' => 30, 'discount' => 0, 'shipping' => 0, 'total' => 30], [[$sku['A'], 1, 20, 0, 4], [$sku['B'], 1, 10, 0, null]]);
$recent = $place('RPTST-06', 'processing', date('Y-m-d H:i:s', strtotime('-2 days')), ['subtotal' => 60, 'discount' => 0, 'shipping' => 0, 'total' => 60], [[$sku['A'], 3, 20, 0, 4]]);
$pdo->exec("UPDATE sales_order_item SET sku = p.sku FROM catalog_product p WHERE p.product_id = sales_order_item.product_id AND p.sku LIKE 'RPTST-%'");
$report = static function (string $code, array $in, bool $cost = true) use ($service, $websiteId): array {
    $r = ReportService::get($code);
    [$f] = ReportService::filters($r, $in + ['preset' => 'month', 'website_id' => (string) $websiteId, 'q' => 'RPTST-'], $cost);
    [$sort, $dir] = $r->sort();

    return [array_column($service->rows($r, $f, $sort, $dir)['rows'], null, 'sku'), $service->totals($r, $f), $r, $f];
};

[$rows, $t] = $report('e1', ['only' => 'all']);
check(['10', '1', '9', '40', '200', 'RPT Origem: 10', 'Ativo'] === [rtrim(rtrim($rows['RPTST-A']['physical'], '0'), '.'), rtrim(rtrim($rows['RPTST-A']['reserved'], '0'), '.'), rtrim(rtrim($rows['RPTST-A']['salable'], '0'), '.'), (string) (float) $rows['RPTST-A']['value_cost'], (string) (float) $rows['RPTST-A']['value_price'], $rows['RPTST-A']['sources'], $rows['RPTST-A']['situation']],
    'E1: físico 10, reservado 1 (pedido −3, cancelado +1, enviado +1), vendável 9, valor pelo custo e pelo preço, a origem', $rows['RPTST-A'] ?? null);
check(count($rows) === 3 && $rows['RPTST-C']['situation'] === 'Inativo' && (float) $rows['RPTST-B']['salable'] === 0.0 && (int) $t['with_stock'] === 2 && (int) $t['no_cost'] === 1,
    'E1: todos os produtos, o inativo marcado; 2 com estoque, 1 deles sem custo', [$rows, $t]);
check(count($report('e1', ['only' => 'stock'])[0]) === 2, 'E1: "só com estoque físico" deixa o produto sem estoque de fora');
[$rows, $t] = $report('e2', ['max' => '3']);
check(array_keys($rows) === ['RPTST-B'] && $rows['RPTST-B']['situation'] === 'Esgotado' && (int) $t['out'] === 1 && (int) $t['low'] === 0,
    'E2: só o ativo sem estoque (o inativo fica de fora, A tem 9)', $rows);
[$rows, $t] = $report('e2', ['max' => '10']);
check(['Baixo', 3.0, 90] === [$rows['RPTST-A']['situation'] ?? null, (float) ($rows['RPTST-A']['sold'] ?? 0), (int) ($rows['RPTST-A']['lasts'] ?? 0)] && (int) $t['low'] === 1,
    'E2 até 10: A baixo, 3 vendidos em 30 dias (o pedido de março não conta), dura cerca de 90 dias (9 ÷ 0,1 por dia)', $rows['RPTST-A'] ?? null);
check(array_keys($report('e2', ['max' => '10', 'show' => 'selling'])[0]) === ['RPTST-A'] && array_keys($report('e2', ['max' => '10', 'show' => 'out'])[0]) === ['RPTST-B'], 'E2: "só os que venderam" e "só esgotados"');
check($report('e3', ['preset' => '', 'from' => '2001-03-01', 'to' => '2001-03-31'])[0] === [], 'E3: A vendeu em março, B não tem estoque, C está inativo: ninguém parado');
[$rows] = $report('e3', ['preset' => '', 'from' => '2001-04-01', 'to' => '2001-04-30']);
check(array_keys($rows) === ['RPTST-A'] && substr((string) $rows['RPTST-A']['last_sale'], 0, 10) === date('Y-m-d', strtotime('-2 days')) && (int) $rows['RPTST-A']['days'] === 2,
    'E3 em abril de 2001: A parado, com a última venda de dois dias atrás', $rows);
[$rows, $t, $e4, $f4] = $report('e4', ['preset' => '', 'from' => '2001-03-09', 'to' => '2001-03-15']);
$moves = array_map(static fn ($r) => [$r['kind'], (float) $r['quantity'], $r['source'], $r['username'], $r['order_id'] !== null], $service->rows($e4, $f4, 'created_at', 'asc')['rows']);
check([['order', -3.0, null, null, true], ['canceled', 1.0, null, null, true], ['adjust', 5.0, 'RPT Origem', 'Rel Teste', false], ['shipped', -1.0, null, null, true]] === $moves
    && [3.0, 1.0, 1.0, 5.0, 0.0] === [(float) $t['reserved'], (float) $t['returned'], (float) $t['shipped'], (float) $t['adjusted_in'], (float) $t['adjusted_out']],
    'E4: reserva −3, devolução +1, ajuste manual +5 (do log, com a origem e o usuário; o "Nome" fica de fora), baixa −1, com o pedido', [$moves, $t]);
check(count($report('e4', ['preset' => '', 'from' => '2001-03-09', 'to' => '2001-03-15', 'kind' => 'adjust'])[0]) === 1, 'E4: filtro por movimento');
[$rows, $t] = $report('e5', ['preset' => '', 'from' => '2001-03-09', 'to' => '2001-03-15', 'q' => '', 'parent' => '0']);
$classes = array_map(static fn ($r) => [$r['class'], (int) $r['rank']], array_filter($rows, static fn ($r) => in_array($r['sku'], ['RPT-' . $x, 'RPT-' . $y, 'RPTST-A', 'RPTST-B'], true)));
check(['RPT-' . $x => ['A', 1], 'RPT-' . $y => ['A', 2], 'RPTST-A' => ['B', 3], 'RPTST-B' => ['C', 4]] === $classes && (int) $t['a'] === 2 && (float) $t['a_revenue'] === 230.0,
    'E5: 140 e 90 somam até 88% (A, o segundo entra porque antes dele havia 54%), 20 vai a 96% (B), 10 é C', [$classes, $t]);
check(array_keys($report('e5', ['preset' => '', 'from' => '2001-03-09', 'to' => '2001-03-15', 'q' => '', 'parent' => '0', 'class' => 'C'])[0]) === ['RPTST-B'], 'E5: filtro por classe');
[$rows, $t] = $report('c1', []);
check(['sem imagem, sem descrição, sem peso, sem preço, sem custo', 'inativo com estoque'] === [$rows['RPTST-B']['problems'] ?? null, $rows['RPTST-C']['problems'] ?? null] && !isset($rows['RPTST-A']),
    'C1: B sem nada, C inativo com estoque, A completo fica de fora', $rows);
[$rows] = $report('c1', [], false);
check(($rows['RPTST-B']['problems'] ?? null) === 'sem imagem, sem descrição, sem peso, sem preço' && array_keys($report('c1', ['problem' => 'inactive_stock'])[0]) === ['RPTST-C'],
    'C1: sem a permissão do custo, "sem custo" não aparece; filtro por problema');

// stock permission alone: opens e1 and not v1; the menu and the list show only Estoque e catálogo
$stockOnly = $admin('stockonly', ['Iter_Report::stock']);
$as($stockOnly);
$menu = array_column(Iter\Admin\MenuRegistry::all(), null, 'label')['Relatórios'] ?? ['children' => []];
check(Auth::authorize(Iter\Report\Controllers\Report::class, '/admin/report/view') && ReportService::allowed(ReportService::get('e1')) && !ReportService::allowed(ReportService::get('v1'))
    && array_column($menu['children'], 'label') === ['Todos os relatórios', 'Estoque e catálogo'] && array_keys((new Iter\Report\View\ReportHome())->getData()['groups']) === ['stock'],
    'só Iter_Report::stock: abre os relatórios de estoque e não os de vendas; no menu e na lista, só Estoque e catálogo', $menu);
$as($noCost);
check(!ReportService::allowed(ReportService::get('e1')), 'só Iter_Report::sales: não abre os de estoque');

// --- delivery 3: customers, channel and region -----------------------------------------------------------------
// Paid in 09–15/03/2001: RPTST-01 (R$ 195, now of customer K1, Rio de Janeiro), RPTST-02 (R$ 60, placed by an admin
// in Atendimento, "sp"/"são paulo") and RPTST-05 (R$ 30, no address). RPTST-06 (two days ago) is the guest's too.
$customer = $pdo->prepare('INSERT INTO customer_entity (website_id, attribute_set_id, group_id, created_at) SELECT ?, attribute_set_id, group_id, ? FROM customer_entity ORDER BY customer_id LIMIT 1 RETURNING customer_id');
$customer->execute([$websiteId, '2001-03-10 08:00:00']);
$k1 = (int) $customer->fetchColumn();
$customer->execute([$websiteId, '2001-03-11 08:00:00']);
$k2 = (int) $customer->fetchColumn();
$type = $pdo->query("SELECT attribute_id, storage_type FROM eav_attribute WHERE code = 'customer_type' AND entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE code = 'customer')")->fetch(PDO::FETCH_ASSOC);
$pdo->prepare("INSERT INTO eav_value_{$type['storage_type']} (attribute_id, entity_id, value) VALUES (?, ?, 'pj')")->execute([$type['attribute_id'], $k2]);
$pdo->exec("UPDATE sales_order SET customer_id = {$k1} WHERE increment_id = 'RPTST-01'");
$pdo->exec("UPDATE sales_order SET sales_channel = 'Atendimento', admin_user_id = {$full} WHERE increment_id = 'RPTST-02'");
$address = $pdo->prepare("INSERT INTO sales_order_address (order_id, type, postcode, street, number, district, city, region, recipient, telephone) SELECT order_id, 'shipping', '20000000', 'Rua', '1', 'Centro', ?, ?, 'Rel Teste', ? FROM sales_order WHERE increment_id = ?");
$address->execute(['Rio de Janeiro', 'RJ', '(21) 3333-0000', 'RPTST-01']);
$address->execute(['são paulo', 'sp', '(11) 4444-0000', 'RPTST-02']);
$march = ['preset' => '', 'from' => '2001-03-09', 'to' => '2001-03-15', 'q' => ''];

[$rows, $t] = $report('v5', $march);
$byChannel = array_map(static fn ($r) => [$r['channel'], $r['attendant'], (int) $r['orders'], (float) $r['total']], array_values($rows));
check([['App mobile', '—', 2, 225.0], ['Atendimento', 'report_selftest_full', 1, 60.0]] === $byChannel && (int) $t['by_admin'] === 1,
    'V5: os pedidos do cliente sem atendente; o do painel com o canal e o usuário que fez', $byChannel);
check(count($report('v5', $march + ['by' => 'channel'])[0]) === 2 && array_column($report('v5', $march + ['admin_user_id' => (string) $full])[0], 'orders') === [1], 'V5: só por canal; filtro por atendente');
[$rows, $t] = $report('v6', $march);
check(['RJ' => 195.0, 'SP' => 60.0, 'Sem endereço' => 30.0] === array_map(static fn ($r) => (float) $r['total'], array_column($rows, null, 'region')) && (float) $t['shipping'] === 25.0,
    'V6: por UF do endereço de entrega ("sp" vira SP); o pedido sem endereço à parte; o frete somado', $rows);
[$rows] = $report('v6', $march + ['by' => 'city', 'region' => 'SP']);
check(array_map(static fn ($r) => [$r['region'], $r['city']], array_values($rows)) === [['SP', 'São Paulo']], 'V6: por cidade, filtrando a UF');
$k1Rows = static fn (array $in) => array_values(array_filter($report('k1', $in + $march)[0], static fn ($r) => (int) $r['signups'] > 0));
check(array_map(static fn ($r) => [$r['period'], (int) $r['pf'], (int) $r['pj'], (int) $r['buyers']], $k1Rows(['group_by' => 'day'])) === [['10/03/2001', 1, 0, 1], ['11/03/2001', 0, 1, 0]],
    'K1 por dia: o cadastro de 10/03 (PF, já comprou) e o de 11/03 (PJ, não comprou)');
[, $t] = $report('k1', ['group_by' => 'month'] + $march);
check([2, 1, 0.5] === [(int) $t['signups'], (int) $t['buyers'], $t['buyers_pct']], 'K1: 2 novos, 1 comprou (50%)', $t);
[$rows, $t] = $report('k2', $march);
$byKey = array_column($rows, null, 'customer');
$guest = array_values(array_filter($rows, static fn ($r) => $r['kind'] === 'Visitante'))[0] ?? [];
check([1, 195.0, 'c' . $k1] === [(int) ($rows[array_key_first($rows)]['orders'] ?? 0), (float) ($rows[array_key_first($rows)]['total'] ?? 0), $rows[array_key_first($rows)]['key'] ?? null]
    && [2, 90.0, '2001-03-12', date('Y-m-d', strtotime('-2 days')), '(11) 4444-0000'] === [(int) $guest['orders'], (float) $guest['total'], substr((string) $guest['first_order'], 0, 10), substr((string) $guest['last_order'], 0, 10), $guest['telephone']]
    && [2, 1, 1] === [(int) $t['lines'], (int) $t['guests'], (int) $t['repeat']],
    'K2: o cliente cadastrado pela conta; o visitante pelo e-mail (2 pedidos no período, a última compra de dois dias atrás, o telefone do último pedido com endereço)', [$rows, $t]);
check(array_column($report('k2', $march + ['who' => 'guest'])[0], 'kind') === ['Visitante'] && $report('k2', ['q' => 'ninguem-com-este-nome'] + $march)[0] === [], 'K2: só visitantes; busca');
check(ReportView::link('/admin/customer/edit?id={customer_id}', $guest) === null && ReportView::link('/admin/customer/edit?id={customer_id}', ['customer_id' => $k1]) === '/admin/customer/edit?id=' . $k1,
    'K2: o visitante não tem link para o cadastro');

// the exports of customer reports go to the operation log (LGPD); the sales ones don't
$config([Iter\Log\Model\OperationLog::ENABLED => '1']);
$as($full);
$logged = static fn () => (int) $pdo->query("SELECT COUNT(*) FROM log_operation WHERE action = 'export' AND admin_user_id = {$full}")->fetchColumn();
[, , $k2Report, $k2Filters] = $report('k2', $march);
ReportService::logExport($k2Report, ReportView::export($k2Report, $k2Filters, 'teste'), 'xlsx');
$line = $pdo->query("SELECT entity_type, entity_label, changes FROM log_operation WHERE action = 'export' AND admin_user_id = {$full}")->fetch(PDO::FETCH_ASSOC) ?: [];
$changes = json_decode($line['changes'] ?? '{}', true);
check(['report', 'Melhores clientes', 'Excel', '2'] === [$line['entity_type'] ?? null, $line['entity_label'] ?? null, $changes['Formato'][1] ?? null, $changes['Linhas'][1] ?? null] && str_contains($changes['Filtros'][1] ?? '', 'Período: 09/03/2001 a 15/03/2001'),
    'exportar um relatório de clientes grava no log: quem, o relatório, o formato, os filtros e as linhas', $line);
ReportService::logExport($v1, ReportView::export($v1, $f, 'teste'), 'csv');
check($logged() === 1, 'exportar um relatório de vendas não grava no log');

$customersOnly = $admin('customersonly', ['Iter_Report::customers']);
$as($customersOnly);
check(Auth::authorize(Iter\Report\Controllers\Report::class, '/admin/report/view') && ReportService::allowed(ReportService::get('k2')) && !ReportService::allowed(ReportService::get('v6'))
    && array_keys((new Iter\Report\View\ReportHome())->getData()['groups']) === ['customers'], 'só Iter_Report::customers: abre os de clientes e não os de vendas');
$as($noCost);
check(!ReportService::allowed(ReportService::get('k1')) && ReportService::allowed(ReportService::get('v5')), 'só Iter_Report::sales: abre V5 e V6, não os de clientes');

// --- delivery 4: promotions, shipping, service times, shipments, receipts, Mercado Pago, margin ----------------
// The paid orders of 09–15/03/2001 above: RPTST-01 (R$ 195, frete 15), RPTST-02 (R$ 60, frete 10) and RPTST-05
// (R$ 30, frete 0); RPTST-04 (R$ 40) canceled, now after being paid.
$id = static fn (string $number) => (int) $pdo->query("SELECT order_id FROM sales_order WHERE increment_id = '{$number}'")->fetchColumn();
[$o1, $o2, $o4, $o5] = [$id('RPTST-01'), $id('RPTST-02'), $id('RPTST-04'), $id('RPTST-05')];
$discount = $pdo->prepare('INSERT INTO sales_order_discount (order_id, promotion_name, coupon_code, discount_amount) VALUES (?, ?, ?, ?)');
$discount->execute([$o1, 'Promo teste', null, 20]);
$discount->execute([$o1, 'Cupom teste', 'rptst5', 3]);
$discount->execute([$o5, 'Cupom teste', 'rptst5', 5]);
[$rows, $t] = $report('v8', $march);
$uses = array_map(static fn ($r) => [$r['coupon'], (int) $r['orders'], (float) $r['discount'], (float) $r['revenue']], array_values($rows));
check([['Automática', 1, 20.0, 195.0], ['RPTST5', 2, 8.0, 225.0]] === $uses && [2, 28.0, 225.0, 1, 60.0] === [(int) $t['orders'], (float) $t['discount'], (float) $t['revenue'], (int) $t['plain_orders'], (float) $t['plain_total']],
    'V8: a automática e o cupom (2 usos); no total o pedido com as duas conta uma vez; sem desconto, 1 pedido de R$ 60', [$uses, $t]);
check(array_column($report('v8', ['kind' => 'coupon'] + $march)[0], 'coupon') === ['RPTST5'], 'V8: só cupons');

$shipping = $pdo->prepare('UPDATE sales_order SET shipping_method = ?, shipping_label = ?, shipping_days = ? WHERE order_id = ?');
$shipping->execute(['tablerate', 'Entrega', 5, $o1]);
$shipping->execute(['pickup', 'Retirar na loja', null, $o2]);
$shipping->execute(['freeshipping', 'Frete grátis', 3, $o5]);
[$rows, $t] = $report('v9', $march);
check(count($rows) === 3 && [25.0, 1, 1, 4.0] === [(float) $t['shipping'], (int) $t['free'], (int) $t['pickup'], (float) $t['days']],
    'V9: uma linha por forma; frete 25, 1 grátis, 1 retirada, prazo médio de 4 dias (o pedido sem prazo fica fora da média)', [$rows, $t]);

$history = $pdo->prepare('INSERT INTO sales_order_status_history (order_id, status, created_at) VALUES (?, ?, ?)');
$history->execute([$o1, 'shipped', '2001-03-11 10:00:00']);
$history->execute([$o1, 'complete', '2001-03-13 10:00:00']);
$history->execute([$o2, 'processing', '2001-03-12 17:00:00']);
$history->execute([$o2, 'shipped', '2001-03-12 21:00:00']);
$pdo->exec("UPDATE sales_order_status_history SET created_at = '2001-03-14 21:00:00' WHERE order_id = {$o2} AND status = 'complete'");
$history->execute([$o5, 'processing', '2001-03-13 10:00:00']);
[$rows, $t] = $report('o2', ['group_by' => 'week'] + $march);
$times = array_map(static fn ($v) => $v === null ? null : round((float) $v, 3), [$t['pay_avg'], $t['pay_max'], $t['ship_avg'], $t['ship_max'], $t['done_avg'], $t['done_max']]);
check([3, 0.667, 2.0, 14.0, 24.0, 48.0, 48.0] === [(int) $t['orders'], ...$times] && array_column(array_values($rows), 'period') === ['Semana de 05/03/2001', 'Semana de 12/03/2001'],
    'O2: pedido → pagamento 0,67 h (máx. 2), pagamento → envio 14 h (máx. 24), envio → conclusão 48 h; por semana do pedido', [$t, array_column($rows, 'period')]);
check(Format::show('age', 0.5) === '30 min' && Format::show('age', 30) === '30 h' && Format::show('age', 72) === '3 dias', 'tempo: minutos abaixo de 1 h, horas até 48 h, depois dias');

$shipment = $pdo->prepare('INSERT INTO sales_shipment (order_id, source_name, carrier, tracking_code, created_at) VALUES (?, ?, ?, ?, ?)');
$shipment->execute([$o1, 'RPT Origem', 'Correios', 'BR123', '2001-03-11 10:00:00']);
$shipment->execute([$o2, 'RPT Origem', 'Correios', null, '2001-03-12 21:00:00']);
[$rows, $t] = $report('o3', $march);
$row = array_values($rows)[0] ?? [];
check(count($rows) === 1 && ['Correios', 'RPT Origem', 2, 1, 14.0] === [$row['carrier'], $row['source'], (int) $row['shipments'], (int) $row['missing'], round((float) $row['ship_hours'], 3)],
    'O3: Correios / RPT Origem, 2 envios, 1 sem rastreio, 14 h em média do pagamento ao envio', $rows);
[$rows, , $o3, $f3] = $report('o3', ['view' => 'list'] + $march);
$listed = (new ReportService())->rows($o3, $f3, 'shipments', 'desc')['rows'];
check(array_column($listed, 'number') === ['RPTST-02', 'RPTST-01'] && $listed[0]['tracking_code'] === null && count($report('o3', ['view' => 'list', 'tracking' => 'missing'] + $march)[0]) === 1,
    'O3 lista: o envio mais novo primeiro, com o pedido; só sem rastreio', $listed);

$mercadopago = \isModuleEnabled('iter_mercadopago');
$history->execute([$o4, 'processing', '2001-03-14 08:00:00']);
if ($mercadopago) {
    $charge = $pdo->prepare("INSERT INTO mercadopago_payment (order_id, method, external_id, status, status_detail, amount, card_brand, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $charge->execute([$o1, 'mercadopago_credit', 'RPTST-MP1', 'rejected', 'cc_rejected_insufficient_amount', 195, 'visa', '2001-03-10 09:58:00']);
    $charge->execute([$o1, 'mercadopago_credit', 'RPTST-MP2', 'approved', 'accredited', 195, 'master', '2001-03-10 10:00:00']);
    $charge->execute([$o4, 'mercadopago_pix', 'RPTST-MP3', 'refunded', 'refunded', 25, null, '2001-03-14 08:00:00']);
    $charge->execute([$o4, 'mercadopago_pix', 'RPTST-MP4', 'approved', 'accredited', 10, null, '2001-03-14 08:05:00']);
}
[$rows, $t] = $report('f1', $march);
check([4, 325.0, 1, $mercadopago ? 25.0 : 0.0, $mercadopago ? 15.0 : 40.0, 285.0] === [(int) $t['orders'], (float) $t['gross'], (int) $t['canceled'], (float) $t['refunded_mp'], (float) $t['returned'], (float) $t['net']],
    'F1: pela data do pagamento, 4 pagamentos (o cancelado depois de pago entra no bruto), estornado no Mercado Pago e o resto devolvido de outro jeito, líquido 285', $t);
if ($mercadopago) {
    [$rows, $t] = $report('f2', $march);
    $lines = array_map(static fn ($r) => [$r['situation'], $r['reason'], $r['brand'], (float) $r['amount']], array_values($rows));
    usort($lines, static fn ($a, $b) => [$a[0], $a[2]] <=> [$b[0], $b[2]]);
    check([['Aprovado', 'Pagamento aprovado', 'Mastercard', 195.0], ['Aprovado', 'Pagamento aprovado', 'Pix', 10.0], ['Estornado', 'Estornado', 'Pix', 25.0], ['Recusado', 'Saldo ou limite insuficiente', 'Visa', 195.0]] === $lines
        && [4, 2, 205.0, 1, 25.0, 1, 10.0] === [(int) $t['charges'], (int) $t['approved'], (float) $t['approved_amount'], (int) $t['rejected'], (float) $t['refunded_amount'], (int) $t['attention'], (float) $t['attention_amount']],
        'F2: por situação, motivo (em português) e bandeira (Pix para o Pix); aprovado, recusa, estorno e 1 cobrança a estornar', [$lines, $t]);
    [$rows, $t] = $report('f2', ['view' => 'attention'] + $march);
    check(array_column(array_values($rows), 'number') === ['RPTST-04'] && (int) $t['charges'] === 4, 'F2 "a estornar": a cobrança aprovada do pedido cancelado; os indicadores continuam contando todas');
} else {
    echo "PULADO: F2 (módulo Mercado Pago desligado)\n";
}

[$rows, $t] = $report('f3', ['by' => 'product', 'parent' => '0'] + $march);
check([260.0, 160.0, 94.0, 66.0, 100.0] === [(float) $t['revenue'], (float) $t['net_cost'], (float) $t['cost'], (float) $t['margin'], (float) $t['no_cost']] && (float) ($rows['RPT-' . $x]['margin'] ?? 0) === 50.0,
    'F3 por produto: faturamento 260, com custo 160, custo 94, margem 66, sem custo 100', [$t, $rows['RPT-' . $x] ?? null]);
[, $byCategory] = $report('f3', ['by' => 'category'] + $march);
check([260.0, 66.0] === [(float) $byCategory['revenue'], (float) $byCategory['margin']], 'F3 por categoria: os mesmos totais (o produto em duas categorias não soma em dobro)', $byCategory);

$financeNoCost = $admin('financenocost', ['Iter_Report::finance']);
$as($financeNoCost);
check(ReportService::allowed(ReportService::get('f1')) && !ReportService::allowed(ReportService::get('f3')) && !ReportService::allowed(ReportService::get('v8')),
    'só Iter_Report::finance: abre Recebimentos; Margem pede também a permissão do custo; os de vendas não');
$as($full);
[, , $f1Report, $f1Filters] = $report('f1', $march);
ReportService::logExport($f1Report, ReportView::export($f1Report, $f1Filters, 'teste'), 'pdf');
check($logged() === 2 && $pdo->query("SELECT entity_label FROM log_operation WHERE action = 'export' AND admin_user_id = {$full} ORDER BY log_id DESC LIMIT 1")->fetchColumn() === 'Recebimentos',
    'exportar um relatório financeiro também grava no log');

// --- delivery 5: lapsed customers, abandoned carts, guest buyers ------------------------------------------------
// K1 last paid in March 2001 (RPTST-01); K2 never bought. The guest orders RPTST-02 and RPTST-05 (rel@selftest.local) are paid in the period.
$mine = static fn (array $rows, string $key, array $ids) => array_values(array_filter($rows, static fn ($r) => in_array((int) $r[$key], $ids, true)));
$rows = $mine($report('k3', ['q' => ''])[0], 'customer_id', [$k1, $k2]);
check([[$k1, 1, 195.0, '2001-03-10']] === array_map(static fn ($r) => [(int) $r['customer_id'], (int) $r['orders'], (float) $r['total'], substr((string) $r['last_order'], 0, 10)], $rows),
    'K3: o cliente com a última compra paga há mais de 90 dias; quem nunca comprou fica de fora', $rows);
$rows = $mine($report('k3', ['q' => '', 'who' => 'all'])[0], 'customer_id', [$k1, $k2]);
check(count($rows) === 2 && (int) array_column($rows, 'never', 'customer_id')[$k2] === 1 && $mine($report('k3', ['q' => '', 'days' => '100000'])[0], 'customer_id', [$k1]) === [],
    'K3: "também quem nunca comprou" traz o cadastro sem pedido; com mais dias, o cliente sai', $rows);

$promo = $attribute("code = 'price_promo'");
$value('decimal', $promo, $sku['C'], '12');
$cart = $pdo->prepare('INSERT INTO sales_quote (token_hash, customer_id, website_id, email, updated_at) VALUES (?, ?, ?, ?, ?) RETURNING quote_id');
$carts = [];
foreach ([[null, $k2, null, '-3 days', [$sku['A'] => 2]], [bin2hex(random_bytes(32)), null, 'carrinho@selftest.local', '-30 hours', [$sku['C'] => 1]],
    [bin2hex(random_bytes(32)), null, null, '-1 hour', [$sku['A'] => 1]], [bin2hex(random_bytes(32)), null, null, '-3 days', []]] as [$token, $customerId, $email, $ago, $lines]) {
    $cart->execute([$token, $customerId, $websiteId, $email, date('Y-m-d H:i:s', strtotime($ago))]);
    $carts[] = $quoteId = (int) $cart->fetchColumn();
    foreach ($lines as $productId => $qty) {
        $pdo->prepare('INSERT INTO sales_quote_item (quote_id, product_id, quantity) VALUES (?, ?, ?)')->execute([$quoteId, $productId, $qty]);
    }
}
$k4 = static fn (array $in) => array_map(static fn ($r) => [(int) $r['quote_id'], (float) $r['value'], $r['email']], $mine($report('k4', $in)[0], 'quote_id', $carts));
check([[$carts[0], 40.0, null], [$carts[1], 12.0, 'carrinho@selftest.local']] === $k4([]),
    'K4: os carrinhos parados há mais de 24 h, pelo preço de hoje (o promocional no lugar do preço); o recente e o vazio ficam de fora', $k4([]));
check([[$carts[0], 40.0, null]] === $k4(['hours' => '48']) && [[$carts[1], 12.0, 'carrinho@selftest.local']] === $k4(['who' => 'email']) && [] === $k4(['who' => 'registered', 'hours' => '100']),
    'K4: mais horas; só com e-mail; só cadastrados');

$k5 = static fn (array $in = []) => $report('k5', $in + ['q' => 'rel@selftest'] + $march)[0];
$rows = $k5();
check([['rel@selftest.local', '52998224725', '(11) 4444-0000', 2, 90.0, 'Não']] === array_map(static fn ($r) => [$r['email'], $r['taxvat'], $r['telephone'], (int) $r['orders'], (float) $r['total'], $r['account']], $rows),
    'K5: as compras pagas sem cadastro no período, juntadas pelo e-mail, com o CPF e o telefone do último pedido', $rows);
$taxvat = $pdo->query("SELECT attribute_id, storage_type FROM eav_attribute WHERE code = 'taxvat' AND entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE code = 'customer')")->fetch(PDO::FETCH_ASSOC);
$pdo->prepare("INSERT INTO eav_value_{$taxvat['storage_type']} (attribute_id, entity_id, value) VALUES (?, ?, '52998224725')")->execute([$taxvat['attribute_id'], $k2]);
$rows = $k5(['who' => 'all']);
check($k5() === [] && [[$k2, 'Já tem']] === array_map(static fn ($r) => [(int) $r['customer_id'], $r['account']], $rows),
    'K5: com um cadastro de mesmo CPF, sai da lista "sem cadastro" e em "todos" aparece como "Já tem", com o link para a conta', $rows);

// the admin API: reports (what the token's admin may open) and report (the screen's lines, totals and indicators)
$schema = Iter\Core\GraphQl\Schema::build();
$gql = static fn (string $query) => GraphQL\GraphQL::executeQuery($schema, $query)->toArray();
$as($full);
$v1Api = static fn (string $more = '') => $gql('{ report(code: "v1", filters: [{key: "preset", value: ""}, {key: "from", value: "2001-03-09"}, {key: "to", value: "2001-03-15"}, {key: "website_id", value: "' . $websiteId . '"}, {key: "group_by", value: "day"}]' . $more . ') {
    applied { key value } errors columns { key type } rows total totals tiles { label value } note } }');
$api = $v1Api()['data']['report'] ?? [];
[$screen, $screenTotals] = $report('v1', ['group_by' => 'day', 'q' => ''] + $march);
$keys = array_column($api['columns'] ?? [], 'key');
$apiTotals = array_combine($keys, $api['totals'] ?? array_fill(0, count($keys), null));
check(count($api['rows'] ?? []) === 7 && $api['total'] === 7 && in_array('margin', $keys, true) && [(float) $apiTotals['total'], (float) $apiTotals['margin']] === [(float) $screenTotals['total'], (float) $screenTotals['margin']]
    && array_column($api['applied'], 'value', 'key')['from'] === '2001-03-09' && $api['tiles'] !== [] && $api['errors'] === [],
    'API report: V1 com as mesmas linhas, totais e margem da tela, os filtros usados e os indicadores', $api);
$page = $v1Api(', sort: "period", direction: DESC, page: 2, pageSize: 5')['data']['report'] ?? [];
check(count($page['rows']) === 2 && $page['total'] === 7 && $page['rows'][1][0] === ($api['rows'][0][0] ?? 'x'), 'API report: ordem, direção e página (a página 2 de 5 tem as 2 últimas)', $page['rows'] ?? null);
$logBefore = $logged();
$k5Api = $gql('{ report(code: "k5", filters: [{key: "who", value: "all"}, {key: "q", value: "rel@selftest"}, {key: "preset", value: ""}, {key: "from", value: "2001-03-09"}, {key: "to", value: "2001-03-15"}]) { rows total } }')['data']['report'] ?? [];
check($k5Api['total'] === 1 && $logged() === $logBefore + 1 && $pdo->query("SELECT changes->'Formato'->>1 FROM log_operation WHERE action = 'export' AND admin_user_id = {$full} ORDER BY log_id DESC LIMIT 1")->fetchColumn() === 'API',
    'API report: um relatório de clientes pela API fica no log de operações (formato API)', $k5Api);
$codes = static fn () => array_column($gql('{ reports { code filters { key type options { value } default } } }')['data']['reports'] ?? [], null, 'code');
$listed = $codes();
check(isset($listed['v1'], $listed['f3'], $listed['k4']) && $listed['k4']['filters'][0] === ['key' => 'hours', 'type' => 'number', 'options' => null, 'default' => '24'],
    'API reports: a lista com os filtros de cada relatório', $listed['k4'] ?? null);
$as($noCost);
$api = $v1Api()['data']['report'] ?? [];
check(!in_array('margin', array_column($api['columns'] ?? [], 'key'), true) && !isset($codes()['f3']) && !isset($codes()['k4']) && isset($codes()['v1']),
    'API sem Iter_Catalog::cost: sem as colunas de custo e margem; só os relatórios da permissão', $api['columns'] ?? null);
check(($gql('{ report(code: "k4") { total } }')['errors'][0]['extensions']['code'] ?? null) === 'NOT_FOUND', 'API: um relatório sem permissão é NOT_FOUND, como na tela');
$as($noReport);
check(($gql('{ reports { code } }')['errors'][0]['extensions']['code'] ?? null) === 'FORBIDDEN', 'API: sem permissão de relatórios, FORBIDDEN');
$as(null);
check(($gql('{ report(code: "v1") { total } }')['errors'][0]['extensions']['code'] ?? null) === 'UNAUTHENTICATED', 'API: sem token, UNAUTHENTICATED');

// cost import (Catálogo → Produtos → Importar custos): all or nothing, empty keeps, the log per product changed
$costOf = static fn (int $id) => $pdo->query("SELECT value FROM eav_value_decimal WHERE attribute_id = {$costAttribute} AND entity_id = {$id}")->fetchColumn();
$costFile = static function (string $content) use ($tmp): string {
    file_put_contents($path = $tmp . 'custos-' . bin2hex(random_bytes(3)) . '.csv', $content);

    return $path;
};
$configurable = (string) $pdo->query("SELECT sku FROM catalog_product WHERE type = 'configurable' LIMIT 1")->fetchColumn();
$import = new Iter\Catalog\Model\CostImport();
$bad = $import->run($costFile("\xEF\xBB\xBFsku;Nome;CUSTO\nRPTST-A;x;5,50\nNAO-EXISTE-RPT;;1\nrptst-a;;2\nRPTST-B;;abc\nRPTST-C;;-1\n" . ($configurable !== '' ? "{$configurable};;3\n" : '')), 'custos.csv');
check($bad === null && (float) $costOf($sku['A']) === 4.0 && array_slice($import->errors, 0, 4) === ['Linha 3: o SKU "NAO-EXISTE-RPT" não existe.', 'Linha 4: o SKU "rptst-a" já está na linha 2.', 'Linha 5: custo inválido "abc" (use um número, como 12,50).', 'Linha 6: custo inválido "-1" (use um número, como 12,50).']
    && ($configurable === '' || str_contains($import->errors[4] ?? '', 'o custo vai em cada variação')),
    'importar custos: um arquivo com erros não salva nada e diz a linha de cada um (SKU inexistente, repetido sem diferenciar maiúsculas, custo inválido ou negativo, produto com variações)', $import->errors);
check((new Iter\Catalog\Model\CostImport())->run($costFile("Produto;Preço\nRPTST-A;1\n"), 'c.csv') === null && (new Iter\Catalog\Model\CostImport())->run($costFile('x'), 'custos.pdf') === null, 'importar custos: sem as colunas SKU e Custo, ou fora de .csv/.xlsx, é recusado');
$config([Iter\Log\Model\OperationLog::ENABLED => '1']);
$as($full);
$result = (new Iter\Catalog\Model\CostImport())->run($costFile("SKU,Custo\nRPTST-A,\"1.234,5\"\nRPTST-B,R$ 0\nRPTST-C,\n\n"), 'custos.csv');
$costLog = $pdo->query("SELECT changes FROM log_operation WHERE action = 'update' AND entity_type = 'product' AND entity_id = {$sku['A']} AND admin_user_id = {$full} ORDER BY log_id DESC LIMIT 1")->fetchColumn();
check($result === ['changed' => 2, 'same' => 0, 'empty' => 1] && [(float) $costOf($sku['A']), (float) $costOf($sku['B']), $costOf($sku['C'])] === [1234.5, 0.0, false]
    && json_decode((string) $costLog, true) === ['Custo' => ['4', '1234.5']],
    'importar custos: CSV com vírgula, "1.234,5" e "R$ 0"; o vazio mantém; cada custo alterado fica no log do produto', [$result, $costLog]);
$xlsx = $tmp . 'custos.xlsx';
$writer = new OpenSpout\Writer\XLSX\Writer();
$writer->openToFile($xlsx);
$writer->addRows([OpenSpout\Common\Entity\Row::fromValues(['SKU', 'Custo']), OpenSpout\Common\Entity\Row::fromValues(['RPTST-A', 1234.5]), OpenSpout\Common\Entity\Row::fromValues(['RPTST-B', 7.25])]);
$writer->close();
check((new Iter\Catalog\Model\CostImport())->run($xlsx, 'Custos.XLSX') === ['changed' => 1, 'same' => 1, 'empty' => 0] && (float) $costOf($sku['B']) === 7.25,
    'importar custos: .xlsx com números da planilha; o custo igual não é regravado');
$model = array_column((new Iter\Catalog\Model\CostImport())->template(), null, 0);
check(($model['RPTST-A'][2] ?? null) === '1234,5' && ($model['RPTST-C'][2] ?? null) === '' && ($configurable === '' || !isset($model[$configurable])), 'importar custos: o modelo traz os SKUs com o custo de hoje, sem o produto com variações');
$as($noCost);
check(!Auth::isAllowed(Iter\Catalog\Controllers\Productcost::ADMIN_RESOURCE), 'importar custos: sem Iter_Catalog::cost, a tela é negada');

// --- limits and permission -----------------------------------------------------------------------------------
[$long, $errors] = ReportService::filters($v1, ['preset' => '', 'from' => '2000-01-01', 'to' => '2001-12-31'], true);
check([$long['from'], $long['to']] === ['2000-12-31', '2001-12-31'] && str_contains($errors[0] ?? '', 'no máximo 366 dias'), 'período acima de 366 dias: fica nos 366 dias até a data final, com o aviso', [$long['from'], $errors]);
[, $errors] = ReportService::filters($v1, ['preset' => '', 'from' => '2001-03-15', 'to' => '2001-03-01'], true);
check(str_contains($errors[0] ?? '', 'Período inválido'), 'período invertido é recusado');
$as($noReport);
ob_start();
$allowed = @Auth::authorize(Iter\Report\Controllers\Report::class, '/admin/report/view'); // @: the CLI already printed, no status code to set
$page = (string) ob_get_clean();
check(!$allowed && str_contains($page, 'Acesso negado'), 'sem Iter_Report::sales: 403 nos relatórios');
$as($noCost);
check(Auth::authorize(Iter\Report\Controllers\Report::class, '/admin/report/view') && !ReportService::filters($v1, [])[0]['cost'], 'com Iter_Report::sales e sem Iter_Catalog::cost: abre, sem o custo');

echo "Todos os testes passaram.\n";
