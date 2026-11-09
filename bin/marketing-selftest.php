<?php

declare(strict_types=1);

/**
 * MARKETING-PLAN §7: consent (and its history), the unsubscribe link and the settings check.
 * Everything runs in a transaction that is rolled back: the store's data isn't touched.
 */

require_once __DIR__ . '/../path.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once FUNCTIONS . 'bootstrap.php';

use Iter\Core\Config;
use Iter\Core\Db\Connection;
use Iter\Marketing\Controllers\Index;
use Iter\Marketing\Model\MarketingService;
use Iter\Marketing\Model\SubscriberService;
use Iter\Marketing\Model\Unsubscribe;

function check(bool $condition, string $message, mixed $got = null): void
{
    if (!$condition) {
        fwrite(STDERR, "FALHOU: {$message}\n" . ($got === null ? '' : 'obtido: ' . var_export($got, true) . "\n"));
        exit(1);
    }
    echo "OK: {$message}\n";
}

ob_start(); // the controller sends headers: nothing may go out before it
$db = Connection::get()->adapter();
$db->beginTransaction();
register_shutdown_function(static fn () => $db->inTransaction() && $db->rollBack());

$websiteId = (int) $db->fetchOne('SELECT website_id FROM store_website ORDER BY website_id LIMIT 1');
$attr = static fn (string $code): int => (int) $db->fetchOne("SELECT a.attribute_id FROM eav_attribute a JOIN eav_entity_type t ON t.entity_type_id = a.entity_type_id AND t.code = 'customer' WHERE a.code = :c", ['c' => $code]);
$optin = $attr('marketing_optin');
check($optin > 0, 'atributo marketing_optin criado no cliente');
check((int) $db->fetchOne('SELECT COUNT(*) FROM eav_attribute_set s JOIN eav_entity_type t ON t.entity_type_id = s.entity_type_id AND t.code = \'customer\' WHERE NOT EXISTS (SELECT 1 FROM eav_attribute_set_item i WHERE i.attribute_set_id = s.attribute_set_id AND i.attribute_id = :a)', ['a' => $optin]) === 0,
    'marketing_optin está em todos os conjuntos de cliente');

$email = 'mkt-' . bin2hex(random_bytes(4)) . '@selftest.local';
$customer = (int) $db->insert('customer_entity', ['website_id' => $websiteId,
    'attribute_set_id' => (int) $db->fetchOne("SELECT s.attribute_set_id FROM eav_attribute_set s JOIN eav_entity_type t ON t.entity_type_id = s.entity_type_id AND t.code = 'customer' ORDER BY s.is_default DESC LIMIT 1"),
    'group_id' => (int) $db->fetchOne('SELECT customer_group_id FROM customer_group ORDER BY 1 LIMIT 1')])['customer_id'];
$db->insert('eav_unique_value', ['attribute_id' => $attr('email'), 'entity_id' => $customer, 'scope_id' => $websiteId, 'value_key' => $email]);
$history = static fn (): array => array_map('intval', $db->fetchCol('SELECT optin FROM marketing_consent WHERE customer_id = ? ORDER BY consent_id', [$customer]));

check(!Unsubscribe::canReceive($customer, $email, $websiteId), 'cliente novo não recebe (opt-in começa desmarcado)');
$db->insertOnConflict('eav_value_boolean', ['attribute_id' => $optin, 'entity_id' => $customer, 'value' => 1], ['attribute_id', 'entity_id']);
$db->insertOnConflict('eav_value_boolean', ['attribute_id' => $optin, 'entity_id' => $customer, 'value' => 1], ['attribute_id', 'entity_id']);
check(Unsubscribe::canReceive($customer, $email, $websiteId), 'com o opt-in marcado, recebe');
check($history() === [1], 'histórico grava o aceite uma vez (salvar de novo sem mudar não repete)', $history());

$token = Unsubscribe::token(strtoupper($email), $websiteId);
check(Unsubscribe::parse($token) === ['email' => $email, 'website_id' => $websiteId], 'token do descadastro volta ao e-mail e website', Unsubscribe::parse($token));
check(str_ends_with(Unsubscribe::url($email, $websiteId), '/marketing/unsubscribe?t=' . $token), 'link do descadastro usa o endereço do site');
$shop = \Iter\Core\Config::storefrontUrl($websiteId);
check(\Iter\Marketing\Model\Rule\Rule::vars(json_encode(['link' => '{shop}', 'products' => '<a href="{shop}/x">', 'old' => \Iter\Core\Config::baseUrl($websiteId) . '/y']), $websiteId)
    === ['link' => $shop, 'products' => '<a href="' . $shop . '/x">', 'old' => $shop . '/y'], 'sugestão usa o endereço da loja web de agora (também nas antigas)');
[$payload, $mac] = explode('.', $token);
$forged = rtrim(strtr(base64_encode($websiteId . ':outro@selftest.local'), '+/', '-_'), '=') . '.' . $mac;
check(Unsubscribe::parse($forged) === null && Unsubscribe::parse($payload . '.') === null && Unsubscribe::parse('lixo') === null, 'token alterado ou sem assinatura é recusado');

$call = static function (string $action, array $get = [], array $post = []): string {
    $_GET = $get;
    $_POST = $post;
    ob_start();
    (new Index())->$action();

    return (string) ob_get_clean();
};
check(str_contains($call('Unsubscribe', ['t' => $token]), 'Confirmar') && Unsubscribe::canReceive($customer, $email, $websiteId), 'abrir o link (GET) só pergunta, não descadastra');
check(str_contains($call('Unsubscribe', ['t' => $forged]), 'Link inválido'), 'link falso mostra "Link inválido"');
check(str_contains($call('Unsubscribepost', [], ['t' => $token]), 'Pronto') && !Unsubscribe::canReceive($customer, $email, $websiteId), 'confirmar (POST) tira o consentimento do cliente');
check($history() === [1, 0], 'histórico grava a saída', $history());
check(!Unsubscribe::canReceive($customer, $email, $websiteId, true), 'quem pediu para sair não recebe nem pela exceção do carrinho');

$guest = 'visitante-' . bin2hex(random_bytes(4)) . '@selftest.local';
check(!Unsubscribe::canReceive(null, $guest, $websiteId) && Unsubscribe::canReceive(null, $guest, $websiteId, true), 'visitante só recebe com a exceção do carrinho abandonado');
$call('Unsubscribepost', [], ['t' => Unsubscribe::token($guest, $websiteId)]);
$call('Unsubscribepost', [], ['t' => Unsubscribe::token($guest, $websiteId)]);
check(!Unsubscribe::canReceive(null, strtoupper($guest), $websiteId, true), 'visitante descadastrado (duas vezes, sem erro) não recebe mais, nem pela exceção');

$gone = (int) $db->fetchOne('SELECT COALESCE(MAX(website_id), 0) + 1 FROM store_website');
check(str_contains($call('Unsubscribepost', [], ['t' => Unsubscribe::token($guest, $gone)]), 'Pronto'), 'link de um website apagado não dá erro');

$settings = static fn (int $from, int $to): array => MarketingService::validateConfig(static fn (string $p) => ['marketing/general/hour_from' => $from, 'marketing/general/hour_to' => $to][$p] ?? null);
check($settings(9, 20) === [] && $settings(20, 9) !== [] && $settings(9, 9) !== [], 'horário de envio precisa começar antes de terminar');

// --- delivery 2: the rules and the cycle -------------------------------------------------------------------
$sourceWebsite = (int) $db->fetchOne('SELECT website_id FROM inventory_source WHERE is_enabled = 1 ORDER BY source_id LIMIT 1');
$w = $sourceWebsite ?: $websiteId;
$cfg = static fn (array $values) => Config::save($values, $w);
Config::save(['mail/transport' => 'log', 'mail/from_email' => 'loja@selftest.local', 'mail/limit_per_hour' => '100']);
$rules = ['abandoned_cart', 'lapsed', 'overdue', 'never_bought', 'recurring_news'];
$cfg(array_replace(array_merge(...array_map(static fn ($r) => ["marketing/{$r}/active" => '1', "marketing/{$r}/mode" => 'auto'], $rules)), [
    'marketing/never_bought/mode' => 'review',
    'marketing/general/days_between' => '7', 'marketing/general/hour_from' => '9', 'marketing/general/hour_to' => '20',
    'marketing/general/expire_days' => '3', 'marketing/general/cart_without_optin' => '0',
    'marketing/abandoned_cart/hours_first' => '3', 'marketing/abandoned_cart/hours_second' => '24', 'marketing/abandoned_cart/guests' => '1',
    'marketing/lapsed/days' => '90', 'marketing/overdue/min_orders' => '3', 'marketing/overdue/tolerance' => '20', 'marketing/never_bought/days' => '7',
    'marketing/recurring_news/min_orders' => '3', 'marketing/recurring_news/period_days' => '180', 'marketing/recurring_news/every_days' => '30',
    'marketing/recurring_news/products' => '6', 'marketing/recurring_news/new_days' => '30',
]));
$set = (int) $db->fetchOne("SELECT s.attribute_set_id FROM eav_attribute_set s JOIN eav_entity_type t ON t.entity_type_id = s.entity_type_id AND t.code = 'customer' ORDER BY s.is_default DESC LIMIT 1");
$group = (int) $db->fetchOne('SELECT customer_group_id FROM customer_group ORDER BY 1 LIMIT 1');
$tag = bin2hex(random_bytes(3));
$person = static function (string $name, int $daysAgo = 400, bool $consent = true) use ($db, $w, $set, $group, $attr, $optin, $tag): array {
    $email = "mkt-{$name}-{$tag}@selftest.local";
    $id = (int) $db->insert('customer_entity', ['website_id' => $w, 'attribute_set_id' => $set, 'group_id' => $group,
        'created_at' => date('Y-m-d H:i:s', strtotime("-{$daysAgo} days"))])['customer_id'];
    $db->insert('eav_value_varchar', ['attribute_id' => $attr('email'), 'entity_id' => $id, 'value' => $email]);
    $db->insert('eav_value_varchar', ['attribute_id' => $attr('firstname'), 'entity_id' => $id, 'value' => ucfirst($name)]);
    $db->insert('eav_unique_value', ['attribute_id' => $attr('email'), 'entity_id' => $id, 'scope_id' => $w, 'value_key' => $email]);
    $consent && $db->insert('eav_value_boolean', ['attribute_id' => $optin, 'entity_id' => $id, 'value' => 1]);

    return ['id' => $id, 'email' => $email];
};
$order = static function (array $c, int $daysAgo, string $status = 'complete') use ($db, $w): int {
    return (int) $db->insert('sales_order', ['increment_id' => 'MKT' . bin2hex(random_bytes(5)), 'website_id' => $w, 'customer_id' => $c['id'], 'email' => $c['email'],
        'firstname' => 'T', 'lastname' => 'T', 'taxvat' => '', 'status' => $status, 'subtotal' => 10, 'grand_total' => 10,
        'created_at' => date('Y-m-d H:i:s', strtotime("-{$daysAgo} days"))])['order_id'];
};
$product = (int) $db->insert('catalog_product', ['attribute_set_id' => (int) $db->fetchOne("SELECT s.attribute_set_id FROM eav_attribute_set s JOIN eav_entity_type t ON t.entity_type_id = s.entity_type_id AND t.code = 'product' ORDER BY s.is_default DESC LIMIT 1"),
    'sku' => "MKT-{$tag}", 'name' => "Novidade Selftest {$tag}", 'url_key' => "novidade-selftest-{$tag}", 'is_active' => 1])['product_id'];
$db->insert('catalog_product_website', ['product_id' => $product, 'website_id' => $w]);
if (\isModuleEnabled('iter_inventory')) {
    $source = (int) $db->insert('inventory_source', ['code' => "mkt_{$tag}", 'name' => 'Selftest marketing', 'website_id' => $w, 'is_enabled' => 1])['source_id'];
    $db->insert('inventory_source_item', ['source_id' => $source, 'product_id' => $product, 'quantity' => 5, 'status' => 1]);
}
$cart = static function (?array $c, int $hoursAgo, ?string $guestEmail = null) use ($db, $w, $product): int {
    $quote = (int) $db->insert('sales_quote', ['website_id' => $w, 'customer_id' => $c['id'] ?? null, 'email' => $guestEmail, 'is_active' => 1,
        'token_hash' => $c === null ? hash('sha256', random_bytes(16)) : null,
        'updated_at' => date('Y-m-d H:i:s', strtotime("-{$hoursAgo} hours"))])['quote_id'];
    $db->insert('sales_quote_item', ['quote_id' => $quote, 'product_id' => $product, 'quantity' => 2]);

    return $quote;
};
$of = static fn (array|string $c): array => $db->fetchAll('SELECT * FROM marketing_suggestion WHERE lower(email) = ? ORDER BY suggestion_id', [is_array($c) ? $c['email'] : $c]);

$a = $person('carrinho');
$quoteA = $cart($a, 4);
$cart($person('recente'), 1); // 1 h: not yet
$guestEmail = "mkt-visitante-{$tag}@selftest.local";
$cart(null, 4, $guestEmail);
$n = $person('nunca', 10);
$person('novato', 2); // signed up 2 days ago
$l = $person('sumido');
$lOrder = $order($l, 100);
$o = $person('ritmo');
foreach ([100, 70] as $d) { $order($o, $d); }
$oLast = $order($o, 40); // every 30 days, 40 since the last: past 36
$both = $person('ambos');
foreach ([200, 150] as $d) { $order($both, $d); }
$bothLast = $order($both, 100); // overdue (every 50, +20% = 60) and lapsed (≥ 90): one e-mail
$r = $person('fiel');
foreach ([50, 30, 10] as $d) { $order($r, $d); }
$x = $person('semaceite', 400, false);
$order($x, 200);
$order($person('cancelado'), 120, 'canceled'); // not paid: neither lapsed nor overdue

$service = new MarketingService();
$totals = $service->run(10);
$sa = $of($a);
check(count($sa) === 1 && $sa[0]['rule'] === 'abandoned_cart' && $sa[0]['reason_key'] === "quote:{$quoteA}:1" && $sa[0]['status'] === 'sent', 'carrinho parado há 4 h (automático): 1º lembrete enviado', $sa);
$mail = $db->fetchRow('SELECT * FROM mail_queue WHERE message_id = ?', [(int) $sa[0]['mail_id']]);
$headers = json_decode($mail['headers'], true);
check($mail['template_code'] === 'marketing_abandoned_cart' && str_contains($mail['body_html'], "Novidade Selftest {$tag} × 2") && str_contains($mail['body_html'], '/marketing/unsubscribe?t='),
    'e-mail do carrinho com os itens e o link "Não quero mais receber"');
check(str_contains($headers['List-Unsubscribe'] ?? '', '/marketing/unsubscribepost?t=') && ($headers['List-Unsubscribe-Post'] ?? '') === 'List-Unsubscribe=One-Click', 'cabeçalhos List-Unsubscribe de um clique', $headers);
$rulesOf = static fn (string $email): array => array_column($of($email), 'rule');
check(!in_array('abandoned_cart', $rulesOf("mkt-recente-{$tag}@selftest.local"), true), 'carrinho parado há 1 h ainda não entra');
check($of($guestEmail) === [], 'visitante sem a exceção do consentimento não entra');
$sn = $of($n);
check(count($sn) === 1 && $sn[0]['status'] === 'pending' && $sn[0]['reason_key'] === "customer:{$n['id']}" && $sn[0]['mail_id'] === null, 'nunca comprou (aguardar aprovação): sugestão pendente, nada enviado', $sn);
check($of("mkt-novato-{$tag}@selftest.local") === [], 'cadastrado há 2 dias ainda não entra em "nunca comprou"');
$sl = $of($l);
check(count($sl) === 1 && $sl[0]['rule'] === 'lapsed' && $sl[0]['reason_key'] === "last_order:{$lOrder}" && $sl[0]['status'] === 'sent', 'sem comprar há 100 dias: enviado', $sl);
$so = $of($o);
check(count($so) === 1 && $so[0]['rule'] === 'overdue' && $so[0]['reason_key'] === "last_order:{$oLast}" && json_decode($so[0]['vars'], true)['every'] === '30', 'fora do ritmo (a cada 30 dias, 40 sem comprar): enviado', $so);
$sb = $of($both);
check(count($sb) === 1 && $sb[0]['reason_key'] === "last_order:{$bothLast}", 'sem comprar e fora do ritmo pela mesma compra: um e-mail só', $sb);
$sr = $of($r);
check(count($sr) === 1 && $sr[0]['rule'] === 'recurring_news' && str_contains(json_decode($sr[0]['vars'], true)['products'], "novidade-selftest-{$tag}"), 'recorrente recebe as novidades com o produto novo', $sr);
check($of($x) === [] && array_intersect(['lapsed', 'overdue'], $rulesOf("mkt-cancelado-{$tag}@selftest.local")) === [], 'sem consentimento: nada; pedido cancelado não conta como compra');
$service->run(10);
check(count($of($a)) === 1 && count($of($l)) === 1 && count($of($r)) === 1, 'rodar de novo: continua um e-mail por motivo');

// 2nd reminder (a cart idle for 25 h; sales_quote's trigger keeps updated_at = NOW() on every UPDATE): the 1st
// goes out, the 2nd is blocked by the 7-day limit, then goes out with the limit off
$a2 = $person('lembrete');
$quoteA2 = $cart($a2, 25);
$service->run(10);
check(array_column($of($a2), 'reason_key') === ["quote:{$quoteA2}:1"], 'carrinho de 25 h: primeiro só o 1º lembrete', $of($a2));
$service->run(10);
check(count($of($a2)) === 1, '2º lembrete barrado pelo limite de 1 e-mail a cada 7 dias');
$cfg(['marketing/general/days_between' => '0']);
$service->run(10);
$s2nd = $of($a2);
check(count($s2nd) === 2 && $s2nd[1]['reason_key'] === "quote:{$quoteA2}:2" && $s2nd[1]['status'] === 'sent', 'sem limite, o 2º lembrete sai (depois do 1º)', $s2nd);
$cfg(['marketing/general/days_between' => '7']);

// sending hours: created at 22 h, waits, goes out at 10 h
$cfg(['marketing/general/cart_without_optin' => '1']);
$service->run(22);
$sg = $of($guestEmail);
check(count($sg) === 1 && $sg[0]['status'] === 'approved' && $sg[0]['mail_id'] === null, 'com a exceção, o visitante entra; fora do horário, espera', $sg);
$service->run(10);
check($of($guestEmail)[0]['status'] === 'sent', 'no horário, o e-mail do visitante sai');

// checked again when sending: the reason and the consent
$order($n, 1);
$db->update('marketing_suggestion', ['status' => 'approved'], ['suggestion_id = ?' => $sn[0]['suggestion_id']]);
$service->run(10);
$sn = $of($n);
check($sn[0]['status'] === 'skipped' && str_contains((string) $sn[0]['note'], 'deixou de valer'), 'aprovado depois que o cliente comprou: pulado, com o motivo', $sn);
$n2 = $person('desistiu', 10);
$service->run(10);
$db->delete('eav_value_boolean', ['attribute_id = ?' => $optin, 'entity_id = ?' => $n2['id']]);
$db->update('marketing_suggestion', ['status' => 'approved'], ['lower(email) = ?' => $n2['email']]);
$service->run(10);
$s2 = $of($n2);
check($s2[0]['status'] === 'skipped' && str_contains((string) $s2[0]['note'], 'consentimento'), 'tirou o consentimento antes do envio: pulado', $s2);

// expiry
$n3 = $person('esquecido', 10);
$service->run(10);
$db->update('marketing_suggestion', ['created_at' => date('Y-m-d H:i:s', strtotime('-4 days'))], ['lower(email) = ?' => $n3['email']]);
$service->run(10);
check($of($n3)[0]['status'] === 'expired', 'sugestão não aprovada em 3 dias expira');

// a rule off creates nothing
$cfg(['marketing/never_bought/active' => '0']);
$n4 = $person('desligado', 10);
$service->run(10);
check($of($n4) === [], 'regra desligada não cria sugestão');

// one-click unsubscribe: POST to the header's URL, token in the query
$tokenR = Unsubscribe::token($r['email'], $w);
$call('Unsubscribepost', ['t' => $tokenR]);
check(!Unsubscribe::canReceive($r['id'], $r['email'], $w), 'descadastro de um clique (POST com ?t=) tira o consentimento');

// --- NEWSLETTER-PLAN: double opt-in, consent, unsubscribe and "Novidades" --------------------------------------
$subs = new SubscriberService();
$cfg(['marketing/newsletter/confirm_url' => 'https://loja.selftest.local/news/ok?token={token}', 'marketing/newsletter/confirm_hours' => '48']);
$mails = static fn (string $email): array => $db->fetchAll("SELECT template_code, body_html, is_private FROM mail_queue WHERE lower(to_email) = ? AND template_code = 'marketing_newsletter_confirm' ORDER BY message_id", [mb_strtolower($email)]);
$tokenOf = static fn (array $mail): string => preg_match('#news/ok\?token=([0-9a-f]{64})#', (string) $mail['body_html'], $m) ? $m[1] : '';
$sub = static fn (string $email): array|false => $db->fetchRow('SELECT * FROM marketing_subscriber WHERE website_id = ? AND lower(email) = ?', [$w, mb_strtolower($email)]);
$fails = static function (callable $call): ?string {
    try {
        $call();
    } catch (\Iter\Eav\Model\AttributeException $e) {
        return $e->errorCode;
    }
    return null;
};
$news = "mkt-news-{$tag}@selftest.local";
$subs->subscribe(strtoupper($news), $w, '203.0.113.9');
$first = $mails($news);
$row = $sub($news);
check(count($first) === 1 && (int) $first[0]['is_private'] === 1 && strlen($tokenOf($first[0])) === 64 && $row['status'] === 'pending' && $row['email'] === $news
    && $row['ip'] === '203.0.113.9' && strlen((string) $row['token_hash']) === 64 && $row['token_hash'] !== $tokenOf($first[0]),
    'inscrição nova: pendente, e-mail de confirmação (privado) com o link do site; só o hash do token fica gravado', [$first, $row]);
$subs->subscribe($news, $w);
check(count($mails($news)) === 1, 'inscrever de novo dentro de 1 minuto não reenvia');
$db->update('marketing_subscriber', ['confirm_sent_at' => date('Y-m-d H:i:s', strtotime('-2 minutes'))], ['subscriber_id = ?' => $row['subscriber_id']]);
$subs->subscribe($news, $w);
$again = $mails($news);
$oldToken = $tokenOf($first[0]);
$newToken = $tokenOf($again[1] ?? []);
check(count($again) === 2 && $newToken !== $oldToken, 'pendente depois de 1 minuto: manda outro link');
check($fails(static fn () => $subs->confirm($oldToken)) === 'TOKEN_INVALID', 'o link anterior deixa de valer');
check(!Unsubscribe::canReceive(null, $news, $w), 'pendente não recebe marketing');
$subs->confirm($newToken);
$row = $sub($news);
$consent = $db->fetchAll('SELECT optin, origin, customer_id FROM marketing_consent WHERE subscriber_id = ? ORDER BY consent_id', [$row['subscriber_id']]);
check($row['status'] === 'confirmed' && $row['confirmed_at'] !== null && $row['token_hash'] === null && $consent === [['optin' => 1, 'origin' => 'newsletter', 'customer_id' => null]]
    && Unsubscribe::canReceive(null, $news, $w), 'confirmar: inscrito confirmado, aceite gravado em marketing_consent (origem newsletter) e passa a poder receber', [$row, $consent]);
check($fails(static fn () => $subs->confirm($newToken)) === 'TOKEN_INVALID', 'o token vale uma vez só');
$subs->subscribe($news, $w);
check(count($mails($news)) === 2 && $sub($news)['status'] === 'confirmed', 'e-mail já confirmado: nada muda e nada é enviado');
check($fails(static fn () => $subs->subscribe('sem-arroba', $w)) === 'INVALID_INPUT' && $fails(static fn () => $subs->subscribe($news, 999999)) === 'INVALID_INPUT', 'e-mail inválido e website desconhecido: INVALID_INPUT');

$late = "mkt-late-{$tag}@selftest.local";
$subs->subscribe($late, $w);
$lateToken = $tokenOf($mails($late)[0]);
$db->update('marketing_subscriber', ['token_expires_at' => date('Y-m-d H:i:s', strtotime('-1 minute'))], ['lower(email) = ?' => $late]);
check($fails(static fn () => $subs->confirm($lateToken)) === 'TOKEN_INVALID' && $sub($late)['status'] === 'pending', 'token vencido: TOKEN_INVALID, segue pendente');
check($fails(static fn () => $subs->confirm('desconhecido')) === 'TOKEN_INVALID', 'token desconhecido: TOKEN_INVALID');

// the e-mail is a customer's: confirming checks the customer's "Aceita receber ofertas por e-mail"
$cust = $person('assina', 400, false);
$subs->subscribe($cust['email'], $w);
$subs->confirm($tokenOf($mails($cust['email'])[0]));
$custConsent = $db->fetchAll('SELECT optin, origin FROM marketing_consent WHERE customer_id = ? ORDER BY consent_id', [$cust['id']]);
check(Unsubscribe::canReceive($cust['id'], $cust['email'], $w) && $custConsent === [['optin' => 1, 'origin' => 'newsletter']], 'e-mail de cliente: a confirmação marca o opt-in do cliente, com a origem newsletter', $custConsent);

// "Novidades": the confirmed subscriber who isn't a customer gets the newsletter's template; the interval holds
$service->run(10);
$sn = $of($news);
$nm = $sn ? $db->fetchRow('SELECT template_code, body_html FROM mail_queue WHERE message_id = ?', [(int) $sn[0]['mail_id']]) : [];
check(count($sn) === 1 && $sn[0]['rule'] === 'recurring_news' && $sn[0]['customer_id'] === null && $sn[0]['status'] === 'sent' && ($nm['template_code'] ?? '') === 'marketing_newsletter_news'
    && str_contains($nm['body_html'], "novidade-selftest-{$tag}") && str_contains($nm['body_html'], 'se inscreveu na newsletter') && str_contains($nm['body_html'], 'marketing/unsubscribe?t='),
    'Novidades: o inscrito confirmado que não é cliente recebe, com o modelo da newsletter e o descadastro', [$sn, $nm]);
check(!array_filter($of($cust['email']), static fn ($x) => $x['rule'] === 'recurring_news' && $x['customer_id'] === null), 'inscrito que é cliente não entra como visitante nas Novidades');
$db->update('marketing_suggestion', ['created_at' => date('Y-m-d H:i:s', strtotime('-10 days')), 'sent_at' => date('Y-m-d H:i:s', strtotime('-10 days'))], ['lower(email) = ?' => $news]);
$service->run(10);
check(count($of($news)) === 1, 'Novidades: respeita o "Enviar a cada" (30 dias) mesmo depois do limite de 7 dias');

// one-click unsubscribe: the subscriber goes to unsubscribed; subscribing again asks for the confirmation again
$call('Unsubscribepost', ['t' => Unsubscribe::token($news, $w)]);
$row = $sub($news);
$consent = array_column($db->fetchAll('SELECT optin FROM marketing_consent WHERE subscriber_id = ? ORDER BY consent_id', [$row['subscriber_id']]), 'optin');
check($row['status'] === 'unsubscribed' && $row['unsubscribed_at'] !== null && $consent === [1, 0] && !Unsubscribe::canReceive(null, $news, $w, true), 'descadastro em um clique: inscrito descadastrado, saída gravada, não recebe mais', [$row, $consent]);
$db->update('marketing_subscriber', ['confirm_sent_at' => date('Y-m-d H:i:s', strtotime('-2 minutes'))], ['subscriber_id = ?' => $row['subscriber_id']]);
$subs->subscribe($news, $w);
check($sub($news)['status'] === 'pending' && count($mails($news)) === 3, 'descadastrado que se inscreve de novo volta a pendente e recebe outra confirmação');
$subs->confirm($tokenOf($mails($news)[2]));
check(Unsubscribe::canReceive(null, $news, $w), 'confirmado de novo: o descadastro antigo deixa de valer');

$rendered = array_map(static fn ($rule) => \Iter\Mail\Model\Template::render(\Iter\Mail\Model\Template::get($rule->template()), \Iter\Mail\Model\Template::get($rule->template())['vars'])['html'], MarketingService::rules());
check(count($rendered) === 5 && !array_filter($rendered, static fn ($html) => !str_contains($html, 'Não quero mais receber') || !str_contains($html, 'marketing/unsubscribe?t=')), 'os 5 modelos de e-mail existem e levam o link de descadastro');

check(MarketingService::validateConfig(static fn ($p) => ['marketing/general/hour_from' => 9, 'marketing/general/hour_to' => 20, 'marketing/abandoned_cart/hours_first' => 3, 'marketing/abandoned_cart/hours_second' => 3][$p] ?? null) !== []
    && MarketingService::validateConfig(static fn ($p) => ['marketing/general/hour_from' => 9, 'marketing/general/hour_to' => 20, 'marketing/abandoned_cart/hours_first' => 3, 'marketing/abandoned_cart/hours_second' => 0][$p] ?? null) === [],
    '2º lembrete precisa vir depois do 1º (0 = sem 2º)');

$db->rollBack();
echo "Tudo certo.\n";
