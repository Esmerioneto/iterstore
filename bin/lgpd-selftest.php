<?php

declare(strict_types=1);

/**
 * LGPD-PLAN, delivery 1: the modules' etc/lgpd.json are valid, the inventory follows the enabled modules and the
 * settings ("when", retention_config), and the extra operators come in. Delivery 2: the copy (myPersonalData), the
 * erase (deleteMe) with what stays and what goes, the requests. Delivery 3: the policy (draft, versions frozen,
 * privacy query), its acceptance and Customer.privacyVersion. Everything runs in a transaction that is rolled back.
 */

require_once __DIR__ . '/../path.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once FUNCTIONS . 'bootstrap.php';

use Iter\Core\Config;
use Iter\Core\Db\Connection;
use Iter\Lgpd\Model\Inventory;
use Iter\Lgpd\View\InventoryList;

function check(bool $condition, string $message, mixed $got = null): void
{
    if (!$condition) {
        fwrite(STDERR, "FALHOU: {$message}\n" . ($got === null ? '' : 'obtido: ' . var_export($got, true) . "\n"));
        exit(1);
    }
    echo "OK: {$message}\n";
}

if (!isModuleEnabled('iter_lgpd')) {
    echo "Iter_Lgpd desligado: nada a testar.\n";
    exit(0);
}

$db = Connection::get()->adapter();
$db->beginTransaction();
register_shutdown_function(static fn () => $db->inTransaction() && $db->rollBack());
$set = static function (array $values): void {
    Config::save($values);
};

// 1) every lgpd.json is complete and consistent
check(Inventory::problems() === [], 'todo etc/lgpd.json tem os campos, bases legais conhecidas, handler e prazo válidos', Inventory::problems());

// 2) the inventory lists only what the enabled modules declare
$modules = array_unique(array_column(Inventory::activities(), 'module'));
check(in_array('Customer', $modules, true) && in_array('Sales', $modules, true), 'Cadastro e Pedidos no inventário', $modules);
check(!in_array('Correios', $modules, true) || isModuleEnabled('iter_correios'), 'módulo desligado (Correios) fica de fora', $modules);
$config = json_decode((string) file_get_contents(ETC . 'config.json'), true);
$config['vendor']['iter_wishlist'] = '0';
$file = ETC . 'config.json';
$original = (string) file_get_contents($file);
file_put_contents($file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
try {
    getModules(true);
    check(!in_array('Wishlist', array_column(Inventory::activities(), 'module'), true), 'desligar o Wishlist tira os Favoritos do inventário');
} finally {
    file_put_contents($file, $original);
    getModules(true);
}
check(in_array('Wishlist', array_column(Inventory::activities(), 'module'), true), 'religado, Favoritos volta');

// 3) "when" by setting: the backup operator appears only with its destination
$operatorNames = static fn () => array_column(Inventory::operators(), 'name');
$set(['system/backup/destination' => 'local']);
check(!in_array('Backblaze B2', $operatorNames(), true), 'backup só no servidor: sem operador de backup');
$set(['system/backup/destination' => 'b2']);
check(in_array('Backblaze B2', $operatorNames(), true), 'backup na Backblaze: Backblaze B2 (EUA) entra como operador');

// 4) "when" by call: only the try-on provider in use receives photos
$set(['catalog/tryon/active' => '0']);
check(!array_intersect(['Cloudflare', 'Gemini', 'Huggingface', 'Fashnlocal'], array_column(Inventory::activities(), 'module')), 'provador desligado: nenhum provedor no inventário');
$set(['catalog/tryon/active' => '1']);
$inUse = Iter\Tryon\Model\TryonProviderRegistry::inUseCode();
$providers = array_values(array_intersect(['Cloudflare', 'Gemini', 'Huggingface', 'Fashnlocal'], array_column(Inventory::activities(), 'module')));
check(count($providers) === ($inUse === null ? 0 : 1), 'provador ligado: só o provedor em uso (' . ($inUse ?? 'nenhum configurado') . ')', $providers);

// 5) retention from the setting's current value
$set(['mail/log/retention_days' => '45']);
$mail = array_values(array_filter(Inventory::activities(), static fn ($a) => $a['module'] === 'Mail'))[0] ?? null;
check($mail !== null && str_contains($mail['retention_now'], '45 dias'), 'prazo do e-mail mostra o valor configurado (45 dias)', $mail['retention_now'] ?? null);
$set(['system/operation_log/retention_days' => '0']);
$log = array_values(array_filter(Inventory::activities(), static fn ($a) => $a['module'] === 'Log'))[0] ?? null;
check($log !== null && str_contains($log['retention_now'], 'sem limpeza automática'), 'prazo 0 aparece como "sem limpeza automática"', $log['retention_now'] ?? null);

// 6) extra operators: one per line, merged with the modules' ones
$set([Inventory::EXTRA_OPERATORS => "Contabilidade Silva | Brasil | notas fiscais\n\n  Hospedagem X | Alemanha | servidor  \nMercado Pago | Brasil | conciliação"]);
$ops = [];
foreach (Inventory::operators() as $op) {
    $ops[$op['name']] = $op;
}
check(isset($ops['Contabilidade Silva'], $ops['Hospedagem X']) && $ops['Hospedagem X']['country'] === 'Alemanha', 'outros operadores entram, linha vazia ignorada, espaços cortados');
check(in_array('conciliação', $ops['Mercado Pago']['purposes'] ?? [], true) && in_array('Configurações', $ops['Mercado Pago']['modules'] ?? [], true) && count(array_filter(array_keys($ops), static fn ($n) => $n === 'Mercado Pago')) === 1, 'mesmo operador do módulo e da configuração aparece uma vez, com as duas finalidades');

// 7) the text for the policy flags the international transfer
$text = InventoryList::policyText(Inventory::operators());
check(str_contains($text, 'Hospedagem X, Alemanha (transferência internacional)') && !str_contains($text, 'Contabilidade Silva, Brasil (transferência'), 'texto da política marca só a transferência internacional');
check(!Inventory::isAbroad(Inventory::UNKNOWN_COUNTRY) && !Inventory::isAbroad('') && !Inventory::isAbroad(' Brasil ') && Inventory::isAbroad('EUA'), 'país "conforme o provedor" ou vazio não conta como internacional');
check(!str_contains($text, 'conforme o provedor (transferência') && str_contains($text, 'país a confirmar'), 'no texto da política, SMTP aparece com "país a confirmar", sem marca de internacional');
check(!str_contains($text, 'conforme o provedor') && !str_contains($text, 'Configurações →'), 'texto para o cliente sem jargão do painel ("conforme o provedor", "Configurações →")');

// 8) the screen renders
$data = (new InventoryList())->getData();
ob_start();
extract($data);
include __DIR__ . '/../src/vendor/Iter/Lgpd/view/frontend/templates/inventory.php';
$html = (string) ob_get_clean();
check(str_contains($html, 'Inventário de dados pessoais') && str_contains($html, 'Hospedagem X') && str_contains($html, 'internacional'), 'tela Inventário mostra atividades, operadores e o aviso internacional');

// ---------------------------------------------------------------------------------------------------------
// delivery 2: the data subject's rights
use Iter\Customer\Model\Customer;
use Iter\Customer\Model\CustomerAuth;
use Iter\Customer\Model\CustomerService;
use Iter\Lgpd\Model\LgpdException;
use Iter\Lgpd\Model\PersonalDataService;
use Iter\Lgpd\Model\RequestService;

$code = static function (callable $fn): ?string {
    try {
        $fn();
    } catch (LgpdException|Iter\Eav\Model\AttributeException $e) {
        return $e->errorCode;
    } catch (GraphQL\Error\Error $e) {
        return $e->getExtensions()['code'] ?? $e->getMessage();
    }

    return null;
};
$cpf = static function (): string { // a valid CPF, different on each run
    $n = array_map('intval', str_split(sprintf('%09d', random_int(100000000, 999999999))));
    foreach ([10, 11] as $w) {
        $sum = 0;
        foreach ($n as $i => $d) {
            $sum += $d * ($w - $i);
        }
        $n[] = ($sum * 10) % 11 % 10;
    }

    return implode('', $n);
};
$websiteId = (int) $db->fetchOne('SELECT website_id FROM store_website ORDER BY website_id LIMIT 1');
$email = 'lgpd-selftest-' . bin2hex(random_bytes(4)) . '@example.com';
$taxvat = $cpf();
$customer = (new CustomerService())->create([
    'website_id' => $websiteId, 'group_id' => (int) $db->fetchOne('SELECT customer_group_id FROM customer_group ORDER BY customer_group_id LIMIT 1'),
    'email' => $email, 'firstname' => 'Ana', 'lastname' => 'Titular', 'customer_type' => 'pf', 'taxvat' => $taxvat, 'dob' => '1990-05-01',
], [], [['postcode' => '01310-100', 'street' => 'Avenida Paulista', 'number' => '1000', 'district' => 'Bela Vista', 'city' => 'São Paulo', 'region' => 'SP']], false);
$id = (int) $customer->getId();
$db->update('customer_entity', ['password_hash' => password_hash('Senha-Forte-1', PASSWORD_DEFAULT)], ['customer_id = ?' => $id]);
$product = (int) $db->fetchOne('SELECT product_id FROM catalog_product ORDER BY product_id LIMIT 1');
$product && isModuleEnabled('iter_wishlist') && $db->insert('customer_wishlist_item', ['customer_id' => $id, 'product_id' => $product]);
isModuleEnabled('iter_message') && $db->insert('customer_message', ['website_id' => $websiteId, 'customer_id' => $id, 'sender' => 'customer', 'body' => 'Meu CPF é ' . $taxvat]);
isModuleEnabled('iter_marketing') && $db->insert('marketing_subscriber', ['website_id' => $websiteId, 'email' => $email, 'status' => 'confirmed', 'ip' => '10.0.0.1', 'confirmed_at' => date('Y-m-d H:i:s')]);
isModuleEnabled('iter_marketing') && $db->insert('marketing_suggestion', ['website_id' => $websiteId, 'customer_id' => $id, 'email' => $email, 'rule' => 'lapsed', 'reason_key' => 'x', 'vars' => '{"name":"Ana"}', 'status' => 'pending']);
$quote = (int) $db->insert('sales_quote', ['customer_id' => $id, 'website_id' => $websiteId, 'is_active' => 1, 'email' => $email, 'taxvat' => $taxvat])['quote_id'];
$order = (int) $db->insert('sales_order', ['increment_id' => 'LGPDT-' . $id, 'website_id' => $websiteId, 'customer_id' => $id, 'quote_id' => $quote, 'email' => $email, 'firstname' => 'Ana',
    'lastname' => 'Titular', 'taxvat' => $taxvat, 'status' => 'processing', 'subtotal' => 10, 'grand_total' => 10])['order_id'];
$db->insert('sales_order_address', ['order_id' => $order, 'type' => 'shipping', 'postcode' => '01310100', 'street' => 'Avenida Paulista', 'number' => '1000', 'city' => 'São Paulo', 'region' => 'SP', 'recipient' => 'Ana Titular']);
$guestEmail = 'lgpd-visitante-' . bin2hex(random_bytes(4)) . '@example.com';
$db->insert('sales_order', ['increment_id' => 'LGPDV-' . $id, 'website_id' => $websiteId, 'email' => $guestEmail, 'firstname' => 'Vera', 'lastname' => 'Visitante',
    'taxvat' => $cpf(), 'status' => 'complete', 'subtotal' => 5, 'grand_total' => 5]);
$service = new PersonalDataService();

// 9) the copy: one key per module with data, the visitor's by e-mail
$copy = $service->export($id, $email);
check(($copy['Titular'] ?? '') === $email && isset($copy['Cadastro do cliente'], $copy['Pedidos']) && str_contains(json_encode($copy, JSON_UNESCAPED_UNICODE), 'Avenida Paulista'),
    'cópia do cliente: cadastro, endereço e pedidos', array_keys($copy));
check(!isModuleEnabled('iter_wishlist') || isset($copy['Favoritos']), 'cópia traz os favoritos');
check(!isModuleEnabled('iter_marketing') || str_contains(json_encode($copy['E-mails de marketing e newsletter'] ?? [], JSON_UNESCAPED_UNICODE), 'Confirmado'), 'cópia traz a inscrição na newsletter');
$guest = $service->export(null, $guestEmail);
check(!isset($guest['Cadastro do cliente']) && ($guest['Pedidos']['Pedidos'][0]['Pedido'] ?? '') === 'LGPDV-' . $id, 'cópia de visitante: só os pedidos do e-mail, sem cadastro', $guest);

// 10) the API: login required, the copy is a JSON string and is recorded
$gql = static function (string $query, array $variables = []): array {
    $result = \GraphQL\GraphQL::executeQuery(Iter\Core\GraphQl\Schema::build(), $query, null, null, $variables)->toArray();

    return [$result['data'] ?? null, $result['errors'][0]['extensions']['code'] ?? null];
};
$as = static function (?int $customerId): void {
    (new ReflectionProperty(CustomerAuth::class, 'customer'))->setValue(null, $customerId === null ? null : (new Customer())->load($customerId));
};
$as(null);
check($gql('{ myPersonalData }')[1] === 'UNAUTHENTICATED' && $gql('mutation { deleteMe(currentPassword: "x") }')[1] === 'UNAUTHENTICATED', 'sem login: UNAUTHENTICATED');
$as($id);
[$data] = $gql('{ myPersonalData }');
check((json_decode((string) ($data['myPersonalData'] ?? ''), true)['Titular'] ?? null) === $email, 'myPersonalData devolve a cópia em JSON');
check((int) $db->fetchOne("SELECT COUNT(*) FROM lgpd_request WHERE customer_id = :c AND type = 'export' AND status = 'done' AND origin = 'app'", ['c' => $id]) === 1, 'a cópia pelo app fica registrada como atendida');

// 11) deleteMe: password, then an order still running blocks
check($gql('mutation { deleteMe(currentPassword: "errada") }')[1] === 'INVALID_CREDENTIALS', 'deleteMe com senha errada: INVALID_CREDENTIALS');
check($gql('mutation { deleteMe(currentPassword: "Senha-Forte-1") }')[1] === 'LGPD_ERASE_BLOCKED', 'pedido em andamento bloqueia: LGPD_ERASE_BLOCKED');
check(str_contains(implode(' ', $service->blockers($id)), 'LGPDT-' . $id), 'o bloqueio cita o número do pedido');
$db->update('sales_order', ['status' => 'complete'], ['order_id = ?' => $order]);
$mailsBefore = (int) $db->fetchOne('SELECT COUNT(*) FROM mail_queue WHERE LOWER(to_email) = :e', ['e' => $email]);
[$data, $err] = $gql('mutation { deleteMe(currentPassword: "Senha-Forte-1") }');
check(($data['deleteMe'] ?? null) === true, 'deleteMe sem pedido em aberto: true', $err);

// 12) what went and what stayed
$flat = $db->fetchRow('SELECT f.*, c.anonymized_at, c.is_active FROM customer_flat f JOIN customer_entity c USING (customer_id) WHERE f.customer_id = :c', ['c' => $id]);
check($flat['email'] === Iter\Customer\Model\PersonalData::anonymousEmail($id) && $flat['firstname'] === 'Cliente' && $flat['taxvat'] === null && $flat['dob'] === null
    && $flat['anonymized_at'] !== null && (int) $flat['is_active'] === 0, 'cadastro anonimizado: e-mail inválido, sem CPF e nascimento, inativo', $flat);
check(!password_verify('Senha-Forte-1', (string) $db->fetchOne('SELECT password_hash FROM customer_entity WHERE customer_id = :c', ['c' => $id])), 'a senha antiga não vale mais');
check((int) $db->fetchOne('SELECT COUNT(*) FROM customer_address WHERE customer_id = :c', ['c' => $id]) === 0, 'endereços apagados');
check((int) $db->fetchOne('SELECT COUNT(*) FROM customer_api_token WHERE customer_id = :c', ['c' => $id]) === 0, 'tokens apagados');
check((int) $db->fetchOne('SELECT COUNT(*) FROM eav_unique_value WHERE value_key = :t', ['t' => $taxvat]) === 0, 'o CPF fica livre (unicidade) para um cadastro novo');
check((int) $db->fetchOne('SELECT COUNT(*) FROM sales_quote WHERE quote_id = :q', ['q' => $quote]) === 0, 'carrinho apagado');
$kept = $db->fetchRow('SELECT email, taxvat, quote_id FROM sales_order WHERE order_id = :o', ['o' => $order]);
check($kept['email'] === $email && $kept['taxvat'] === $taxvat && $kept['quote_id'] === null, 'o pedido fica com a cópia dos dados (prazo fiscal)', $kept);
check(!isModuleEnabled('iter_wishlist') || (int) $db->fetchOne('SELECT COUNT(*) FROM customer_wishlist_item WHERE customer_id = :c', ['c' => $id]) === 0, 'favoritos apagados');
check(!isModuleEnabled('iter_message') || (int) $db->fetchOne('SELECT COUNT(*) FROM customer_message WHERE customer_id = :c', ['c' => $id]) === 0, 'mensagens apagadas');
if (isModuleEnabled('iter_marketing')) {
    $sub = $db->fetchRow('SELECT email, status, ip FROM marketing_subscriber WHERE email LIKE \'excluido-s%\' ORDER BY subscriber_id DESC LIMIT 1');
    check($sub['status'] === 'unsubscribed' && $sub['ip'] === null && (int) $db->fetchOne('SELECT COUNT(*) FROM marketing_subscriber WHERE LOWER(email) = :e', ['e' => $email]) === 0,
        'newsletter anonimizada e encerrada', $sub);
    check((int) $db->fetchOne("SELECT COUNT(*) FROM marketing_consent WHERE origin = 'lgpd' AND optin = 0") >= 1, 'saída da newsletter fica no histórico de consentimento (prova)');
    check((int) $db->fetchOne('SELECT COUNT(*) FROM marketing_suggestion WHERE customer_id = :c', ['c' => $id]) === 0, 'sugestão de marketing não enviada apagada');
}
check((int) $db->fetchOne('SELECT COUNT(*) FROM mail_queue WHERE LOWER(to_email) = :e AND template_code = :t AND status = 0', ['e' => $email, 't' => PersonalDataService::MAIL_ERASED]) === 1
    && $mailsBefore === 0, 'e-mail de confirmação na fila, para o endereço antigo');
$request = $db->fetchRow("SELECT * FROM lgpd_request WHERE customer_id = :c AND type = 'erase'", ['c' => $id]);
check($request['status'] === 'done' && $request['origin'] === 'app' && $request['email'] === $email, 'exclusão registrada como atendida, com o e-mail como prova', $request);
check($code(static fn () => $service->erase($id, 'admin')) === 'LGPD_ALREADY_ERASED', 'excluir de novo: LGPD_ALREADY_ERASED');
$after = $service->export($id, Iter\Customer\Model\PersonalData::anonymousEmail($id));
check(!str_contains(json_encode($after['Cadastro do cliente'] ?? [], JSON_UNESCAPED_UNICODE), $taxvat) && str_contains(json_encode($after['Pedidos'] ?? [], JSON_UNESCAPED_UNICODE), $taxvat),
    'depois da exclusão: o cadastro não tem mais o CPF; o pedido ainda tem (prazo fiscal)');

// 13) requests from the admin: validation, close, refuse needs the reason
$requests = new RequestService();
check($code(static fn () => $requests->create(['website_id' => $websiteId, 'email' => 'x', 'type' => 'export', 'origin' => 'admin'])) === 'EMAIL_INVALID', 'e-mail inválido é recusado');
check($code(static fn () => $requests->create(['website_id' => $websiteId, 'email' => $guestEmail, 'type' => 'hack', 'origin' => 'admin'])) === 'INVALID_ARGUMENT', 'tipo desconhecido é recusado');
$rid = $requests->create(['website_id' => $websiteId, 'email' => strtoupper($guestEmail), 'type' => 'erase', 'origin' => 'admin', 'note' => 'Pediu por e-mail']);
$r = $requests->get($rid);
check($r['customer_id'] === null && $r['email'] === $guestEmail && $r['status'] === 'open' && strtotime((string) $r['due_at']) > time() + 14 * 86400, 'solicitação de visitante: aberta, e-mail em minúsculas, prazo de 15 dias', $r);
check($code(static fn () => $requests->close($rid, 'refused', ' ', null)) === 'LGPD_NOTE_REQUIRED', 'recusar sem motivo: LGPD_NOTE_REQUIRED');
$requests->close($rid, 'done', 'Pedidos ficam pelo prazo fiscal', null);
check(str_contains((string) $requests->get($rid)['note'], "Pediu por e-mail\nPedidos ficam") && $code(static fn () => $requests->close($rid, 'done', '', null)) === 'LGPD_REQUEST_CLOSED', 'concluir junta a observação; encerrada não reabre');
check($requests->search(['q' => (string) $rid], 1, 20)['total'] === 1 && $requests->search(['q' => substr($guestEmail, 0, 18)], 1, 20)['total'] >= 1, 'busca por número e por e-mail');

// 14) the screens render
$_GET = ['id' => (string) $rid];
foreach (['requests' => new Iter\Lgpd\View\RequestList(), 'request-view' => new Iter\Lgpd\View\RequestView(), 'request-form' => new Iter\Lgpd\View\RequestForm()] as $tpl => $view) {
    ob_start();
    (static function (array $__data, string $__tpl): void {
        extract($__data);
        include __DIR__ . '/../src/vendor/Iter/Lgpd/view/frontend/templates/' . $__tpl . '.php';
    })($view->getData(), $tpl);
    $html = (string) ob_get_clean();
    check(str_contains($html, '<h2>'), "tela {$tpl} monta");
}

// ---------------------------------------------------------------------------------------------------------
// delivery 3: the privacy policy, its versions and acceptance
use Iter\Lgpd\Model\PolicyService;

$policies = new PolicyService();
$db->delete('lgpd_policy', ['website_id = ?' => $websiteId, 'version IS NULL']); // start from no draft (rolled back)
$before = $policies->current($websiteId);
$next = (int) ($before['version'] ?? 0) + 1;
$as(null);
[$data] = $gql('{ privacy(websiteId: "' . $websiteId . '") { version text dpo { email } operators { name international } cookieBannerText cookieCategories { code required } } }');
check($data['privacy']['version'] === ($before['version'] ?? null) && count($data['privacy']['cookieCategories']) === 3
    && $data['privacy']['cookieCategories'][0] === ['code' => 'necessary', 'required' => true] && $data['privacy']['operators'] !== [], 'privacy sem login: versão em vigor, 3 categorias (necessários obrigatório), operadores', $data);
[$data] = $gql('{ privacy(websiteId: "' . $websiteId . '") { operators { name country } } }');
check(!str_contains(json_encode($data, JSON_UNESCAPED_UNICODE), 'conforme o provedor') && !str_contains(json_encode($data, JSON_UNESCAPED_UNICODE), 'Configurações →'), 'privacy: operadores sem jargão do painel', $data);
check($gql('{ privacy(websiteId: "32000") { version } }')[0]['privacy'] === null, 'website desconhecido: privacy null');
Config::save(['lgpd/dpo/name' => 'Encarregada Teste', 'lgpd/dpo/email' => 'dpo@example.com'], $websiteId); // website scope: a website's own value wins over the default
check($gql('{ privacy(websiteId: "' . $websiteId . '") { dpo { name email } } }')[0]['privacy']['dpo'] === ['name' => 'Encarregada Teste', 'email' => 'dpo@example.com'], 'encarregado das Configurações na API');

check($code(static fn () => $policies->publish($websiteId, "  \n ", null)) === 'POLICY_EMPTY', 'publicar texto vazio: POLICY_EMPTY');
$policies->saveDraft($websiteId, "Rascunho 1\r\n", null);
$policies->saveDraft($websiteId, "Rascunho 2\r\n\r\nParágrafo", null);
check($policies->draft($websiteId) === ['content' => "Rascunho 2\n\nParágrafo", 'updated_at' => $policies->draft($websiteId)['updated_at'], 'is_draft' => true]
    && (int) $db->fetchOne('SELECT COUNT(*) FROM lgpd_policy WHERE website_id = :w AND version IS NULL', ['w' => $websiteId]) === 1, 'rascunho: um só por website, CRLF vira LF');
check(($policies->current($websiteId)['version'] ?? null) === ($before['version'] ?? null), 'rascunho não muda a versão em vigor');
$v1 = $policies->publish($websiteId, "Política A\n\nTexto.", null);
check($v1 === $next && $policies->current($websiteId)['content'] === "Política A\n\nTexto." && !$policies->draft($websiteId)['is_draft'], "publicar: versão {$next}, rascunho some");
check($code(static fn () => $policies->publish($websiteId, "Política A\n\nTexto.\n", null)) === 'POLICY_UNCHANGED', 'publicar o mesmo texto: POLICY_UNCHANGED');
$frozen = null;
try {
    $db->savepoint(static fn () => $db->update('lgpd_policy', ['content' => 'mudei'], ['website_id = ?' => $websiteId, 'version = ?' => $v1]));
} catch (PDOException $e) {
    $frozen = $e;
}
check($frozen !== null && $policies->current($websiteId)['content'] === "Política A\n\nTexto.", 'versão publicada não pode ser alterada (trigger no banco)');

// acceptance: a fresh customer
$ana2 = (int) (new CustomerService())->create(['website_id' => $websiteId, 'group_id' => (int) $db->fetchOne('SELECT customer_group_id FROM customer_group ORDER BY customer_group_id LIMIT 1'),
    'email' => 'lgpd-aceite-' . bin2hex(random_bytes(4)) . '@example.com', 'firstname' => 'Bia', 'lastname' => 'Aceite', 'customer_type' => 'pf', 'taxvat' => $cpf()], [], null, false)->getId();
$as($ana2);
check($gql('{ me { privacyVersion } }')[0]['me']['privacyVersion'] === null, 'cliente novo: privacyVersion null');
check($gql('mutation { acceptPrivacy(version: ' . ($v1 + 5) . ') }')[1] === 'POLICY_VERSION_OUTDATED', 'aceitar versão que não é a em vigor: POLICY_VERSION_OUTDATED');
$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
check($gql('mutation { acceptPrivacy(version: ' . $v1 . ') }')[0]['acceptPrivacy'] === true && $gql('mutation { acceptPrivacy(version: ' . $v1 . ') }')[0]['acceptPrivacy'] === true, 'acceptPrivacy: aceita, e aceitar de novo não falha');
$accept = $db->fetchRow('SELECT ip, origin FROM lgpd_policy_accept WHERE customer_id = :c', ['c' => $ana2]);
check((int) $db->fetchOne('SELECT COUNT(*) FROM lgpd_policy_accept WHERE customer_id = :c', ['c' => $ana2]) === 1 && $accept === ['ip' => '203.0.113.0', 'origin' => 'app'], 'um aceite só, com IP truncado e origem', $accept);
check($gql('{ me { privacyVersion } }')[0]['me']['privacyVersion'] === $v1, "privacyVersion = {$v1} depois do aceite");
$v2 = $policies->publish($websiteId, "Política B", null);
[$data] = $gql('{ me { privacyVersion } privacy(websiteId: "' . $websiteId . '") { version } }');
check($data['me']['privacyVersion'] === $v1 && $data['privacy']['version'] === $v2, 'política nova: privacyVersion fica na antiga, privacy.version na nova (o app pede o aceite)');
check($policies->versions($websiteId)[0]['version'] === $v2 && (int) $policies->versions($websiteId)[1]['accepts'] === 1, 'lista de versões com a contagem de aceites');
check(PolicyService::truncatedIp('2001:db8:abcd:12:1::5') === '2001:db8:abcd::' && PolicyService::truncatedIp('x') === null, 'IPv6 truncado nos 3 primeiros grupos; inválido vira null');
$copy = $service->export($ana2, (string) $db->fetchOne('SELECT email FROM customer_flat WHERE customer_id = :c', ['c' => $ana2]));
check(str_contains(json_encode($copy['Aceite da política, aviso de cookies e solicitações LGPD'] ?? [], JSON_UNESCAPED_UNICODE), 'Versão ' . $v1), 'a cópia dos dados traz o aceite da política');

// ---------------------------------------------------------------------------------------------------------
// delivery 4: the cookie notice's choice, recorded as proof
$visitor = 'selftest-' . bin2hex(random_bytes(8));
$save = static fn (string $args) => $gql('mutation { saveCookieConsent(websiteId: "' . $websiteId . '", ' . $args . ') }');
$as(null);
check($save('visitorId: "' . $visitor . '", categories: ["statistics"], policyVersion: ' . $v2)[0]['saveCookieConsent'] === true, 'saveCookieConsent sem login');
$row = $db->fetchRow('SELECT categories, policy_version, customer_id, ip FROM lgpd_cookie_consent WHERE visitor_id = :v', ['v' => $visitor]);
check($row === ['categories' => 'necessary,statistics', 'policy_version' => $v2, 'customer_id' => null, 'ip' => '203.0.113.0'], 'necessários entram sempre; versão e IP truncado gravados', $row);
check($save('visitorId: "' . $visitor . '", categories: ["ads"]')[1] === 'INVALID_INPUT', 'categoria desconhecida: INVALID_INPUT');
check($save('visitorId: "curto", categories: []')[1] === 'INVALID_INPUT', 'visitorId inválido: INVALID_INPUT');
check($save('visitorId: "' . $visitor . '", categories: [], policyVersion: ' . ($v2 + 9))[1] === 'INVALID_INPUT', 'versão da política desconhecida: INVALID_INPUT');
check($gql('mutation { saveCookieConsent(websiteId: "32000", visitorId: "' . $visitor . '", categories: []) }')[1] === 'INVALID_INPUT', 'website desconhecido: INVALID_INPUT');
$as($ana2);
check($save('visitorId: "' . $visitor . '", categories: []')[0]['saveCookieConsent'] === true, 'saveCookieConsent com login');
$rows = $db->fetchAll('SELECT categories, policy_version, customer_id FROM lgpd_cookie_consent WHERE visitor_id = :v ORDER BY consent_id', ['v' => $visitor]);
check(count($rows) === 2 && $rows[1] === ['categories' => 'necessary', 'policy_version' => null, 'customer_id' => $ana2], 'nova escolha é registro novo, ligado ao cliente logado', $rows);
$copy = $service->export($ana2, (string) $db->fetchOne('SELECT email FROM customer_flat WHERE customer_id = :c', ['c' => $ana2]));
check(str_contains(json_encode($copy['Aceite da política, aviso de cookies e solicitações LGPD']['Aviso de cookies'] ?? [], JSON_UNESCAPED_UNICODE), 'Necessários em '), 'a cópia dos dados traz a escolha de cookies');
Config::save(['lgpd/cookies/per_minute' => '1']);
$as(null);
$_SERVER['REMOTE_ADDR'] = '198.51.100.' . random_int(1, 254);
check($save('visitorId: "' . $visitor . '", categories: []')[1] === null && $save('visitorId: "' . $visitor . '", categories: []')[1] === 'TOO_MANY_REQUESTS', 'limite por IP: TOO_MANY_REQUESTS');

// the admin screen renders
$_GET = ['website_id' => (string) $websiteId];
ob_start();
(static function (array $__data): void {
    extract($__data);
    include __DIR__ . '/../src/vendor/Iter/Lgpd/view/frontend/templates/policy.php';
})((new Iter\Lgpd\View\PolicyEditor())->getData());
$html = (string) ob_get_clean();
check(str_contains($html, 'Versão ' . $v2 . ', publicada') && str_contains($html, 'Publicar versão ' . ($v2 + 1)) && str_contains($html, 'Política B') && str_contains($html, 'dpo@example.com'), 'tela Política: em vigor, próximo número, texto e encarregado');

// ---------------------------------------------------------------------------------------------------------
// delivery 5: retention (purge), each module by Configurações → LGPD → Prazos de guarda
$ago = static fn (string $when) => date('Y-m-d H:i:s', strtotime($when));
$tag = bin2hex(random_bytes(4));
$mkOrder = static function (string $suffix, string $status, string $created) use ($db, $websiteId, $cpf, $tag): int {
    $oid = (int) $db->insert('sales_order', ['increment_id' => "LGPDP{$suffix}-{$tag}", 'website_id' => $websiteId, 'email' => "purge-{$suffix}-{$tag}@example.com", 'firstname' => 'Paula',
        'lastname' => 'Antiga', 'taxvat' => $cpf(), 'status' => $status, 'subtotal' => 7, 'grand_total' => 7, 'created_at' => $created])['order_id'];
    $db->insert('sales_order_address', ['order_id' => $oid, 'type' => 'shipping', 'postcode' => '01310100', 'street' => 'Rua Velha', 'number' => '9', 'district' => 'Centro',
        'city' => 'São Paulo', 'region' => 'SP', 'recipient' => 'Paula Antiga', 'telephone' => '11999990000']);

    return $oid;
};
$oldOrder = $mkOrder('a', 'complete', $ago('-6 years'));
$newOrder = $mkOrder('b', 'complete', $ago('-4 years'));
$oldOpen = $mkOrder('c', 'processing', $ago('-6 years'));
$sub = static fn (string $status, string $created, ?string $left = null) => (int) $db->insert('marketing_subscriber', ['website_id' => $websiteId, 'email' => "sub-{$status}-" . bin2hex(random_bytes(3)) . "@example.com",
    'status' => $status, 'created_at' => $created, 'unsubscribed_at' => $left])['subscriber_id'];
$hasMarketing = isModuleEnabled('iter_marketing');
if ($hasMarketing) {
    $pendingOld = $sub('pending', $ago('-10 days'));
    $pendingNew = $sub('pending', $ago('-2 days'));
    $leftOld = $sub('unsubscribed', $ago('-7 years'), $ago('-6 years'));
    $leftNew = $sub('unsubscribed', $ago('-2 years'), $ago('-1 year'));
    $db->insert('marketing_consent', ['subscriber_id' => $leftOld, 'optin' => 1, 'origin' => 'newsletter']);
}
// a customer erased 6 years ago and one erased last year, with consent proof
$gone = [];
foreach (['-6 years', '-1 year'] as $when) {
    $cid = (int) (new CustomerService())->create(['website_id' => $websiteId, 'group_id' => (int) $db->fetchOne('SELECT customer_group_id FROM customer_group ORDER BY customer_group_id LIMIT 1'),
        'email' => 'purge-cli-' . bin2hex(random_bytes(4)) . '@example.com', 'firstname' => 'Ex', 'lastname' => 'Cliente', 'customer_type' => 'pf', 'taxvat' => $cpf()], [], null, false)->getId();
    $db->update('customer_entity', ['anonymized_at' => $ago($when)], ['customer_id = ?' => $cid]);
    $db->insert('lgpd_policy_accept', ['customer_id' => $cid, 'policy_id' => (int) $policies->current($websiteId)['policy_id'], 'origin' => 'app']);
    $hasMarketing && $db->insert('marketing_consent', ['customer_id' => $cid, 'optin' => 1, 'origin' => 'app']);
    $gone[] = $cid;
}
$cookieOld = 'purge-old-' . $tag . '-xxxxxx';
$cookieNew = 'purge-new-' . $tag . '-xxxxxx';
$db->insert('lgpd_cookie_consent', ['website_id' => $websiteId, 'visitor_id' => $cookieOld, 'categories' => 'necessary', 'created_at' => $ago('-6 years')]);
$db->insert('lgpd_cookie_consent', ['website_id' => $websiteId, 'visitor_id' => $cookieNew, 'categories' => 'necessary', 'created_at' => $ago('-4 years')]);
$reqs = [];
foreach ([['done', '-6 years'], ['open', '-6 years'], ['done', '-4 years']] as [$status, $when]) {
    $rid = $requests->create(['website_id' => $websiteId, 'email' => "purge-req-{$tag}@example.com", 'type' => 'export', 'origin' => 'admin', 'status' => $status]);
    $db->update('lgpd_request', ['created_at' => $ago($when)], ['request_id = ?' => $rid]);
    $reqs[] = $rid;
}

Config::save(['lgpd/retention/order_years' => '0', 'lgpd/retention/newsletter_pending_days' => '0', 'lgpd/retention/consent_years' => '0', 'lgpd/retention/request_years' => '0']);
check(array_sum($service->purge()) === 0 && $db->fetchOne('SELECT email FROM sales_order WHERE order_id = :o', ['o' => $oldOrder]) === "purge-a-{$tag}@example.com", 'prazos em 0: nada é apagado nem anonimizado');
Config::save(['lgpd/retention/order_years' => '5', 'lgpd/retention/newsletter_pending_days' => '7', 'lgpd/retention/consent_years' => '5', 'lgpd/retention/request_years' => '5']);
$done = $service->purge();
check(isset($done['Pedidos'], $done['Aceite da política, aviso de cookies e solicitações LGPD']) && (!$hasMarketing || isset($done['E-mails de marketing e newsletter'])), 'purge: um total por módulo com prazo', $done);

$o = $db->fetchRow('SELECT email, firstname, lastname, taxvat, grand_total FROM sales_order WHERE order_id = :o', ['o' => $oldOrder]);
$a = $db->fetchRow('SELECT recipient, telephone, street, number, district, postcode, city, region FROM sales_order_address WHERE order_id = :o', ['o' => $oldOrder]);
check($o === ['email' => "excluido-pedido-{$oldOrder}@anonimo.invalid", 'firstname' => 'Cliente', 'lastname' => 'excluído', 'taxvat' => '', 'grand_total' => $o['grand_total']] && (float) $o['grand_total'] === 7.0
    && $a === ['recipient' => null, 'telephone' => null, 'street' => null, 'number' => null, 'district' => null, 'postcode' => null, 'city' => 'São Paulo', 'region' => 'SP'],
    'pedido de 6 anos: sem dados do comprador; total, cidade e UF ficam', [$o, $a]);
check($db->fetchOne('SELECT email FROM sales_order WHERE order_id = :o', ['o' => $newOrder]) === "purge-b-{$tag}@example.com"
    && $db->fetchOne('SELECT email FROM sales_order WHERE order_id = :o', ['o' => $oldOpen]) === "purge-c-{$tag}@example.com", 'pedido dentro do prazo e pedido em andamento ficam intactos');
check(!$hasMarketing || ($db->fetchCol('SELECT subscriber_id FROM marketing_subscriber WHERE subscriber_id IN (' . implode(',', [$pendingOld, $pendingNew, $leftOld, $leftNew]) . ') ORDER BY subscriber_id') === [$pendingNew, $leftNew]
    && !$db->fetchOne('SELECT 1 FROM marketing_consent WHERE subscriber_id = :s', ['s' => $leftOld])), 'newsletter: não confirmada antiga e saída há 6 anos apagadas (com o consentimento); as recentes ficam');
check(!$hasMarketing || $db->fetchCol('SELECT customer_id FROM marketing_consent WHERE customer_id IN (' . implode(',', $gone) . ')') === [$gone[1]], 'consentimento de ofertas: só o de quem foi excluído há mais de 5 anos sai');
check($db->fetchCol('SELECT customer_id FROM lgpd_policy_accept WHERE customer_id IN (' . implode(',', $gone) . ')') === [$gone[1]], 'aceite da política: só o de quem foi excluído há mais de 5 anos sai');
check($db->fetchCol('SELECT visitor_id FROM lgpd_cookie_consent WHERE visitor_id IN (:a, :b)', ['a' => $cookieOld, 'b' => $cookieNew]) === [$cookieNew], 'cookies: escolha de 6 anos sai, a de 4 fica');
check($db->fetchCol('SELECT request_id FROM lgpd_request WHERE request_id IN (' . implode(',', $reqs) . ') ORDER BY request_id') === [$reqs[1], $reqs[2]], 'solicitações: atendida de 6 anos sai; aberta e recente ficam');
$again = $service->purge();
check(($again['Pedidos'] ?? -1) === 0, 'rodar de novo: pedido já anonimizado não conta de novo', $again);
check(str_contains(implode(' ', array_column(Inventory::activities(), 'retention_now')), '5 anos (Configurações)') && !Inventory::problems(), 'inventário mostra o prazo em uso, sem problemas', Inventory::problems());

echo "\nTodos os testes passaram.\n";
