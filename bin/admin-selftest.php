<?php

declare(strict_types=1);

/**
 * End-to-end check of the admin against the real DB: starts `php -S`,
 * creates selftest_* users/roles/stores, walks the flows over HTTP and
 * removes everything on exit (saved settings are restored).
 *
 * Uso: php bin/admin-selftest.php
 */

require_once __DIR__ . '/../path.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once FUNCTIONS . 'bootstrap.php';

use Iter\Admin\Model\AdminRole;
use Iter\Admin\Model\AdminUser;
use Iter\Catalog\Model\CategoryService;
use Iter\Catalog\Model\ProductService;
use Iter\Catalog\Model\VariantService;
use Iter\Core\Config;
use Iter\Core\Db\Connection;
use Iter\Core\Log;
use Iter\Core\View\LayoutParser;
use Iter\Core\View\LayoutRenderer;
use Iter\Eav\Form\FormSchemaGenerator;
use Iter\Eav\InputTypeRegistry;
use Iter\Eav\Model\AttributeException;
use Iter\Eav\Model\AttributeService;
use Iter\Eav\Model\AttributeSetService;
use Iter\Eav\Model\ValueService;
use Iter\Customer\Model\AddressService;
use Iter\Customer\Model\CustomerService;
use Iter\Inventory\Model\InventoryException;
use Iter\Inventory\Model\SourceService;
use Iter\Inventory\Model\StockService;
use Iter\Sales\Model\OrderService;
use Iter\Mail\Model\MailException;
use Iter\Mail\Model\MailService;
use Iter\Store\Model\Website;

const PASSWORD = 'Selftest123';

function assertSame($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FALHOU: {$label}\nesperado: " . var_export($expected, true) . "\nobtido:   " . var_export($actual, true) . "\n");
        exit(1);
    }
    echo "OK: {$label}\n";
}

/** One browser: keeps its own cookies (session) between requests. */
class Browser
{
    private CurlHandle $ch;

    public function __construct(private string $baseUrl)
    {
        $this->ch = curl_init();
        curl_setopt_array($this->ch, [CURLOPT_COOKIEFILE => '', CURLOPT_RETURNTRANSFER => true]);
    }

    /** @return array{status: int, location: string, body: string} */
    public function request(string $path, ?array $post = null, ?string $json = null): array
    {
        curl_setopt_array($this->ch, [
            CURLOPT_URL => $this->baseUrl . $path,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => [],
        ]);
        if ($post !== null) {
            // with a CURLFile inside, send multipart: curl wants flat "a[b][c]" keys
            $flat = [];
            $flatten = static function (array $data, string $prefix) use (&$flatten, &$flat): void {
                foreach ($data as $key => $value) {
                    $name = $prefix === '' ? (string) $key : "{$prefix}[{$key}]";
                    is_array($value) ? $flatten($value, $name) : $flat[$name] = $value;
                }
            };
            $flatten($post, '');
            $multipart = array_filter($flat, static fn ($v) => $v instanceof CURLFile) !== [];
            curl_setopt($this->ch, CURLOPT_POSTFIELDS, $multipart ? $flat : http_build_query($post));
        } elseif ($json !== null) {
            curl_setopt_array($this->ch, [CURLOPT_POSTFIELDS => $json, CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
        }
        $body = (string) curl_exec($this->ch);

        return [
            'status' => curl_getinfo($this->ch, CURLINFO_RESPONSE_CODE),
            'location' => (string) preg_replace('#^https?://[^/]+#', '', (string) curl_getinfo($this->ch, CURLINFO_REDIRECT_URL)),
            'body' => $body,
        ];
    }

    /** POSTs a form from $formPage with its form_key; returns [redirect path, flash messages shown there]. */
    public function submit(string $formPage, string $action, array $fields, bool $withKey = true): array
    {
        if ($withKey) {
            preg_match('/name="form_key" value="([0-9a-f]{64})"/', $this->request($formPage)['body'], $m);
            $fields['form_key'] = $m[1] ?? '';
        }
        $location = $this->request($action, $fields)['location'];

        return [$location, $location === '' ? [] : self::messages($this->request($location)['body'])];
    }

    public function login(string $username, string $password = PASSWORD): string
    {
        return $this->submit('/admin/auth/login', '/admin/auth/loginpost', ['username' => $username, 'password' => $password])[0];
    }

    /** @return array<int, string> */
    public static function messages(string $html): array
    {
        preg_match_all('/class="message message-\w+" data-label="\w+">([^<]*)</', $html, $m);

        return array_map(static fn ($text) => html_entity_decode($text, ENT_QUOTES), $m[1]);
    }
}

$pdo = Connection::get()->connect();

// --- setup + guaranteed cleanup ------------------------------------------------
$savedConfig = $pdo->query('SELECT scope, scope_id, path, value FROM core_config_data')->fetchAll(PDO::FETCH_ASSOC);
$port = (static function (): int {
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    return $port;
})();
$logDir = sys_get_temp_dir() . '/selftest_log_' . bin2hex(random_bytes(4)) . '/';
putenv('ITER_LOG_DIR=' . $logDir); // this process and the server log there, not in var/log
$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', dirname(__DIR__) . '/public'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes,
    null,
    ['XDEBUG_MODE' => 'off', 'ITER_LOG_DIR' => $logDir] + getenv()
);

$savedModules = file_get_contents(ETC . 'config.json'); // module on/off tests write it; shared with a running dev server
$savedTemplates = $pdo->query('SELECT code, subject, body_html, body_text FROM mail_template')->fetchAll(PDO::FETCH_ASSOC);
// the shipping tests replace the default website's table rate: it's put back as it was
$savedRates = $pdo->query("SELECT to_regclass('shipping_table_rate') IS NOT NULL")->fetchColumn()
    ? $pdo->query('SELECT website_id, postcode_from, postcode_to, weight_to, price, days FROM shipping_table_rate')->fetchAll(PDO::FETCH_ASSOC) : null;
$savedSequences = $pdo->query('SELECT website_id, last_value FROM sales_order_sequence')->fetchAll(PDO::FETCH_NUM); // the test orders take real numbers
$purge = static function () use ($pdo, $savedConfig, $savedModules, $savedTemplates, $savedRates, $savedSequences): void {
    $pdo->exec("DELETE FROM sales_order WHERE email LIKE '%@selftest.local'"); // before the websites, which they hold
    $pdo->exec('DELETE FROM sales_order_sequence');
    $restoreSequence = $pdo->prepare('INSERT INTO sales_order_sequence (website_id, last_value) VALUES (?, ?)');
    foreach ($savedSequences as $row) {
        $restoreSequence->execute($row);
    }
    if ($savedRates !== null) {
        $pdo->exec('DELETE FROM shipping_table_rate');
        $restoreRate = $pdo->prepare('INSERT INTO shipping_table_rate (website_id, postcode_from, postcode_to, weight_to, price, days) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($savedRates as $rate) {
            $restoreRate->execute(array_values($rate));
        }
    }
    if (file_get_contents(ETC . 'config.json') !== $savedModules) {
        file_put_contents(ETC . 'config.json', $savedModules);
    }
    // before the websites: a customer holds its website
    foreach ($pdo->query("SELECT customer_id FROM customer_flat WHERE email LIKE '%@selftest.local'")->fetchAll(PDO::FETCH_COLUMN) as $customerId) {
        (new CustomerService())->delete((int) $customerId);
    }
    $pdo->exec("DELETE FROM sales_quote WHERE customer_id IS NULL AND email LIKE '%@selftest.local'"); // test guest carts (customers' go with them)
    $pdo->exec("DELETE FROM customer_group WHERE code LIKE 'selftest%'");
    foreach ($pdo->query("SELECT product_id FROM catalog_product WHERE sku LIKE 'selftest%' AND parent_id IS NULL")->fetchAll(PDO::FETCH_COLUMN) as $productId) {
        (new ProductService())->delete((int) $productId);
    }
    // leaves first: a category with children isn't deleted
    do {
        $leaves = $pdo->query("SELECT category_id FROM catalog_category c WHERE url_key LIKE 'selftest%' AND NOT EXISTS (SELECT 1 FROM catalog_category k WHERE k.parent_id = c.category_id)")->fetchAll(PDO::FETCH_COLUMN);
        array_map(static fn ($id) => (new CategoryService())->delete((int) $id), $leaves);
    } while ($leaves);
    $pdo->exec("DELETE FROM inventory_source_item WHERE source_id IN (SELECT source_id FROM inventory_source WHERE code LIKE 'selftest%')");
    $pdo->exec("DELETE FROM inventory_source WHERE code LIKE 'selftest%'");
    $pdo->exec("DELETE FROM eav_attribute_set WHERE code LIKE 'selftest%'");
    $pdo->exec("UPDATE eav_attribute_set SET is_default = 1 WHERE code = 'default' AND entity_type_id NOT IN (SELECT entity_type_id FROM eav_attribute_set WHERE is_default = 1)");
    $pdo->exec("SET iter.allow_system_attribute = 'on'"); // selftest_sys* are system attributes
    $pdo->exec("DELETE FROM eav_attribute WHERE code LIKE 'selftest%'");
    $pdo->exec('RESET iter.allow_system_attribute');
    $pdo->exec("DELETE FROM store WHERE store_name LIKE 'selftest%'");
    $pdo->exec("DELETE FROM store_group WHERE code LIKE 'selftest%'");
    $pdo->exec("DELETE FROM store_website WHERE code LIKE 'selftest%'");
    foreach ($pdo->query("DELETE FROM mail_queue WHERE to_email LIKE '%@selftest.local' RETURNING message_id")->fetchAll(PDO::FETCH_COLUMN) as $messageId) {
        @unlink(MailService::logDir() . $messageId . '.eml');
    }
    $pdo->exec('DELETE FROM mail_template');
    $restoreTemplate = $pdo->prepare('INSERT INTO mail_template (code, subject, body_html, body_text) VALUES (:code, :subject, :body_html, :body_text)');
    foreach ($savedTemplates as $row) {
        $restoreTemplate->execute($row);
    }
    $pdo->exec("DELETE FROM admin_user WHERE username LIKE 'selftest\\_%'");
    $pdo->exec("DELETE FROM admin_role WHERE role_name LIKE 'selftest\\_%'");
    $pdo->exec('DELETE FROM core_config_data');
    $restore = $pdo->prepare('INSERT INTO core_config_data (scope, scope_id, path, value) VALUES (:scope, :scope_id, :path, :value)');
    foreach ($savedConfig as $row) {
        $restore->execute($row);
    }
    // images uploaded in Configurações that no setting points to anymore
    $inUse = array_column($savedConfig, 'value');
    foreach (glob(Config::mediaDir() . 'config/*') ?: [] as $file) {
        in_array('config/' . basename($file), $inUse, true) || unlink($file);
    }
};
register_shutdown_function(static function () use ($purge, $server, $logDir): void {
    proc_terminate($server);
    $purge();
    array_map('unlink', glob($logDir . '{,unit/}*', GLOB_BRACE) ?: []);
    @rmdir($logDir . 'unit');
    @rmdir($logDir);
});
$purge(); // leftovers from an interrupted run

// known settings regardless of what the admin configured
Config::save([
    'admin/security/max_failures' => '2',
    'admin/security/lock_minutes' => '15',
    'admin/security/password_min_length' => '8',
    'admin/security/password_require_alnum' => '1',
    'admin/security/password_history' => '4',
    'admin/security/password_lifetime' => '90',
    'mail/transport' => 'log', // .eml files in var/mail instead of SMTP
    'mail/from_email' => 'loja@selftest.local',
    'mail/from_name' => 'Selftest Loja',
    'mail/limit_per_hour' => '10',
    'mail/queue/max_attempts' => '5',
]);

$makeUser = static function (string $username, array $resources): int {
    $role = (new AdminRole())->setData('role_name', $username);
    $role->setResources($resources)->save();
    $user = (new AdminUser())->addData([
        'username' => $username,
        'email' => $username . '@selftest.local',
        'is_active' => 1,
        'role_id' => (int) $role->getId(),
    ])->setPassword(PASSWORD);
    $user->save();
    return (int) $user->getId();
};
$adminId = $makeUser('selftest_admin', ['Iter_Admin::root']);
$dashId = $makeUser('selftest_dash', ['Iter_Admin::dashboard']);
$victimId = $makeUser('selftest_victim', ['Iter_Admin::dashboard']);

$base = "http://127.0.0.1:{$port}";
for ($i = 0; $i < 50 && @file_get_contents($base . '/admin/auth/login') === false; $i++) {
    usleep(100_000);
}

assertSame($pdo, Connection::get()->connect(), 'connect() devolve sempre a mesma conexão');

// --- login, CSRF, ACL ------------------------------------------------------------
$guest = new Browser($base);
assertSame('/admin/auth/login', $guest->request('/admin/index')['location'], 'sem login, /admin redireciona para o login');
[, $msgs] = $guest->submit('/admin/auth/login', '/admin/auth/loginpost', ['username' => 'selftest_admin', 'password' => PASSWORD], false);
assertSame(['Sua sessão expirou ou o formulário é inválido. Tente novamente.'], $msgs, 'login sem form_key é recusado');
assertSame('/admin/auth/login', $guest->login('selftest_admin', 'errada1234'), 'senha errada volta para o login');

$admin = new Browser($base);
assertSame('/admin/index', $admin->login('selftest_admin'), 'login válido vai para o dashboard');
assertSame(200, $admin->request('/admin/user/index')['status'], 'admin completo abre Usuários');

$dash = new Browser($base);
$dash->login('selftest_dash');
assertSame(403, $dash->request('/admin/user/index')['status'], 'perfil só com Dashboard recebe 403 em Usuários');
assertSame(false, str_contains($dash->request('/admin/index')['body'], '/admin/user/index'), 'menu esconde Usuários de quem não tem acesso');

$admin->submit('/admin/user/index', '/admin/user/delete', ['user_id' => $victimId], false);
assertSame(true, (new AdminUser())->load($victimId)->getId() !== null, 'delete sem form_key não remove o usuário');

// --- lockout (max_failures = 2) -------------------------------------------------------
$attacker = new Browser($base);
$attacker->login('selftest_victim', 'errada1234');
$attacker->login('selftest_victim', 'errada1234');
assertSame('/admin/auth/login', $attacker->login('selftest_victim'), 'após 2 erros a senha certa é recusada (bloqueio)');

// --- configuration screen --------------------------------------------------------------
[, $msgs] = $admin->submit('/admin/config/index', '/admin/config/save', ['config' => ['admin/security/max_failures' => '0']]);
assertSame(['"Tentativas de login antes do bloqueio" deve ser um número inteiro entre 1 e 100.'], $msgs, 'config recusa valor fora da faixa');
[, $msgs] = $admin->submit('/admin/config/index', '/admin/config/save', ['config' => ['admin/security/max_failures' => '3']]);
assertSame(['Configurações salvas.'], $msgs, 'config salva valor válido');
[, $msgs] = $admin->submit('/admin/config/index', '/admin/config/save', ['config' => ['web/base_url' => 'javascript:alert(1)']]);
assertSame(['"Endereço do site (usado nos links enviados por e-mail)" deve ser um endereço http:// ou https://.'], $msgs, 'config recusa URL que não é http(s)');
assertSame('3', $pdo->query("SELECT value FROM core_config_data WHERE path = 'admin/security/max_failures'")->fetchColumn(), 'valor gravado em core_config_data');

// --- password policy ----------------------------------------------------------------------
$changePassword = static fn (Browser $b, string $current, string $new) => $b->submit('/admin/auth/password', '/admin/auth/passwordpost', [
    'current_password' => $current, 'password' => $new, 'password_confirm' => $new,
])[1];
assertSame(['A senha deve ter pelo menos 8 caracteres.'], $changePassword($dash, PASSWORD, 'Ab1'), 'senha curta é recusada');
assertSame(['A senha deve conter letras e números.'], $changePassword($dash, PASSWORD, 'somenteletras'), 'senha sem número é recusada');
assertSame(['A nova senha não pode repetir uma das senhas usadas recentemente.'], $changePassword($dash, PASSWORD, PASSWORD), 'senha repetida é recusada');
assertSame(['Senha alterada com sucesso.'], $changePassword($dash, PASSWORD, 'Nova2026x'), 'senha válida é aceita');

$pdo->exec("UPDATE admin_passwords SET last_updated = last_updated - 91 * 86400 WHERE user_id = {$dashId}");
assertSame('/admin/auth/password', $dash->request('/admin/index')['location'], 'senha expirada força a troca');
$changePassword($dash, 'Nova2026x', 'Outra2026x');
assertSame(200, $dash->request('/admin/index')['status'], 'após a troca o acesso volta');

// --- forgot / reset password (selftest_victim is still locked from above) --------------------
$genericReply = ['Se o e-mail estiver cadastrado, você receberá um link para redefinir a senha.'];
[, $msgs] = $guest->submit('/admin/auth/forgot', '/admin/auth/forgotpost', ['email' => 'ninguem@selftest.local']);
assertSame($genericReply, $msgs, 'esqueci a senha: e-mail desconhecido tem a mesma resposta');
[, $msgs] = $guest->submit('/admin/auth/forgot', '/admin/auth/forgotpost', ['email' => 'selftest_victim@selftest.local']);
assertSame($genericReply, $msgs, 'esqueci a senha: e-mail cadastrado recebe a resposta genérica');
assertSame(64, strlen((string) (new AdminUser())->load($victimId)->getData('rp_token')), 'token gravado só como hash sha256');
$resetMail = $pdo->query("SELECT * FROM mail_queue WHERE to_email = 'selftest_victim@selftest.local' ORDER BY message_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
preg_match('#(/admin/auth/reset\?id=\d+&(?:amp;)?token=[0-9a-f]{64})#', quoted_printable_decode((string) @file_get_contents(MailService::logDir() . $resetMail['message_id'] . '.eml')), $m);
assertSame([1, null, 200], [(int) $resetMail['status'], $resetMail['body_html'], $guest->request(html_entity_decode($m[1] ?? '/x'))['status']], 'e-mail de redefinição enviado na hora, sem corpo guardado, e o link dele abre o formulário');

// the mailed token isn't readable back, so plant a known one
$setToken = static fn (string $createdAt) => $pdo->prepare('UPDATE admin_user SET rp_token = :hash, rp_token_created_at = ' . $createdAt . ' WHERE user_id = :id')
    ->execute(['hash' => hash('sha256', 'selftest-token'), 'id' => $victimId]);
$resetUrl = '/admin/auth/reset?' . http_build_query(['id' => $victimId, 'token' => 'selftest-token']);
$reset = static fn (string $password) => $guest->submit($resetUrl, '/admin/auth/resetpost', [
    'id' => $victimId, 'token' => 'selftest-token', 'password' => $password, 'password_confirm' => $password,
]);

$setToken("NOW() - INTERVAL '3 hours'");
assertSame('/admin/auth/forgot', $guest->request($resetUrl)['location'], 'link expirado é recusado');
$setToken('NOW()');
assertSame('/admin/auth/forgot', $guest->request('/admin/auth/reset?id=' . $victimId . '&token=errado')['location'], 'token errado é recusado');
assertSame(200, $guest->request($resetUrl)['status'], 'link válido abre o formulário');
assertSame([$resetUrl, ['A senha deve conter letras e números.']], $reset('somenteletras'), 'redefinição aplica a regra de formato');
assertSame(['/admin/auth/login', ['Senha redefinida. Entre com a nova senha.']], $reset('Redefinida2026'), 'redefinição válida volta ao login');
assertSame('/admin/index', (new Browser($base))->login('selftest_victim', 'Redefinida2026'), 'nova senha entra e o bloqueio foi liberado');
assertSame('/admin/auth/forgot', $guest->request($resetUrl)['location'], 'link não pode ser usado de novo');

// --- self-lockout guard ------------------------------------------------------------------
[, $msgs] = $admin->submit('/admin/user/index', '/admin/user/delete', ['user_id' => $adminId]);
assertSame(['Você não pode remover o próprio usuário.'], $msgs, 'admin não remove a si mesmo');
$ownRole = (int) (new AdminUser())->load($adminId)->getData('role_id');
[, $msgs] = $admin->submit('/admin/role/index', '/admin/role/save', ['role_id' => $ownRole, 'role_name' => 'selftest_admin', 'resources' => ['Iter_Admin::dashboard']]);
assertSame(['O seu próprio perfil precisa manter acesso a Perfis.'], $msgs, 'admin não tira de si o acesso a Perfis');

// --- website -> group -> store --------------------------------------------------------------
$admin->submit('/admin/website/create', '/admin/website/save', ['code' => 'selftest_w', 'name' => 'Selftest W']);
$websiteId = (int) $pdo->query("SELECT website_id FROM store_website WHERE code = 'selftest_w'")->fetchColumn();
$admin->submit('/admin/group/create', '/admin/group/save', ['website_id' => $websiteId, 'code' => 'selftest_g', 'name' => 'Selftest G']);
$groupId = (int) $pdo->query("SELECT group_id FROM store_group WHERE code = 'selftest_g'")->fetchColumn();
[, $msgs] = $admin->submit('/admin/store/create', '/admin/store/save', ['store_name' => 'selftest loja', 'webview' => 'v', 'theme' => 't', 'group_id' => $groupId]);
assertSame(['Loja criada com sucesso.'], $msgs, 'loja criada dentro do grupo');
[, $msgs] = $admin->submit('/admin/website/index', '/admin/website/delete', ['website_id' => $websiteId]);
assertSame(['Não é possível remover: há lojas, clientes, produtos, categorias ou origens de estoque vinculados a este website.'], $msgs, 'website com lojas não pode ser removido');

$graphql = static fn (Browser $b) => json_decode($b->request('/core/graphql/index', null, json_encode([
    'query' => 'mutation { createStore(storeName: "selftest gql", webview: "v", theme: "t") { id } }',
]))['body'], true);
assertSame('Acesso negado.', $graphql($guest)['errors'][0]['message'] ?? null, 'GraphQL: mutation sem login é negada');
assertSame(true, isset($graphql($admin)['data']['createStore']['id']), 'GraphQL: mutation com admin funciona');
$page = $admin->request('/admin/graphiql/index');
assertSame([200, true], [$page['status'], str_contains($page['body'], "url: '/core/graphql/index'")], 'GraphiQL abre no admin apontando para o endpoint');
assertSame(403, $dash->request('/admin/graphiql/index')['status'], 'GraphiQL exige o recurso Iter_Admin::graphiql');

// --- EAV: input types + attributes -------------------------------------------------------------
$caps = static fn (string $code) => [InputTypeRegistry::get($code)['supports_options'], InputTypeRegistry::get($code)['multiple']];
assertSame([true, false], $caps('select'), 'select: aceita opções, valor único');
assertSame([true, true], $caps('multiselect'), 'multiselect: aceita opções, vários valores');
assertSame([false, false], $caps('text'), 'text: sem opções');
$throws = static function (callable $fn): string {
    try {
        $fn();
    } catch (Throwable $e) {
        return $e instanceof AttributeException || $e instanceof InventoryException || $e instanceof MailException ? $e->errorCode : get_class($e);
    }
    return 'nada';
};
assertSame('InvalidArgumentException', $throws(static fn () => InputTypeRegistry::get('foo')), 'tipo de campo inexistente lança exceção');
assertSame('RuntimeException', $throws(static fn () => InputTypeRegistry::build([
    ['code' => 'x', 'storage_type' => 'varchar'], ['code' => 'x', 'storage_type' => 'varchar'],
])), 'tipo de campo duplicado lança exceção');

$saveAttribute = static fn (array $fields) => $admin->submit('/admin/attribute/create', '/admin/attribute/save', $fields + [
    'entity_type' => 'product', 'label' => $fields['code'] ?? 'Selftest', 'input_type' => 'text',
])[1];
$attributeRow = static fn (string $code) => $pdo->query("SELECT * FROM eav_attribute WHERE code = '{$code}' ORDER BY attribute_id")->fetch(PDO::FETCH_ASSOC);
assertSame(['Atributo criado com sucesso.'], $saveAttribute(['code' => 'selftest_color', 'input_type' => 'select', 'is_system' => '1']), 'atributo select criado pelo admin');
assertSame(['varchar', 0], [$attributeRow('selftest_color')['storage_type'], $attributeRow('selftest_color')['is_system']], 'storage_type vem do tipo; is_system do POST é ignorado');
assertSame(["O tipo de campo 'foo' não está registrado."], $saveAttribute(['code' => 'selftest_foo', 'input_type' => 'foo']), 'tipo de campo inválido é recusado');
assertSame(['Código deve começar com letra e conter apenas a-z, 0-9 e _ (máx. 100).'], $saveAttribute(['code' => '1selftest']), 'código começando com número é recusado');
assertSame(["Já existe um atributo 'selftest_color' nesta entidade."], $saveAttribute(['code' => 'selftest_color']), 'código duplicado na entidade é recusado');
assertSame(['Atributo criado com sucesso.'], $saveAttribute(['code' => 'selftest_color', 'entity_type' => 'customer']), 'mesmo código em outra entidade é aceito');

$service = new AttributeService();
$system = $service->create(['entity_type' => 'product', 'code' => 'selftest_sys', 'label' => 'Sys', 'input_type' => 'text'], true);
$sysId = (int) $system->getId();
assertSame('ATTRIBUTE_SYSTEM_PROTECTED', $throws(static fn () => $service->delete($sysId)), 'atributo do sistema não pode ser removido');
assertSame('ATTRIBUTE_SYSTEM_PROTECTED', $throws(static fn () => $service->update($sysId, ['label' => 'Sys', 'input_type' => 'textarea'])), 'tipo do atributo do sistema não muda');
$service->update($sysId, ['label' => 'Sys novo', 'is_required' => 1]);
assertSame(['Sys novo', 0], [$attributeRow('selftest_sys')['label'], $attributeRow('selftest_sys')['is_required']], 'sistema: nome muda, obrigatório não');
$sysForm = $admin->request('/admin/attribute/edit?id=' . $sysId)['body'];
assertSame([true, true], [str_contains($sysForm, 'Atributo do sistema'), (bool) preg_match('/name="input_type"[^>]* disabled/', $sysForm)], 'form de atributo do sistema trava o tipo');
$newAttributeForm = $admin->request('/admin/attribute/create');
assertSame([200, 1, true], [
    $newAttributeForm['status'],
    substr_count($newAttributeForm['body'], '][value]"'),
    str_contains($newAttributeForm['body'], 'data-add-row="attribute-option-rows"'),
], 'form de novo atributo abre com uma linha de opção e o botão de adicionar');
$colorId = (int) $attributeRow('selftest_color')['attribute_id'];
$service->update($colorId, ['label' => 'C', 'input_type' => 'radio']);
assertSame('radio', $attributeRow('selftest_color')['input_type'], 'troca para tipo com o mesmo armazenamento é aceita');

// options
$optionsOf = static fn (string $code) => $pdo->query("SELECT o.value, o.label, o.sort_order, o.is_default FROM eav_attribute_option o
    JOIN eav_attribute a ON a.attribute_id = o.attribute_id WHERE a.code = '{$code}' ORDER BY o.sort_order")->fetchAll(PDO::FETCH_NUM);
$blankRow = ['value' => '', 'label' => '', 'sort_order' => ''];
assertSame(['Atributo criado com sucesso.'], $saveAttribute(['code' => 'selftest_size', 'input_type' => 'select', 'is_variant' => '1', 'options' => [
    ['value' => 'p', 'label' => 'Pequeno', 'sort_order' => ''], ['value' => 'g', 'label' => '', 'sort_order' => '', 'is_default' => '1'], $blankRow,
]]), 'select criado com opções');
assertSame([['p', 'Pequeno', 10, 0], ['g', 'g', 20, 1]], $optionsOf('selftest_size'), 'opções gravadas; linha vazia ignorada, nome vazio usa o valor');
assertSame(["A opção 'x' aparece mais de uma vez."], $saveAttribute(['code' => 'selftest_dup', 'input_type' => 'select', 'options' => [['value' => 'x'], ['value' => 'x']]]), 'valor de opção repetido é recusado');
assertSame(["Informe o valor da opção 'Sem valor'."], $saveAttribute(['code' => 'selftest_noval', 'input_type' => 'radio', 'options' => [['value' => '', 'label' => 'Sem valor']]]), 'opção sem valor é recusada');
assertSame(["O tipo 'Seleção' aceita só uma opção padrão."], $saveAttribute(['code' => 'selftest_2def', 'input_type' => 'select', 'options' => [['value' => 'a', 'is_default' => '1'], ['value' => 'b', 'is_default' => '1']]]), 'seleção simples aceita só uma opção padrão');
assertSame(['Atributo criado com sucesso.'], $saveAttribute(['code' => 'selftest_multi', 'input_type' => 'multiselect', 'is_variant' => '1', 'options' => [['value' => 'a', 'is_default' => '1'], ['value' => 'b', 'is_default' => '1']]]), 'múltipla seleção aceita várias opções padrão');
assertSame([1, 0], [(int) $attributeRow('selftest_size')['is_variant'], (int) $attributeRow('selftest_multi')['is_variant']], '"Usar na grade" só vale para Seleção');
$saveAttribute(['code' => 'selftest_texto', 'options' => [['value' => 'ignorada']]]);
assertSame([], $optionsOf('selftest_texto'), 'tipo sem opções não grava opções');

$sizeId = (int) $attributeRow('selftest_size')['attribute_id'];
$optionId = static fn (string $value) => $pdo->query("SELECT option_id FROM eav_attribute_option WHERE attribute_id = {$sizeId} AND value = '{$value}'")->fetchColumn();
$pId = $optionId('p');
assertSame(true, str_contains($admin->request('/admin/attribute/edit?id=' . $sizeId)['body'], 'value="Pequeno"'), 'form de edição mostra as opções');
[, $msgs] = $admin->submit('/admin/attribute/edit?id=' . $sizeId, '/admin/attribute/save', [
    'attribute_id' => $sizeId, 'label' => 'Tamanho selftest', 'input_type' => 'select', 'options' => [['value' => 'p', 'label' => 'P', 'sort_order' => '5'], $blankRow],
]);
assertSame(['Atributo atualizado com sucesso.'], $msgs, 'atributo com opções atualizado');
assertSame([[['p', 'P', 5, 0]], $pId], [$optionsOf('selftest_size'), $optionId('p')], 'opção removida some; a mantida muda de nome e conserva o id');
$service->update($sizeId, ['label' => 'Tamanho selftest', 'input_type' => 'text']);
assertSame([], $optionsOf('selftest_size'), 'mudar para tipo sem opções remove as opções');

$sysSelect = (int) $service->create(['entity_type' => 'product', 'code' => 'selftest_sys_sel', 'label' => 'S', 'input_type' => 'select',
    'options' => [['value' => 'a'], ['value' => 'b']]], true)->getId();
assertSame('ATTRIBUTE_SYSTEM_PROTECTED', $throws(static fn () => $service->update($sysSelect, ['label' => 'S', 'options' => [['value' => 'a']]])), 'sistema: opção existente não pode ser removida');
$service->update($sysSelect, ['label' => 'S', 'options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b'], ['value' => 'c']]]);
assertSame(['A', 'b', 'c'], array_column($optionsOf('selftest_sys_sel'), 1), 'sistema: opção nova e nome alterado são aceitos');
$sqlError = static function (string $sql) use ($pdo): string {
    try {
        $pdo->exec($sql);
        return 'nada';
    } catch (PDOException $e) {
        return $e->getCode();
    }
};
assertSame(['23001', '23001', '23001', 'nada'], [
    $sqlError("DELETE FROM eav_attribute WHERE code = 'selftest_sys'"),
    $sqlError("UPDATE eav_attribute SET input_type = 'textarea', storage_type = 'text' WHERE code = 'selftest_sys'"),
    $sqlError("UPDATE eav_attribute SET is_system = 0 WHERE code = 'selftest_sys'"),
    $sqlError("UPDATE eav_attribute SET label = 'Sys SQL' WHERE code = 'selftest_sys'"),
], 'banco: atributo do sistema não é removido nem muda de tipo por SQL; o nome muda');

// names that only differ by case, accents, punctuation, connecting words, word order or plural
$named = static fn (string $code, string $label, string $entity = 'product', array $extra = []) => $throws(static fn () => $service->create(['entity_type' => $entity, 'code' => $code, 'label' => $label, 'input_type' => 'text'] + $extra));
$ruaId = (int) $service->create(['entity_type' => 'product', 'code' => 'selftest_rua', 'label' => 'Rua', 'input_type' => 'text'])->getId();
$service->create(['entity_type' => 'product', 'code' => 'selftest_numero', 'label' => 'Número da casa', 'input_type' => 'text']);
$service->create(['entity_type' => 'category', 'code' => 'selftest_botao', 'label' => 'Botões', 'input_type' => 'text']);
assertSame(
    ['ATTRIBUTE_DUPLICATE', 'ATTRIBUTE_DUPLICATE', 'ATTRIBUTE_DUPLICATE', 'ATTRIBUTE_DUPLICATE', 'ATTRIBUTE_DUPLICATE'],
    [$named('selftest_d1', 'rua'), $named('selftest_d2', 'Numeros das casas'), $named('selftest_d3', 'numero_casa'), $named('selftest_d4', 'botão', 'category'), $named('selftest_d5', 'Casa, número')],
    'atributo com o mesmo nome (maiúsculas, acento, plural, pontuação, ordem) é recusado'
);
assertSame('ATTRIBUTE_DUPLICATE', $throws(static fn () => $service->update($ruaId, ['label' => 'Números da casa', 'input_type' => 'text'])), 'renomear para um nome já usado é recusado');
$service->update($ruaId, ['label' => 'RUA', 'input_type' => 'text']);
assertSame('RUA', $attributeRow('selftest_rua')['label'], 'mudar só maiúsculas do próprio nome é aceito');
try {
    $pdo->exec("INSERT INTO eav_attribute (entity_type_id, code, label, input_type, storage_type) SELECT entity_type_id, 'selftest_rua_sql', 'Ruas', 'text', 'varchar' FROM eav_entity_type WHERE code = 'product'");
    $sqlCode = 'nada';
} catch (PDOException $e) {
    $sqlCode = $e->getCode();
}
assertSame('23505', $sqlCode, 'INSERT direto com nome repetido é recusado pelo banco');
assertSame('nada', $named('selftest_rua_cli', 'Rua', 'customer'), 'mesmo nome em outra entidade é aceito');
assertSame(['ATTRIBUTE_RESERVED', 'ATTRIBUTE_RESERVED'], [$named('selftest_senha', 'Senhas'), $named('website_id', 'Site')], 'nome ou código reservado é recusado');
$service->create(['entity_type' => 'product', 'code' => 'selftest_garantia', 'label' => 'Garantia', 'input_type' => 'text']);
assertSame('ATTRIBUTE_SIMILAR', $named('selftest_garrantia', 'Garrantia'), 'nome parecido (um erro de digitação) pede confirmação');
assertSame(['Atributo criado com sucesso.'], $saveAttribute(['code' => 'selftest_garrantia', 'label' => 'Garrantia', 'confirm_similar' => '1']), 'nome parecido confirmado pelo admin é aceito');
$optionsNamed = static fn (array $labels) => $throws(static fn () => $service->create(['entity_type' => 'product', 'code' => 'selftest_opt' . count($labels), 'label' => 'Opt' . count($labels), 'input_type' => 'select',
    'options' => array_map(static fn ($l) => ['value' => strtolower($l), 'label' => $l], $labels)]));
assertSame(['OPTION_DUPLICATE', 'nada'], [$optionsNamed(['Azul', 'azuis']), $optionsNamed(['P', 'PP', 'G', 'GG'])], 'opções com o mesmo nome são recusadas; P e PP não');

// input types email and cpf_cnpj: validated and stored normalized
$service->create(['entity_type' => 'customer', 'code' => 'selftest_mail', 'label' => 'Mail teste', 'input_type' => 'email']);
$service->create(['entity_type' => 'customer', 'code' => 'selftest_doc', 'label' => 'Documento teste', 'input_type' => 'cpf_cnpj']);
$normalized = static fn (string $code, string $value) => (static function () use ($code, $value) {
    try {
        return (new ValueService())->validate('customer', [$code => $value])[0]['value'];
    } catch (AttributeException $e) {
        return $e->getMessage();
    }
})();
assertSame(
    ['ana@loja.com', "'Mail teste' não é um e-mail válido.", '39053344705', '11222333000181', "'Documento teste' não é um CPF ou CNPJ válido."],
    [$normalized('selftest_mail', ' Ana@Loja.COM '), $normalized('selftest_mail', 'ana@'), $normalized('selftest_doc', '390.533.447-05'), $normalized('selftest_doc', '11.222.333/0001-81'), $normalized('selftest_doc', '111.111.111-11')],
    'tipos e-mail e CPF/CNPJ validam e gravam normalizado'
);

// "Valor único": the value doesn't repeat within the scope (entities here are fake category ids)
$service->create(['entity_type' => 'category', 'code' => 'selftest_ean', 'label' => 'EAN teste', 'input_type' => 'text', 'is_unique' => 1]);
$uniqueWrite = static fn (int $entityId, ?string $value, int $scope = 0) => $throws(static fn () => (new ValueService())->write($entityId, (new ValueService())->validate('category', ['selftest_ean' => $value]), $scope));
assertSame(
    ['nada', 'VALUE_DUPLICATE', 'nada', 'nada', 'nada', 'nada'],
    [$uniqueWrite(900001, 'ABC-1'), $uniqueWrite(900002, ' abc-1 '), $uniqueWrite(900001, 'ABC-1'), $uniqueWrite(900002, 'abc-1', 7), $uniqueWrite(900001, null), $uniqueWrite(900003, 'abc-1')],
    'valor único: repetido (sem diferenciar maiúsculas) é recusado; o mesmo registro regrava; outro escopo aceita; apagar libera'
);
$eanId = (int) $pdo->query("SELECT attribute_id FROM eav_attribute WHERE code = 'selftest_ean'")->fetchColumn();
assertSame('23505', $sqlError("INSERT INTO eav_value_varchar (attribute_id, entity_id, value) VALUES ({$eanId}, 900004, 'xyz')"), 'banco: valor único gravado por SQL sem a chave é recusado');
assertSame('23505', $throws(static fn () => (new \Iter\Eav\Model\Attribute())->getResource()->saveValues(900005, [['attribute_id' => $eanId, 'storage_type' => 'varchar', 'value' => 'ABC-1', 'unique' => 'EAN']])) === 'PDOException' ? '23505' : 'nada', 'banco: dois cadastros simultâneos com o mesmo valor, o índice recusa o segundo');
$loose = (int) $service->create(['entity_type' => 'category', 'code' => 'selftest_loose', 'label' => 'Solto teste', 'input_type' => 'text'])->getId();
(new ValueService())->save('category', 900001, ['selftest_loose' => 'x']);
assertSame('UNIQUE_CHANGE_BLOCKED', $throws(static fn () => $service->update($loose, ['label' => 'Solto teste', 'input_type' => 'text', 'is_unique' => 1])), 'valor único não liga em atributo que já tem valores');
assertSame('0', (string) $service->create(['entity_type' => 'category', 'code' => 'selftest_unq_sel', 'label' => 'Sel unico', 'input_type' => 'select', 'is_unique' => 1])->getData('is_unique'), 'valor único só vale para texto simples');
assertSame('23001', $sqlError("UPDATE eav_attribute SET is_unique = 1 WHERE code = 'selftest_sys'"), 'banco: valor único do atributo do sistema não muda por SQL');

// creating and removing fields: only the Administrador profile (Iter_Admin::root); "Atributos" edits
$makeUser('selftest_attr', ['Iter_Eav::attribute']);
$attrUser = new Browser($base);
$attrUser->login('selftest_attr');
$attrList = $attrUser->request('/admin/attribute/index?q=selftest_rua')['body'];
assertSame([false, false, true], [str_contains($attrList, '+ Novo atributo'), str_contains($attrList, '/admin/attribute/delete'), str_contains($admin->request('/admin/attribute/index?q=selftest_rua')['body'], '/admin/attribute/delete')], 'só o Administrador vê criar e remover');
assertSame('/admin/attribute/index', $attrUser->request('/admin/attribute/create')['location'], 'perfil sem Administrador não abre o novo atributo');
[, $msgs] = $attrUser->submit('/admin/attribute/edit?id=' . $ruaId, '/admin/attribute/save', ['entity_type' => 'product', 'code' => 'selftest_perm', 'label' => 'Perm', 'input_type' => 'text']);
assertSame(['Só o perfil Administrador cria e remove atributos.'], $msgs, 'perfil sem Administrador não cria atributo');
[, $msgs] = $attrUser->submit('/admin/attribute/edit?id=' . $ruaId, '/admin/attribute/delete', ['attribute_id' => $ruaId]);
assertSame(['Só o perfil Administrador cria e remove atributos.'], $msgs, 'perfil sem Administrador não remove atributo');
[, $msgs] = $attrUser->submit('/admin/attribute/edit?id=' . $ruaId, '/admin/attribute/save', ['attribute_id' => $ruaId, 'label' => 'Rua da entrega', 'input_type' => 'text']);
assertSame(['Atributo atualizado com sucesso.'], $msgs, 'perfil "Atributos" edita o nome');
$logWas = Config::get('system/log/enabled');
Config::save(['system/log/enabled' => '1']);
$garrantia = (int) $attributeRow('selftest_garrantia')['attribute_id'];
[, $msgs] = $admin->submit('/admin/attribute/index', '/admin/attribute/delete', ['attribute_id' => $garrantia]);
assertSame(true, str_contains((string) @file_get_contents($logDir . 'iter-' . date('Y-m-d') . '.log'), "Atributo removido: selftest_garrantia (id {$garrantia}) {\"user\":\"selftest_admin\""), 'remover atributo fica no log, com quem removeu');
Config::save(['system/log/enabled' => (string) $logWas]);

for ($i = 1; $i <= 21; $i++) {
    $service->create(['entity_type' => 'category', 'code' => sprintf('selftest_p%02d', $i), 'label' => sprintf('P%02d', $i), 'input_type' => 'text']);
}
$rows = static fn (string $query) => substr_count($admin->request('/admin/attribute/index?' . $query)['body'], '/admin/attribute/edit?id=');
assertSame([20, 1], [$rows('q=selftest_p'), $rows('q=selftest_p&page=2')], 'lista pagina de 20 em 20');
assertSame(2, $rows('q=selftest&is_system=1'), 'filtro de atributos do sistema');
[, $msgs] = $admin->submit('/admin/attribute/index', '/admin/attribute/delete', ['attribute_id' => $colorId]);
assertSame(['Atributo removido com sucesso.'], $msgs, 'atributo personalizado é removido');

// --- EAV: attribute sets and groups -----------------------------------------------------------
$setRow = static fn (string $code) => $pdo->query("SELECT s.* FROM eav_attribute_set s JOIN eav_entity_type et USING (entity_type_id)
    WHERE et.code = 'product' AND s.code = '{$code}'")->fetch(PDO::FETCH_ASSOC);
$saveSet = static fn (array $fields) => $admin->submit('/admin/attributeset/create', '/admin/attributeset/save', $fields + ['entity_type' => 'product', 'label' => 'Selftest']);
[$location, $msgs] = $saveSet(['code' => 'selftest_set']);
$setId = (int) $setRow('selftest_set')['attribute_set_id'];
assertSame(['/admin/attributeset/edit?id=' . $setId, ['Conjunto criado. Agora escolha os atributos.']], [$location, $msgs], 'conjunto criado leva à escolha dos atributos');
assertSame(["Já existe um conjunto 'selftest_set' nesta entidade."], $saveSet(['code' => 'selftest_set'])[1], 'código de conjunto duplicado é recusado');

$attrId = static fn (string $code) => (int) $pdo->query("SELECT attribute_id FROM eav_attribute a JOIN eav_entity_type et USING (entity_type_id) WHERE et.code = 'product' AND a.code = '{$code}'")->fetchColumn();
$service->create(['entity_type' => 'product', 'code' => 'selftest_a1', 'label' => 'A1', 'input_type' => 'text']);
$service->create(['entity_type' => 'product', 'code' => 'selftest_a2', 'label' => 'A2', 'input_type' => 'text']);
$service->create(['entity_type' => 'product', 'code' => 'selftest_a3', 'label' => 'A3', 'input_type' => 'text']);
$form = $admin->request('/admin/attributeset/edit?id=' . $setId)['body'];
assertSame(true, str_contains($form, 'name="items[' . $attrId('selftest_a1') . '][in]"'), 'form do conjunto lista os atributos da entidade');
[, $msgs] = $admin->submit('/admin/attributeset/edit?id=' . $setId, '/admin/attributeset/save', ['attribute_set_id' => $setId, 'label' => 'Selftest', 'items' => [
    $attrId('selftest_a1') => ['in' => '1', 'group' => 'Técnico', 'sort_order' => '30'],
    $attrId('selftest_a2') => ['in' => '1', 'group' => 'Geral', 'sort_order' => '20'],
    $attrId('selftest_a3') => ['in' => '1', 'group' => '', 'sort_order' => '10'],
    $attrId('selftest_sys') => ['group' => 'Geral', 'sort_order' => '1'],
]]);
assertSame(['Conjunto atualizado com sucesso.'], $msgs, 'atributos do conjunto salvos');
$order = static fn () => array_map(static fn ($i) => [$i['code'], $i['group_name']], (new AttributeSetService())->getItems($setId));
assertSame([['selftest_a2', 'Geral'], ['selftest_a1', 'Técnico'], ['selftest_a3', null]], $order(), 'itens por grupo (menor posição) e sem grupo por último; desmarcado fica fora');
// form generation
$service->create(['entity_type' => 'product', 'code' => 'selftest_gen_sel', 'label' => 'Sel', 'input_type' => 'select', 'is_required' => 1,
    'options' => [['value' => 'x', 'label' => 'X'], ['value' => 'y', 'label' => 'Y', 'is_default' => 1]]]);
$setService = new AttributeSetService();
$setService->update($setId, ['label' => 'Selftest', 'items' => [
    $attrId('selftest_a1') => ['in' => 1, 'group' => 'Técnico', 'sort_order' => 30],
    $attrId('selftest_a2') => ['in' => 1, 'group' => 'Geral', 'sort_order' => 20],
    $attrId('selftest_gen_sel') => ['in' => 1, 'group' => 'Geral', 'sort_order' => 25],
    $attrId('selftest_a3') => ['in' => 1, 'group' => '', 'sort_order' => 10],
]]);
$service->update($attrId('selftest_a1'), ['label' => 'A1', 'is_visible' => 0]);
$generator = new FormSchemaGenerator();
$schema = $generator->generate('product', 'selftest_set');
$byName = array_column($schema['fields'], null, 'name');
assertSame(['selftest_a2', 'selftest_gen_sel', 'selftest_a3'], array_keys($byName), 'schema segue a ordem do conjunto e deixa de fora o invisível');
assertSame([[['value' => 'x', 'label' => 'X'], ['value' => 'y', 'label' => 'Y']], 'y', true, 'Geral'],
    [$byName['selftest_gen_sel']['options'], $byName['selftest_gen_sel']['default'], $byName['selftest_gen_sel']['required'], $byName['selftest_gen_sel']['group']], 'campo select traz opções, padrão, obrigatório e grupo');
assertSame(false, isset($byName['selftest_a2']['options']), 'tipo sem opções não traz options');
$custom = $generator->generate('product', 'selftest_set', ['selftest_gen_sel' => ['label' => 'Outro', 'required' => false], 'selftest_a3' => false]);
assertSame([['selftest_a2', 'A2', false], ['selftest_gen_sel', 'Outro', false]], array_map(static fn ($f) => [$f['name'], $f['label'], $f['required']], $custom['fields']), 'configuração do formulário sobrescreve e remove campos');
assertSame('ATTRIBUTE_SET_NOT_FOUND', $throws(static fn () => $generator->generate('product', 'selftest_nada')), 'conjunto inexistente lança exceção');
assertSame((int) $setRow('default')['attribute_set_id'], $generator->generate('product')['attribute_set'], 'sem conjunto usa o padrão da entidade');
$preview = $admin->request('/admin/attributeset/preview?id=' . $setId)['body'];
assertSame([true, true, true, false], [str_contains($preview, '<legend>Geral</legend>'), str_contains($preview, '<option value="y" selected>'),
    (bool) preg_match('/name="selftest_gen_sel"[^>]* required/', $preview), str_contains($preview, 'selftest_a1')], 'pré-visualização renderiza o formulário do conjunto');
$html = (new LayoutRenderer())->render((new LayoutParser())->parse(['f' => ['component' => 'eav/form-fields',
    'data' => ['entity_type' => 'product', 'attribute_set' => 'selftest_set', 'fields' => ['selftest_gen_sel' => false], 'values' => ['selftest_a2' => 'abc']]]]));
assertSame([true, false], [str_contains($html, 'name="selftest_a2" value="abc"'), str_contains($html, 'selftest_gen_sel')], 'componente eav/form-fields configurado pelo layout JSON');

$customerAttr = (int) $pdo->query("SELECT attribute_id FROM eav_attribute WHERE code = 'selftest_color' ORDER BY attribute_id DESC")->fetchColumn();
assertSame('ATTRIBUTE_NOT_FOUND', $throws(static fn () => $setService->update($setId, ['label' => 'S', 'items' => [$customerAttr => ['in' => 1]]])), 'atributo de outra entidade não entra no conjunto');
$service->delete($attrId('selftest_a3'));
assertSame(3, count($order()), 'remover o atributo tira ele do conjunto');

$default = (int) $setRow('default')['attribute_set_id'];
assertSame('ATTRIBUTE_SET_DEFAULT', $throws(static fn () => $setService->delete($default)), 'conjunto padrão não pode ser removido');
$setService->update($default, ['label' => 'Padrão', 'is_default' => 0]);
assertSame(1, (int) $setRow('default')['is_default'], 'desmarcar o padrão não tira o padrão');
$setService->update($setId, ['label' => 'Selftest', 'is_default' => 1]);
assertSame([1, 0], [(int) $setRow('selftest_set')['is_default'], (int) $setRow('default')['is_default']], 'marcar outro conjunto como padrão tira o anterior');
$setService->update($default, ['label' => 'Padrão', 'is_default' => 1]);
[, $msgs] = $admin->submit('/admin/attributeset/index', '/admin/attributeset/delete', ['attribute_set_id' => $setId]);
assertSame(['Conjunto removido com sucesso.'], $msgs, 'conjunto não padrão é removido');

// --- EAV: values -------------------------------------------------------------------------------
$types = ['name' => 'text', 'age' => 'integer', 'price' => 'number', 'ok' => 'checkbox', 'birth' => 'date', 'tags' => 'multiselect', 'size' => 'select'];
foreach ($types as $code => $type) {
    $service->create(['entity_type' => 'customer', 'code' => "selftest_v_{$code}", 'label' => 'Selftest ' . $code, 'input_type' => $type, 'is_required' => $code === 'name' ? 1 : 0,
        'options' => [['value' => 'a'], ['value' => 'b'], ['value' => 'c']]]);
}
$v = static fn (array $values) => array_combine(array_map(static fn ($c) => "selftest_v_{$c}", array_keys($values)), $values);
$values = new ValueService();
$loaded = static function (int $entityId) use ($values): array {
    $all = array_filter($values->load('customer', $entityId), static fn ($c) => str_starts_with($c, 'selftest_v_'), ARRAY_FILTER_USE_KEY);
    ksort($all);
    return $all;
};
$values->save('customer', 999001, $v(['name' => ' Ana ', 'age' => '30', 'price' => '12,50', 'ok' => '1', 'birth' => '1990-05-01', 'tags' => ['c', 'a', 'a', ''], 'size' => 'b']));
$expected = $v(['age' => 30, 'birth' => '1990-05-01 00:00:00', 'name' => 'Ana', 'ok' => true, 'price' => '12.5', 'size' => 'b', 'tags' => ['c', 'a']]);
assertSame($expected, $loaded(999001), 'valores gravados e lidos com o tipo de cada atributo');
assertSame([], $loaded(999002), 'valores são por entidade');
$values->save('customer', 999001, $v(['age' => '', 'ok' => '0']));
assertSame([false, false], [isset($loaded(999001)['selftest_v_age']), $loaded(999001)['selftest_v_ok']], 'valor vazio apaga; outros atributos ficam como estavam');
$valueError = static fn (array $data) => $throws(static fn () => $values->save('customer', 999001, $v($data)));
assertSame(['VALUE_REQUIRED', 'VALUE_INVALID', 'VALUE_INVALID', 'VALUE_INVALID', 'VALUE_INVALID', 'VALUE_INVALID'], [
    $valueError(['name' => '']), $valueError(['age' => '1.5']), $valueError(['price' => 'abc']),
    $valueError(['size' => 'z']), $valueError(['tags' => ['a', 'z']]), $valueError(['birth' => '2026-02-30']),
], 'valores fora do tipo, das opções ou obrigatórios vazios são recusados');
assertSame('ATTRIBUTE_NOT_FOUND', $throws(static fn () => $values->save('customer', 999001, ['selftest_a1' => 'x'])), 'atributo de outra entidade é recusado');
$valueError(['size' => 'c', 'age' => 'x']);
assertSame('b', $loaded(999001)['selftest_v_size'], 'erro em um valor não grava nenhum');

$vId = static fn (string $code) => (int) $pdo->query("SELECT attribute_id FROM eav_attribute WHERE code = 'selftest_v_{$code}'")->fetchColumn();
assertSame('INPUT_TYPE_CHANGE_BLOCKED', $throws(static fn () => $service->update($vId('price'), ['label' => 'Selftest price', 'input_type' => 'integer'])), 'atributo com valores não troca de armazenamento');
$service->update($vId('age'), ['label' => 'Selftest age', 'input_type' => 'text']);
assertSame('varchar', $attributeRow('selftest_v_age')['storage_type'], 'atributo sem valores troca de armazenamento');
assertSame('ATTRIBUTE_HAS_VALUES', $throws(static fn () => $service->delete($vId('size'))), 'atributo com valores não é removido');

$setService->create(['entity_type' => 'customer', 'code' => 'selftest_vset', 'label' => 'V']);
$vSet = (int) $pdo->query("SELECT attribute_set_id FROM eav_attribute_set WHERE code = 'selftest_vset'")->fetchColumn();
$setService->update($vSet, ['label' => 'V', 'items' => array_fill_keys(array_map($vId, array_keys($types)), ['in' => 1])]);
$html = (new LayoutRenderer())->render((new LayoutParser())->parse(['f' => ['component' => 'eav/form-fields',
    'data' => ['entity_type' => 'customer', 'attribute_set' => 'selftest_vset', 'values' => $values->load('customer', 999001)]]]));
assertSame([true, true, true], [str_contains($html, 'value="1990-05-01"'), str_contains($html, '<option value="c" selected>'),
    (bool) preg_match('/name="selftest_v_price" value="12.5"/', $html)], 'valores gravados preenchem o formulário gerado');
$values->deleteEntity('customer', 999001);
assertSame([], $loaded(999001), 'remover a entidade apaga os valores');
$service->delete($vId('size'));

// --- catalog: products -------------------------------------------------------------------------
$productRow = static fn (string $sku) => $pdo->query("SELECT * FROM catalog_product WHERE sku = '{$sku}'")->fetch(PDO::FETCH_ASSOC);
$productValues = static function (string $sku) use ($productRow): array {
    $values = (new ProductService())->getValues((int) $productRow($sku)['product_id']);
    ksort($values); // union order across eav_value_* isn't fixed
    return $values;
};
assertSame(true, str_contains($admin->request('/admin/product/create')['body'], 'name="attributes[price]"'), 'form de produto traz os atributos do conjunto padrão');
// own set: the default one is the user's to edit (extra required attributes would break these checks)
$setService->create(['entity_type' => 'product', 'code' => 'selftest_basico', 'label' => 'Básico']);
$defaultProductSet = (int) $setRow('selftest_basico')['attribute_set_id'];
$setService->update($defaultProductSet, ['label' => 'Básico', 'items' => [$attrId('price') => ['in' => 1], $attrId('description') => ['in' => 1]]]);
$newProduct = $admin->request('/admin/product/create?set=' . $defaultProductSet)['body'];
assertSame([true, true], [str_contains($newProduct, 'name="attributes[price]"'), str_contains($newProduct, 'name="attributes[description]"')], 'form de produto traz os atributos do conjunto');
$defaultWebsite = (new Website())->getResource()->getDefaultId();
$baseCategory = (int) (new CategoryService())->create(['website_id' => $defaultWebsite, 'name' => 'Selftest Base', 'url_key' => 'selftest-base'])->getId(); // a product needs a category
$saveProduct = static fn (array $fields) => $admin->submit('/admin/product/create', '/admin/product/save', $fields + [
    'attribute_set_id' => $defaultProductSet, 'name' => 'Camiseta', 'is_active' => '1', 'website_ids' => [$defaultWebsite], 'category_ids' => [$baseCategory],
])[1];
assertSame(['Escolha ao menos uma categoria para o produto.'], $saveProduct(['sku' => 'selftest-1', 'category_ids' => [], 'attributes' => ['price' => '1']]), 'produto sem categoria é recusado');
assertSame(["'Preço' é obrigatório."], $saveProduct(['sku' => 'selftest-1', 'attributes' => ['price' => '']]), 'atributo obrigatório vazio é recusado');
assertSame(false, $productRow('selftest-1'), 'erro nos atributos não cria o produto');
assertSame(['Produto criado com sucesso.'], $saveProduct(['sku' => 'selftest-1', 'attributes' => ['price' => '49,90', 'description' => 'Algodão']]), 'produto criado pelo admin');
assertSame(['description' => 'Algodão', 'price' => '49.9'], array_intersect_key($productValues('selftest-1'), ['price' => 1, 'description' => 1]), 'preço e descrição gravados como valores EAV');
assertSame(["Já existe um produto com o SKU 'SELFTEST-1'."], $saveProduct(['sku' => 'SELFTEST-1', 'attributes' => ['price' => '1']]), 'SKU repetido (maiúsculas ou não) é recusado');
assertSame(['SKU deve ter até 64 caracteres: letras, números, ponto, hífen e _ (começando por letra ou número).'], $saveProduct(['sku' => 'a b', 'attributes' => ['price' => '1']]), 'SKU com espaço é recusado');

// a product in another set gets that set's fields only
$setService->create(['entity_type' => 'product', 'code' => 'selftest_roupa', 'label' => 'Roupa']);
$roupa = (int) $setRow('selftest_roupa')['attribute_set_id'];
$service->create(['entity_type' => 'product', 'code' => 'selftest_tam', 'label' => 'Tamanho grade', 'input_type' => 'select', 'is_variant' => 1, 'options' => [['value' => 'p'], ['value' => 'm']]]);
$setService->update($roupa, ['label' => 'Roupa', 'items' => [$attrId('price') => ['in' => 1], $attrId('selftest_tam') => ['in' => 1, 'group' => 'Medidas']]]);
$roupaForm = $admin->request('/admin/product/create?set=' . $roupa)['body'];
assertSame([true, false], [str_contains($roupaForm, 'name="attributes[selftest_tam]"'), str_contains($roupaForm, 'name="attributes[description]"')], 'form segue o conjunto escolhido');
$saveProduct(['sku' => 'selftest-2', 'attribute_set_id' => $roupa, 'attributes' => ['price' => '10', 'selftest_tam' => 'm', 'description' => 'fora do conjunto']]);
assertSame(['price' => '10', 'selftest_tam' => 'm'], $productValues('selftest-2'), 'só os atributos do conjunto são gravados');
$p2 = (int) $productRow('selftest-2')['product_id'];
assertSame(true, str_contains($admin->request('/admin/product/edit?id=' . $p2)['body'], '<option value="m" selected>'), 'edição mostra os valores gravados');
[, $msgs] = $admin->submit('/admin/product/edit?id=' . $p2, '/admin/product/save', ['product_id' => $p2, 'attribute_set_id' => $defaultProductSet, 'sku' => 'selftest-2', 'name' => 'Calça', 'website_ids' => [$defaultWebsite], 'category_ids' => [$baseCategory], 'attributes' => ['price' => '12']]);
assertSame([['Produto atualizado com sucesso.'], 'Calça', 0, $roupa, ['price' => '12']], [$msgs, $productRow('selftest-2')['name'], $productRow('selftest-2')['is_active'], (int) $productRow('selftest-2')['attribute_set_id'], $productValues('selftest-2')],
    'edição salva; conjunto não muda; seleção desmarcada é apagada');
$list = $admin->request('/admin/product/index?q=selftest')['body'];
assertSame([2, true], [substr_count($list, '/admin/product/edit?id='), str_contains($list, 'R$ 49,90')], 'lista de produtos com preço');
assertSame(1, substr_count($admin->request('/admin/product/index?q=selftest&is_active=0')['body'], '/admin/product/edit?id='), 'filtro de produtos inativos');
assertSame('ATTRIBUTE_SET_IN_USE', $throws(static fn () => $setService->delete($roupa)), 'conjunto com produtos não é removido');
[, $msgs] = $admin->submit('/admin/product/index', '/admin/product/delete', ['product_id' => $p2]);
assertSame([['Produto removido com sucesso.'], false, []], [$msgs, $productRow('selftest-2'), $values->load('product', $p2)], 'produto removido com os seus valores');

// --- GraphQL: products ------------------------------------------------------------------------
$gql = static function (Browser $b, string $query, array $variables = []): array {
    return json_decode($b->request('/core/graphql/index', null, json_encode(['query' => $query, 'variables' => $variables ?: null]))['body'], true);
};
$deleteGql = 'mutation { deleteProduct(id: "0") }';
assertSame(['UNAUTHENTICATED', 'FORBIDDEN'], [$gql($guest, $deleteGql)['errors'][0]['extensions']['code'] ?? null, $gql($dash, $deleteGql)['errors'][0]['extensions']['code'] ?? null], 'GraphQL: sem login dá UNAUTHENTICATED, sem permissão dá FORBIDDEN');
$deep = '{ categories { ' . str_repeat('children { ', 20) . 'id' . str_repeat(' }', 22);
$deepError = $gql($guest, $deep)['errors'][0] ?? [];
assertSame([true, 'QUERY_LIMIT_EXCEEDED'], [str_starts_with($deepError['message'] ?? '', 'Max query depth should be 15'), $deepError['extensions']['code'] ?? null], 'GraphQL: consulta profunda demais é recusada');
assertSame(['GRAPHQL_VALIDATION_FAILED', 'GRAPHQL_PARSE_FAILED', 'GRAPHQL_VALIDATION_FAILED'], [
    $gql($guest, '{ nada }')['errors'][0]['extensions']['code'] ?? null,
    $gql($guest, '{ categories {')['errors'][0]['extensions']['code'] ?? null,
    $gql($guest, 'query ($p: Int) { products(page: $p) { total } }', ['p' => 'x'])['errors'][0]['extensions']['code'] ?? null,
], 'GraphQL: erros de consulta também trazem o código');
$pdo->exec("INSERT INTO core_config_data (scope, scope_id, path, value) VALUES ('default', 0, 'system/api/introspection', '0') ON CONFLICT (scope, scope_id, path) DO UPDATE SET value = '0'");
assertSame([true, true], [str_contains($gql($guest, '{ __schema { queryType { name } } }')['errors'][0]['message'] ?? '', 'introspection'), isset($gql($guest, '{ categories { id } }')['data'])], 'GraphQL: introspecção desligada nas Configurações');
$pdo->exec("DELETE FROM core_config_data WHERE path = 'system/api/introspection'");
$service->create(['entity_type' => 'product', 'code' => 'selftest_novo', 'label' => 'Novo', 'input_type' => 'checkbox']);
$service->create(['entity_type' => 'product', 'code' => 'selftest_cor', 'label' => 'Cor multipla', 'input_type' => 'multiselect', 'options' => [['value' => 'az', 'label' => 'Azul'], ['value' => 'vm', 'label' => 'Vermelho']]]);
$service->create(['entity_type' => 'product', 'code' => 'selftest_marca', 'label' => 'Marca', 'input_type' => 'select', 'options' => [['value' => 'acme', 'label' => 'ACME Ltda']]]);
$setService->update($defaultProductSet, ['label' => 'Básico', 'items' => [$attrId('price') => ['in' => 1], $attrId('description') => ['in' => 1], $attrId('selftest_novo') => ['in' => 1],
    $attrId('selftest_cor') => ['in' => 1], $attrId('selftest_marca') => ['in' => 1]]]);
$createGql = 'mutation ($sku: String!, $attrs: [ProductAttributeInput!]) { createProduct(sku: $sku, name: "Boné", attributeSetId: "' . $defaultProductSet . '", categoryIds: ["' . $baseCategory . '"], attributes: $attrs) { id sku is_active attributes { code value values url } } }';
$attrsOf = static function (array $product): array {
    $attrs = array_column($product['attributes'], 'value', 'code');
    ksort($attrs);
    return $attrs;
};
assertSame('Acesso negado.', $gql($guest, $createGql, ['sku' => 'selftest-gql'])['errors'][0]['message'] ?? null, 'GraphQL: criar produto sem login é negado');
$r = $gql($admin, $createGql, ['sku' => 'selftest-gql', 'attrs' => [['code' => 'price', 'value' => '19,90'], ['code' => 'selftest_novo', 'value' => '0']]]);
$gqlProduct = $r['data']['createProduct'] ?? [];
assertSame(['price' => '19.9', 'selftest_novo' => '0'], $attrsOf($gqlProduct + ['attributes' => []]), 'GraphQL: produto criado com atributos EAV');
$r = $gql($admin, $createGql, ['sku' => 'selftest-gql2', 'attrs' => [['code' => 'price', 'value' => '1'], ['code' => 'selftest_tam', 'value' => 'm']]]);
assertSame(['ATTRIBUTE_NOT_FOUND', null], [$r['errors'][0]['extensions']['code'] ?? null, $productRow('selftest-gql2') ?: null], 'GraphQL: atributo fora do conjunto é recusado, com o código do erro');
$r = $gql($admin, $createGql, ['sku' => 'selftest-gql2']);
assertSame(["'Preço' é obrigatório.", 'VALUE_REQUIRED'], [$r['errors'][0]['message'] ?? null, $r['errors'][0]['extensions']['code'] ?? null], 'GraphQL: mensagem de negócio chega ao cliente');
$gqlId = (int) ($gqlProduct['id'] ?? 0);
$r = $gql($admin, 'mutation ($id: ID!) { updateProduct(id: $id, name: "Boné azul", isActive: false) { name is_active attributes { code value } } }', ['id' => $gqlId]);
assertSame(['Boné azul', false, '19.9', '0'], [$r['data']['updateProduct']['name'] ?? null, $r['data']['updateProduct']['is_active'] ?? null, $attrsOf($r['data']['updateProduct'])['price'] ?? null, $attrsOf($r['data']['updateProduct'])['selftest_novo'] ?? null],
    'GraphQL: update parcial mantém os atributos omitidos (inclusive Sim/Não = não)');
$r = $gql($admin, 'mutation ($id: ID!, $attrs: [ProductAttributeInput!]) { updateProduct(id: $id, attributes: $attrs) { attributes { code option_label option_labels } } }',
    ['id' => $gqlId, 'attrs' => [['code' => 'selftest_cor', 'values' => ['vm', 'az']], ['code' => 'selftest_marca', 'value' => 'acme']]]);
$labels = array_column($r['data']['updateProduct']['attributes'] ?? [], null, 'code');
assertSame([['Vermelho', 'Azul'], null, 'ACME Ltda', null, null], [$labels['selftest_cor']['option_labels'], $labels['selftest_cor']['option_label'],
    $labels['selftest_marca']['option_label'], $labels['selftest_marca']['option_labels'], $labels['price']['option_label']], 'GraphQL: rótulos das opções (seleção e múltipla seleção)');
$service->update($attrId('selftest_cor'), ['label' => 'Cor multipla', 'input_type' => 'multiselect', 'options' => [['value' => 'az', 'label' => 'Azul']]]);
$labels = array_column($gql($admin, 'query ($id: ID!) { product(id: $id) { attributes { code option_labels } } }', ['id' => $gqlId])['data']['product']['attributes'] ?? [], 'option_labels', 'code');
assertSame(['vm', 'Azul'], $labels['selftest_cor'] ?? null, 'GraphQL: opção removida mostra o valor no lugar do rótulo');
// attribute labels in the API (API-PLAN delivery 9)
$attrLabels = array_column($gql($admin, 'query ($id: ID!) { product(id: $id) { attributes { code label } } }', ['id' => $gqlId])['data']['product']['attributes'] ?? [], 'label', 'code');
assertSame(['Cor multipla', $pdo->query("SELECT label FROM eav_attribute WHERE attribute_id = {$attrId('price')}")->fetchColumn()], [$attrLabels['selftest_cor'] ?? null, $attrLabels['price'] ?? null], 'GraphQL: Product.attributes traz o nome do atributo');
$defs = static fn ($client, string $entity) => array_column($gql($client, '{ attributes(entityType: "' . $entity . '") { code label inputType multiple isRequired isVisible options { value label } } }')['data']['attributes'] ?? [], null, 'code');
$service->update($attrId('selftest_marca'), ['label' => $pdo->query("SELECT label FROM eav_attribute WHERE attribute_id = {$attrId('selftest_marca')}")->fetchColumn(), 'input_type' => '', 'is_visible' => 0]);
[$guestDefs, $adminDefs] = [$defs($guest, 'product'), $defs($admin, 'product')];
$service->update($attrId('selftest_marca'), ['label' => $adminDefs['selftest_marca']['label'] ?? 'x', 'input_type' => '', 'is_visible' => 1]);
assertSame([['Cor multipla', 'multiselect', true, [['value' => 'az', 'label' => 'Azul']]], false, false], [
    [$guestDefs['selftest_cor']['label'] ?? null, $guestDefs['selftest_cor']['inputType'] ?? null, $guestDefs['selftest_cor']['multiple'] ?? null, $guestDefs['selftest_cor']['options'] ?? null],
    isset($guestDefs['selftest_marca']),
    $adminDefs['selftest_marca']['isVisible'] ?? null,
], 'GraphQL attributes(entityType): definição pública com opções; atributo oculto só o admin vê');
assertSame([['CEP', true], 27, 'ENTITY_TYPE_INVALID'], [
    [$defs($guest, 'customer_address')['postcode']['label'] ?? null, $defs($guest, 'customer_address')['postcode']['isRequired'] ?? null],
    count($defs($guest, 'customer_address')['region']['options'] ?? []),
    $gql($guest, '{ attributes(entityType: "nada") { code } }')['errors'][0]['extensions']['code'] ?? null,
], 'GraphQL attributes(entityType): campos do endereço para o formulário da loja; entidade inexistente é recusada');
// storefront filters (API-PLAN delivery 10): "Usar nos filtros da loja" only on option lists and Sim/Não
foreach (['selftest_marca' => 'Marca', 'selftest_novo' => 'Novo'] as $code => $label) {
    $service->update($attrId($code), ['label' => $label, 'input_type' => '', 'is_filterable' => 1]);
}
$filterDescription = $service->create(['entity_type' => 'product', 'code' => 'selftest_obs', 'label' => 'Observação selftest', 'input_type' => 'text', 'is_filterable' => 1]);
$search = static fn (string $args) => $gql($admin, '{ products(search: "selftest-gql"' . $args . ') { total aggregations { attributes { code options { value label count } } price { min max } } } }')['data']['products'] ?? null;
$aggs = array_column($search('')['aggregations']['attributes'] ?? [], 'options', 'code');
assertSame([0, [['value' => 'acme', 'label' => 'ACME Ltda', 'count' => 1]], [['value' => '0', 'label' => 'Não', 'count' => 1]], false],
    [(int) $filterDescription->getData('is_filterable'), $aggs['selftest_marca'] ?? null, $aggs['selftest_novo'] ?? null, isset($aggs['selftest_cor'])],
    'filtros da loja: texto não vira filtro; aggregations contam por opção e Sim/Não, só dos atributos marcados');
assertSame([1, 0, 1, 0, ['min' => 19.9, 'max' => 19.9], 'ATTRIBUTE_NOT_FILTERABLE'], [
    $search(', filters: [{code: "selftest_marca", values: ["acme", "outra"]}]')['total'] ?? null,
    $search(', filters: [{code: "selftest_marca", values: ["outra"]}]')['total'] ?? null,
    $search(', priceTo: 20')['total'] ?? null,
    $search(', priceFrom: 20')['total'] ?? null,
    $search(', priceFrom: 20')['aggregations']['price'] ?? null,
    $gql($admin, '{ products(filters: [{code: "selftest_obs", values: ["x"]}]) { total } }')['errors'][0]['extensions']['code'] ?? null,
], 'filtros da loja: por opção (qualquer uma das escolhidas) e por preço; a faixa de preço ignora o próprio filtro de preço; atributo sem a marca é recusado');
// category mutations (API-PLAN delivery 11)
$catGql = static fn ($client, string $mutation) => $gql($client, 'mutation { ' . $mutation . ' }');
$catFields = '{ id name url_key is_active level parent_id websiteId }';
assertSame('UNAUTHENTICATED', $catGql($guest, 'createCategory(name: "Selftest API") { id }')['errors'][0]['extensions']['code'] ?? null, 'API categoria: sem login é negado');
$apiRoot = $catGql($admin, "createCategory(name: \"Selftest API\", isActive: false) {$catFields}")['data']['createCategory'] ?? [];
$apiChild = $catGql($admin, "createCategory(name: \"Filha\", urlKey: \"selftest-api-filha\", parentId: \"{$apiRoot['id']}\") {$catFields}")['data']['createCategory'] ?? [];
assertSame([['selftest-api', false, 0, (string) $defaultWebsite], [1, $apiRoot['id'], (string) $defaultWebsite], 'URL_KEY_DUPLICATE'], [
    [$apiRoot['url_key'] ?? null, $apiRoot['is_active'] ?? null, $apiRoot['level'] ?? null, $apiRoot['websiteId'] ?? null],
    [$apiChild['level'] ?? null, $apiChild['parent_id'] ?? null, $apiChild['websiteId'] ?? null],
    $catGql($admin, 'createCategory(name: "Selftest API") { id }')['errors'][0]['extensions']['code'] ?? null,
], 'API categoria: cria principal (chave de URL do nome) e subcategoria no website da mãe; chave repetida no website é recusada');
$renamed = $catGql($admin, "updateCategory(id: \"{$apiRoot['id']}\", name: \"Selftest API nova\") {$catFields}")['data']['updateCategory'] ?? [];
assertSame(['Selftest API nova', 'selftest-api', false], [$renamed['name'] ?? null, $renamed['url_key'] ?? null, $renamed['is_active'] ?? null], 'API categoria: update parcial mantém o que não foi enviado');
assertSame(['CATEGORY_PARENT_INVALID', [0, null]], [
    $catGql($admin, "moveCategory(id: \"{$apiRoot['id']}\", parentId: \"{$apiChild['id']}\") { id }")['errors'][0]['extensions']['code'] ?? null,
    array_values(array_intersect_key($catGql($admin, "moveCategory(id: \"{$apiChild['id']}\") {$catFields}")['data']['moveCategory'] ?? [], ['parent_id' => 0, 'level' => 0])),
], 'API categoria: não entra numa subcategoria sua; mover sem parentId vira principal');
$catGql($admin, "moveCategory(id: \"{$apiChild['id']}\", parentId: \"{$apiRoot['id']}\") { id }");
assertSame(['CATEGORY_HAS_CHILDREN', 'CATEGORY_HAS_PRODUCTS', true, true], [
    $catGql($admin, "deleteCategory(id: \"{$apiRoot['id']}\")")['errors'][0]['extensions']['code'] ?? null,
    $catGql($admin, "deleteCategory(id: \"{$baseCategory}\")")['errors'][0]['extensions']['code'] ?? null,
    $catGql($admin, "deleteCategory(id: \"{$apiChild['id']}\")")['data']['deleteCategory'] ?? null,
    $catGql($admin, "deleteCategory(id: \"{$apiRoot['id']}\")")['data']['deleteCategory'] ?? null,
], 'API categoria: não apaga com subcategorias nem com produtos; apaga a folha e depois a mãe');
$read = 'query ($id: ID!) { product(id: $id) { sku } products(search: "selftest-gql") { total items { sku } } }';
assertSame([null, 0], [$gql($guest, $read, ['id' => $gqlId])['data']['product'], $gql($guest, $read, ['id' => $gqlId])['data']['products']['total']], 'GraphQL: visitante não vê produto inativo');
assertSame(['selftest-gql', 1], [$gql($admin, $read, ['id' => $gqlId])['data']['product']['sku'] ?? null, $gql($admin, $read, ['id' => $gqlId])['data']['products']['total'] ?? null], 'GraphQL: admin vê produto inativo');
$bySku = 'query ($sku: String) { product(sku: $sku) { id } }';
assertSame([null, (string) $gqlId], [$gql($guest, $bySku, ['sku' => 'SELFTEST-GQL'])['data']['product'], $gql($admin, $bySku, ['sku' => 'SELFTEST-GQL'])['data']['product']['id'] ?? null], 'GraphQL: busca por SKU ignora maiúsculas e respeita inativo');
$gql($admin, 'mutation ($id: ID!) { updateProduct(id: $id, isActive: true) { id } }', ['id' => $gqlId]);
assertSame([(string) $gqlId, null, 'Informe id ou sku.'], [
    $gql($guest, $bySku, ['sku' => 'selftest-gql'])['data']['product']['id'] ?? null,
    $gql($guest, $bySku, ['sku' => 'selftest-nao-existe'])['data']['product'],
    $gql($guest, 'query { product { id } }')['errors'][0]['message'] ?? null,
], 'GraphQL: busca por SKU; SKU inexistente = null; sem id nem sku = erro');
assertSame(1, $gql($guest, $read, ['id' => $gqlId])['data']['products']['total'], 'GraphQL: visitante vê produto ativo');
assertSame(['az'], $values->load('product', $gqlId)['selftest_cor'] ?? null, 'GraphQL: update parcial descarta opção removida de atributo não enviado (como o form do admin)');
$r = $gql($admin, 'mutation ($id: ID!) { updateProduct(id: $id, attributes: [{code: "selftest_cor", values: ["vm"]}]) { id } }', ['id' => $gqlId]);
assertSame('VALUE_INVALID', $r['errors'][0]['extensions']['code'] ?? null, 'GraphQL: opção inexistente enviada continua dando erro');
$r = $gql($admin, 'mutation ($id: ID!) { deleteProduct(id: $id) }', ['id' => $gqlId]);
assertSame([true, false, []], [$r['data']['deleteProduct'] ?? null, $productRow('selftest-gql'), $values->load('product', $gqlId)], 'GraphQL: produto removido com os valores');

// --- EAV: image attributes ----------------------------------------------------------------------
$scratch = sys_get_temp_dir() . '/iter-selftest-' . getmypid();
@mkdir($scratch);
$img = imagecreatetruecolor(4, 3);
[imagewebp($img, "{$scratch}/a.webp"), imageavif($img, "{$scratch}/a.avif"), imagejpeg($img, "{$scratch}/a.jpg"), imagepng($img, "{$scratch}/a.png"), imagegif($img, "{$scratch}/a.gif")];
file_put_contents("{$scratch}/fake.png", '<?php echo 1;');
assertSame(['image/webp', 'image/avif', 'image/jpeg', 'image/png'], array_keys(InputTypeRegistry::get('image')['accept']), 'tipo imagem aceita WebP, AVIF, JPG e PNG');
$service->create(['entity_type' => 'product', 'code' => 'selftest_foto', 'label' => 'Foto', 'input_type' => 'image']);
$service->create(['entity_type' => 'product', 'code' => 'selftest_capa', 'label' => 'Capa', 'input_type' => 'image', 'is_required' => 1]);
$fotoSet = $setService->create(['entity_type' => 'product', 'code' => 'selftest_fotos', 'label' => 'Fotos']);
$setService->update((int) $fotoSet->getId(), ['label' => 'Fotos', 'items' => [$attrId('price') => ['in' => 1], $attrId('selftest_foto') => ['in' => 1], $attrId('selftest_capa') => ['in' => 1]]]);
$schemaFoto = array_column($generator->generate('product', 'selftest_fotos')['fields'], null, 'name');
assertSame(['image/webp', 'image/avif', 'image/jpeg', 'image/png'], $schemaFoto['selftest_foto']['accept'], 'schema do campo imagem traz os formatos aceitos');
$upload = static fn (string $file) => ['path' => "{$scratch}/{$file}", 'name' => $file];
$productService = new ProductService();
$fileError = static fn (array $attributes) => $throws(static fn () => $productService->create(['attribute_set_id' => $fotoSet->getId(), 'sku' => 'selftest-img', 'name' => 'I', 'website_ids' => [$defaultWebsite], 'category_ids' => [$baseCategory]], $attributes + ['price' => '1']));
assertSame(['VALUE_REQUIRED', 'FILE_TYPE_INVALID', 'FILE_TYPE_INVALID'], [$fileError([]), $fileError(['selftest_capa' => $upload('a.gif')]), $fileError(['selftest_capa' => $upload('fake.png')])],
    'imagem obrigatória, GIF e arquivo que só finge ser PNG são recusados');
assertSame(false, $productRow('selftest-img'), 'imagem recusada não cria o produto');
$media = static fn (string $sku, string $code) => ValueService::mediaDir() . ($productService->getValues((int) $productRow($sku)['product_id'])[$code] ?? 'nada');
foreach (['a.webp' => 'webp', 'a.avif' => 'avif', 'a.jpg' => 'jpg', 'a.png' => 'png'] as $file => $ext) {
    $productService->create(['attribute_set_id' => $fotoSet->getId(), 'sku' => "selftest-img-{$ext}", 'name' => 'I', 'website_ids' => [$defaultWebsite], 'category_ids' => [$baseCategory]], ['price' => '1', 'selftest_capa' => $upload($file), 'selftest_foto' => 'qualquer/caminho.png']);
    $stored = $media("selftest-img-{$ext}", 'selftest_capa');
    assertSame([true, $ext, false], [is_file($stored), pathinfo($stored, PATHINFO_EXTENSION), isset($productService->getValues((int) $productRow("selftest-img-{$ext}")['product_id'])['selftest_foto'])],
        "imagem {$ext} gravada com o formato detectado; texto postado não vira caminho");
}
$imgId = (int) $productRow('selftest-img-png')['product_id'];
$oldCapa = $media('selftest-img-png', 'selftest_capa');
$imgData = ['attribute_set_id' => $fotoSet->getId(), 'sku' => 'selftest-img-png', 'name' => 'I'];
$productService->update($imgId, $imgData, ['price' => '2', 'selftest_foto' => $upload('a.jpg')]);
assertSame([true, true], [is_file($oldCapa), is_file($media('selftest-img-png', 'selftest_foto'))], 'salvar sem enviar mantém a imagem; nova imagem entra');
$productService->update($imgId, $imgData, ['price' => '2', 'selftest_capa' => $upload('a.webp')]);
assertSame([false, true], [is_file($oldCapa), is_file($media('selftest-img-png', 'selftest_capa'))], 'imagem substituída apaga o arquivo antigo');
$foto = $media('selftest-img-png', 'selftest_foto');
assertSame('VALUE_REQUIRED', $throws(static fn () => $productService->update($imgId, $imgData, ['price' => '2', 'selftest_capa' => ['remove' => '1']])), 'imagem obrigatória não pode ser removida');
$productService->update($imgId, $imgData, ['price' => '2', 'selftest_foto' => ['remove' => '1']]);
assertSame(false, is_file($foto), 'remover a imagem apaga o arquivo');
$capa = $media('selftest-img-png', 'selftest_capa');
$productService->delete($imgId);
assertSame(false, is_file($capa), 'remover o produto apaga as imagens');

// through the admin form (real multipart upload)
$imgForm = $admin->request('/admin/product/create?set=' . $fotoSet->getId())['body'];
assertSame([true, true], [str_contains($imgForm, 'enctype="multipart/form-data"'), (bool) preg_match('/type="file" name="attributes\[selftest_capa\]" accept="image\/webp,image\/avif,image\/jpeg,image\/png"\s+required/', $imgForm)], 'form de produto tem campo de imagem');
assertSame(true, str_contains($imgForm, 'data-max-bytes="' . \Iter\Core\Config::uploadMaxBytes() . '"'), 'campo de imagem informa o limite de envio (o navegador reduz a imagem maior)');
assertSame(2, substr_count($imgForm, 'class="eav-file-preview eav-file-placeholder" src="/imagem/logo.png"'), 'campo de imagem vazio mostra o logo em cinza');
$msgs = $admin->submit('/admin/product/create', '/admin/product/save', ['attribute_set_id' => $fotoSet->getId(), 'sku' => 'selftest-up', 'name' => 'U', 'website_ids' => [$defaultWebsite], 'category_ids' => [$baseCategory],
    'attributes' => ['price' => '3', 'selftest_capa' => new CURLFile("{$scratch}/a.avif", 'image/avif', 'capa.avif')]])[1];
assertSame(['Produto criado com sucesso.'], $msgs, 'produto criado com upload de imagem pelo admin');
$upId = (int) $productRow('selftest-up')['product_id'];
$url = ValueService::MEDIA_URL . $productService->getValues($upId)['selftest_capa'];
$upPage = $admin->request('/admin/product/edit?id=' . $upId)['body'];
assertSame([true, 200], [str_contains($upPage, 'src="' . $url . '"'), $admin->request($url)['status']], 'edição mostra a imagem e ela é servida');
assertSame([true, true], [str_contains($upPage, 'class="eav-field eav-field-file" data-field="selftest_capa"'), str_contains($upPage, 'href="' . $url . '" target="_blank" data-image-popup="')], 'imagem vira miniatura que abre no popup');
$gqlImage = array_column($gql($admin, 'query ($id: ID!) { product(id: $id) { attributes { code url } } }', ['id' => $upId])['data']['product']['attributes'] ?? [], 'url', 'code');
ksort($gqlImage);
assertSame(['price' => null, 'selftest_capa' => $url], $gqlImage, 'GraphQL: atributo imagem traz a url; os outros, não');
// image upload through the API: GraphQL multipart request spec (API-PLAN delivery 12)
$upToken = $gql($guest, 'mutation { generateAdminToken(username: "selftest_admin", password: "' . PASSWORD . '") { token } }')['data']['generateAdminToken']['token'] ?? '';
$gqlUpload = static function (string $code, string $file, ?string $token) use ($base, $upId, $scratch): array {
    $ch = curl_init($base . '/core/graphql/index');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $token === null ? [] : ["Authorization: Bearer {$token}"], CURLOPT_POSTFIELDS => [
        'operations' => json_encode(['query' => 'mutation ($id: ID!, $code: String!, $file: Upload!) { uploadProductImage(productId: $id, code: $code, file: $file) { attributes { code url } } }',
            'variables' => ['id' => (string) $upId, 'code' => $code, 'file' => null]]),
        'map' => json_encode(['0' => ['variables.file']]),
        '0' => new CURLFile("{$scratch}/{$file}", 'image/png', $file),
    ]]);
    $body = json_decode((string) curl_exec($ch), true);

    return [curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $body['errors'][0]['extensions']['code'] ?? null, array_column($body['data']['uploadProductImage']['attributes'] ?? [], 'url', 'code')];
};
[$status, , $urls] = $gqlUpload('selftest_foto', 'a.png', $upToken);
assertSame([200, 'png', true, true], [$status, pathinfo((string) ($urls['selftest_foto'] ?? ''), PATHINFO_EXTENSION), is_file(ValueService::mediaDir() . ($productService->getValues($upId)['selftest_foto'] ?? 'nada')), ($urls['selftest_capa'] ?? null) === $url],
    'API: uploadProductImage grava a imagem pelo multipart e mantém as outras');
assertSame([[400, 'UNAUTHENTICATED'], 'ATTRIBUTE_NOT_FOUND', 'FILE_TYPE_INVALID'], [
    array_slice($gqlUpload('selftest_foto', 'a.png', null), 0, 2),
    $gqlUpload('price', 'a.png', $upToken)[1],
    $gqlUpload('selftest_foto', 'fake.png', $upToken)[1],
], 'API: upload sem token é recusado (CSRF); só campo de imagem do conjunto; arquivo que finge ser PNG é recusado');
// friendly URLs: product url_key + route(url) (API-PLAN delivery 13)
$urlProduct = static fn (string $sku, array $extra = []) => $productService->create(['attribute_set_id' => $fotoSet->getId(), 'sku' => $sku, 'name' => 'Selftest URL', 'is_active' => 1, 'website_ids' => [$defaultWebsite], 'category_ids' => [$baseCategory]] + $extra,
    ['price' => '1', 'selftest_capa' => $upload('a.png')]);
$u1 = $urlProduct('selftest-url-1');
$u2 = $urlProduct('selftest-url-2');
$u3 = $urlProduct('selftest-url-3', ['url_key' => 'Selftest Produto Único']);
$baseKey = (string) $pdo->query("SELECT url_key FROM catalog_category WHERE category_id = {$baseCategory}")->fetchColumn();
assertSame(['selftest-url', 'selftest-url-selftest-url-2', 'selftest-produto-unico', 'URL_KEY_DUPLICATE', 'URL_KEY_DUPLICATE', 'URL_KEY_DUPLICATE'], [
    $u1->getData('url_key'), $u2->getData('url_key'), $u3->getData('url_key'),
    $throws(static fn () => $urlProduct('selftest-url-4', ['url_key' => 'selftest-url'])),
    $throws(static fn () => $urlProduct('selftest-url-5', ['url_key' => $baseKey])),
    $throws(static fn () => (new CategoryService())->create(['website_id' => $defaultWebsite, 'name' => 'Selftest URL cat', 'url_key' => 'selftest-produto-unico'])),
], 'URL do produto: vem do nome (com o SKU quando o nome repete) ou digitada; repetida entre produtos ou com uma categoria do website é recusada');
assertSame(true, (bool) preg_match('/name="url_key" maxlength="255" value="selftest-produto-unico"/', $admin->request('/admin/product/edit?id=' . $u3->getId())['body']), 'form de produto mostra a chave de URL');
$productService->update((int) $u3->getId(), ['sku' => 'selftest-url-3', 'name' => 'Outro nome', 'is_active' => 1], ['price' => '1']);
assertSame('selftest-produto-unico', (string) $pdo->query("SELECT url_key FROM catalog_product WHERE product_id = {$u3->getId()}")->fetchColumn(), 'URL do produto: salvar sem a chave mantém a atual');
$routeCat = (int) (new CategoryService())->create(['website_id' => $defaultWebsite, 'name' => 'Selftest rota', 'url_key' => 'selftest-rota', 'is_active' => 1])->getId();
$route = static fn (string $url) => $gql($guest, '{ route(url: "' . $url . '") { type product { sku url_key } category { id } } }')['data']['route'];
assertSame([['type' => 'PRODUCT', 'product' => ['sku' => 'selftest-url-3', 'url_key' => 'selftest-produto-unico'], 'category' => null], ['type' => 'CATEGORY', 'product' => null, 'category' => ['id' => (string) $routeCat]], null], [
    $route('/moda/selftest-produto-unico'), $route('selftest-rota/'), $route('/selftest-nada-aqui'),
], 'route(url): acha o produto pela última parte do endereço, depois a categoria; endereço desconhecido dá null');
assertSame('INPUT_TYPE_CHANGE_BLOCKED', $throws(static fn () => $service->update($attrId('selftest_capa'), ['label' => 'Capa', 'input_type' => 'text'])), 'atributo imagem com valores não vira texto');
array_map('unlink', glob("{$scratch}/*"));
rmdir($scratch);

// --- inventory: sources ---------------------------------------------------------------------------
$sourceService = new SourceService();
$sourceRow = static fn (string $code) => $pdo->query("SELECT * FROM inventory_source WHERE code = '{$code}'")->fetch(PDO::FETCH_ASSOC);
$saveSource = static fn (array $fields) => $admin->submit('/admin/source/create', '/admin/source/save', $fields + ['website_id' => $defaultWebsite, 'name' => 'Depósito', 'is_enabled' => '1'])[1];
assertSame(['Origem criada com sucesso.'], $saveSource(['code' => 'selftest_cd', 'name' => 'CD Recife', 'city' => 'Recife', 'country_code' => 'br']), 'origem criada pelo admin');
assertSame(['Recife', 'BR'], [$sourceRow('selftest_cd')['city'], $sourceRow('selftest_cd')['country_code']], 'origem grava endereço; país em maiúsculas');
assertSame(["Já existe uma origem com o código 'selftest_cd'."], $saveSource(['code' => 'selftest_cd']), 'código de origem duplicado é recusado');
assertSame(['Código deve começar com letra e conter apenas a-z, 0-9 e _ (máx. 64).'], $saveSource(['code' => 'Selftest CD']), 'código de origem inválido é recusado');
$saveSource(['code' => 'selftest_loja', 'name' => 'Loja', 'priority' => '5']);
$cdId = (int) $sourceRow('selftest_cd')['source_id'];
$lojaId = (int) $sourceRow('selftest_loja')['source_id'];
$defaultSource = (int) $sourceRow('default')['source_id'];
assertSame(['SOURCE_DEFAULT_LOCKED', 'SOURCE_DEFAULT_LOCKED'], [
    $throws(static fn () => $sourceService->update($defaultSource, ['name' => 'Origem padrão', 'is_enabled' => 0])),
    $throws(static fn () => $sourceService->delete($defaultSource)),
], 'origem padrão não pode ser desativada nem removida');
$sourceService->update($cdId, ['code' => 'selftest_outro', 'name' => 'CD Recife', 'is_enabled' => 1]);
assertSame(['selftest_cd', null], [$sourceRow('selftest_cd')['code'] ?? null, $sourceRow('selftest_cd')['city']], 'código da origem não muda na edição');
$sourceList = $admin->request('/admin/source/index')['body'];
assertSame([true, true], [str_contains($sourceList, 'CD Recife'), str_contains($admin->request('/admin/index')['body'], '/admin/source/index')], 'lista e menu de origens');

// --- inventory: stock --------------------------------------------------------------------------------
$stock = new StockService();
$estId = (int) $productService->create(['attribute_set_id' => $defaultProductSet, 'sku' => 'selftest-est', 'name' => 'Estoque', 'is_active' => 1, 'website_ids' => [$defaultWebsite], 'category_ids' => [$baseCategory]], ['price' => '1'])->getId();
$estPage = $admin->request('/admin/product/edit?id=' . $estId)['body'];
assertSame([true, true, false], [str_contains($estPage, 'action="/admin/stock/save"'), str_contains($estPage, 'name="stock[selftest_cd][quantity]"'), str_contains($admin->request('/admin/product/create')['body'], '/admin/stock/save')],
    'painel de estoque na edição do produto (não na criação)');
$saveStock = static fn (array $stockFields) => $admin->submit('/admin/product/edit?id=' . $estId, '/admin/stock/save', ['product_id' => $estId, 'stock' => $stockFields])[1];
assertSame(['Estoque atualizado com sucesso.'], $saveStock(['selftest_cd' => ['quantity' => '10,5', 'status' => '1'], 'selftest_loja' => ['quantity' => '2', 'status' => '1'], 'default' => ['quantity' => '0', 'status' => '1']]), 'estoque salvo pelo admin');
assertSame(['12.5', '0'], [$stock->getSalable([$estId])[$estId], $stock->getSalable([999999])[999999]], 'saldo vendável = soma das origens; produto sem estoque = 0');
assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM inventory_source_item WHERE product_id = {$estId} AND source_id = {$defaultSource}")->fetchColumn(), 'quantidade 0 em estoque não grava linha');
assertSame(true, str_contains($admin->request('/admin/product/edit?id=' . $estId)['body'], 'Saldo vendável: <strong>12,5</strong>'), 'painel mostra o saldo sem zeros à direita');
assertSame(true, str_contains($admin->request('/admin/product/index?q=selftest-est')['body'], '<td>12,5</td>'), 'coluna Estoque na lista de produtos');
$saveStock(['selftest_loja' => ['quantity' => '2', 'status' => '0']]);
assertSame(['10.5', '10.5'], [$stock->getSalable([$estId])[$estId], $stock->getItems($estId)[1]['quantity'] ?? null], 'origem "fora de estoque" sai do saldo; origem não enviada mantém a quantidade');
$sourceService->update($cdId, ['name' => 'CD Recife', 'is_enabled' => 0]);
assertSame(['0', '10.5'], [$stock->getSalable([$estId])[$estId], array_column($stock->getItems($estId), 'quantity', 'code')['selftest_cd']], 'origem desativada sai do saldo e mantém a quantidade');
$sourceService->update($cdId, ['name' => 'CD Recife', 'is_enabled' => 1]);
foreach (['-1', 'abc', '1.23456'] as $bad) {
    assertSame(["Quantidade inválida em 'Loja': use um número positivo com até 4 casas decimais."], $saveStock(['selftest_cd' => ['quantity' => '99'], 'selftest_loja' => ['quantity' => $bad]]), "quantidade '{$bad}' é recusada");
}
assertSame('10.5', $stock->getSalable([$estId])[$estId], 'quantidade inválida não grava nenhuma origem');
assertSame('SOURCE_NOT_FOUND', $throws(static fn () => $stock->save($estId, ['selftest_nada' => ['quantity' => '1']])), 'origem inexistente é recusada');
assertSame(['SOURCE_HAS_STOCK', true], [$throws(static fn () => $sourceService->delete($cdId)), (bool) $sourceRow('selftest_cd')], 'origem com estoque não é removida');
$stock->save($estId, ['selftest_cd' => ['quantity' => '0']]);
[, $msgs] = $admin->submit('/admin/source/index', '/admin/source/delete', ['source_id' => $cdId]);
assertSame([['Origem removida com sucesso.'], false], [$msgs, $sourceRow('selftest_cd')], 'origem zerada é removida');
preg_match('/name="form_key" value="([0-9a-f]{64})"/', $dash->request('/admin/auth/password')['body'], $m);
assertSame(403, $dash->request('/admin/stock/save', ['form_key' => $m[1] ?? '', 'product_id' => $estId, 'stock' => ['selftest_loja' => ['quantity' => '1']]])['status'], 'perfil sem Estoque de produtos recebe 403 no save');

// --- inventory: GraphQL --------------------------------------------------------------------------------
$stock->save($estId, ['selftest_loja' => ['quantity' => '3', 'status' => '1']]);
$stockRead = 'query ($sku: String) { product(sku: $sku) { stock { salable_quantity is_in_stock } } }';
assertSame(['salable_quantity' => 3, 'is_in_stock' => true], $gql($guest, $stockRead, ['sku' => 'selftest-est'])['data']['product']['stock'] ?? null, 'GraphQL: visitante vê o saldo vendável');
$withSources = 'query { products(search: "selftest-est") { items { stock { sources { code quantity status } } } } }';
assertSame('Acesso negado.', $gql($guest, $withSources)['errors'][0]['message'] ?? null, 'GraphQL: visitante não vê as quantidades por origem');
$bySource = array_column($gql($admin, $withSources)['data']['products']['items'][0]['stock']['sources'] ?? [], null, 'code');
assertSame(['code' => 'selftest_loja', 'quantity' => 3, 'status' => 1], $bySource['selftest_loja'] ?? null, 'GraphQL: admin vê as quantidades por origem');
$setStock = 'mutation ($sources: [ProductStockInput!]!) { setProductStock(sku: "SELFTEST-EST", sources: $sources) { salable_quantity } }';
assertSame('Acesso negado.', $gql($guest, $setStock, ['sources' => [['code' => 'default', 'quantity' => 1]]])['errors'][0]['message'] ?? null, 'GraphQL: setProductStock exige login');
assertSame(4.25, $gql($admin, $setStock, ['sources' => [['code' => 'default', 'quantity' => 1.25]]])['data']['setProductStock']['salable_quantity'] ?? null, 'GraphQL: setProductStock soma à origem não enviada');
assertSame('QUANTITY_INVALID', $gql($admin, $setStock, ['sources' => [['code' => 'default', 'quantity' => -1]]])['errors'][0]['extensions']['code'] ?? null, 'GraphQL: quantidade negativa recusada com o código');
$many = $gql($admin, 'query { products(search: "selftest") { items { sku stock { salable_quantity } } } }')['data']['products']['items'] ?? [];
assertSame(4.25, array_column(array_map(static fn ($p) => ['sku' => $p['sku'], 'q' => $p['stock']['salable_quantity']], $many), 'q', 'sku')['selftest-est'] ?? null, 'GraphQL: saldo na lista de produtos');
assertSame(true, in_array('default', array_column($gql($admin, '{ inventorySources { code } }')['data']['inventorySources'] ?? [], 'code'), true), 'GraphQL: admin lista as origens');
$productService->delete($estId);
assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM inventory_source_item WHERE product_id = {$estId}")->fetchColumn(), 'remover o produto apaga o estoque dele');
$estId = (int) $productService->create(['attribute_set_id' => $defaultProductSet, 'sku' => 'selftest-est', 'name' => 'Estoque', 'is_active' => 1, 'website_ids' => [$defaultWebsite], 'category_ids' => [$baseCategory]], ['price' => '1'])->getId();
$stock->save($estId, ['selftest_loja' => ['quantity' => '7']]);

// --- catalog: product form layout ("Organizar tela") -----------------------------------------------
$blocksOf = static function (string $html): array {
    preg_match_all('/class="product-block( is-wide)?" data-block="([^"]*)"/', $html, $m);
    return array_map(static fn ($id, $wide) => $id . ($wide ? ' (largura total)' : ''), $m[2], $m[1]);
};
$layoutPost = static function (Browser $b, string $action, array $fields = []) use ($estId): array {
    preg_match('/data-form-key="([0-9a-f]{64})"/', $b->request('/admin/product/edit?id=' . $estId)['body'], $m);
    $r = $b->request($action, $fields + ['form_key' => $m[1] ?? '']);
    return [$r['status'], json_decode($r['body'], true)['message'] ?? $r['body']];
};
$estPage = $admin->request('/admin/product/edit?id=' . $estId)['body'];
$defaultBlocks = $blocksOf($estPage);
assertSame(['base', 'categories', 'group:', 'stock'], array_values(array_intersect($defaultBlocks, ['base', 'categories', 'group:', 'stock'])), 'form de produto em blocos, na ordem padrão, com o estoque como bloco');
assertSame([true, true, true], [str_contains($estPage, '<form id="product-form"'), str_contains($estPage, 'form="product-form" type="text" name="sku"'), str_contains($estPage, '<input form="product-form" type="number" name="attributes[price]"')], 'campos fora do <form> apontam para ele com form=');
$layout = ['blocks' => [['id' => 'stock', 'wide' => false], ['id' => 'group:', 'wide' => true], ['id' => 'group:Medidas', 'wide' => false]], 'fields' => ['group:' => ['description', 'price']], 'columns' => 3];
assertSame([200, 'Layout salvo.'], $layoutPost($admin, '/admin/productlayout/save', ['layout' => json_encode($layout)]), 'layout salvo pelo admin');
$estPage = $admin->request('/admin/product/edit?id=' . $estId)['body'];
assertSame(['stock', 'group: (largura total)', 'base', 'websites', 'categories'], array_slice($blocksOf($estPage), 0, 5), 'form segue o layout salvo; blocos fora dele vão para o fim; grupo de outro conjunto é ignorado');
assertSame(true, strpos($estPage, 'data-field="description"') < strpos($estPage, 'data-field="price"'), 'ordem dos campos dentro do grupo segue o layout');
assertSame([true, true], [str_contains($estPage, 'class="product-layout has-columns" style="--layout-columns: 3"'), str_contains($estPage, '<option value="3" selected>')], 'grade usa o número de colunas salvo');
assertSame(['price', 'description'], array_values(array_intersect(array_column((new FormSchemaGenerator())->generate('product', 'selftest_basico')['fields'], 'name'), ['price', 'description'])), 'layout não muda a ordem do conjunto');
$makeUser('selftest_admin2', ['Iter_Admin::root']);
$admin2 = new Browser($base);
$admin2->login('selftest_admin2');
assertSame($defaultBlocks, $blocksOf($admin2->request('/admin/product/edit?id=' . $estId)['body']), 'outro admin continua com o layout padrão');
assertSame([200, 'Layout salvo.'], $layoutPost($admin, '/admin/productlayout/save', ['layout' => json_encode(['blocks' => [['id' => 'base']]])]), 'salvar só os blocos da página');
$estPage = $admin->request('/admin/product/edit?id=' . $estId)['body'];
assertSame([['base', 'stock', 'group: (largura total)'], true], [array_slice($blocksOf($estPage), 0, 3), str_contains($estPage, '--layout-columns: 3')], 'blocos e colunas não enviados mantêm o que estava salvo');
foreach (['não é json', json_encode(['blocks' => [['id' => '<script>']]]), json_encode(['blocks' => [['id' => 'base', 'wide' => 'sim']]]), json_encode(['blocks' => [], 'columns' => 7]), json_encode(['blocks' => [], 'columns' => '3'])] as $bad) {
    assertSame([422, 'Layout inválido.'], $layoutPost($admin, '/admin/productlayout/save', ['layout' => $bad]), 'layout inválido é recusado: ' . substr($bad, 0, 20));
}
assertSame([200, 'Layout padrão restaurado.'], $layoutPost($admin, '/admin/productlayout/reset'), 'restaurar layout padrão');
assertSame($defaultBlocks, $blocksOf($admin->request('/admin/product/edit?id=' . $estId)['body']), 'depois de restaurar, volta a ordem padrão');
preg_match('/name="form_key" value="([0-9a-f]{64})"/', $dash->request('/admin/auth/password')['body'], $m);
assertSame(403, $dash->request('/admin/productlayout/save', ['form_key' => $m[1] ?? '', 'layout' => '{"blocks":[]}'])['status'], 'perfil sem Produtos recebe 403 ao salvar layout');
$makeUser('selftest_produtos', ['Iter_Catalog::product', 'Iter_Inventory::stock']);
$editor = new Browser($base);
$editor->login('selftest_produtos');
$page = $editor->request('/admin/product/edit?id=' . $estId);
assertSame([200, false, $defaultBlocks], [$page['status'], str_contains($page['body'], 'data-layout-toggle'), $blocksOf($page['body'])], 'perfil só com Produtos não vê "Organizar tela" e usa o layout padrão');
assertSame([403, 403], [$layoutPost($editor, '/admin/productlayout/save', ['layout' => '{"blocks":[]}'])[0], $layoutPost($editor, '/admin/productlayout/reset')[0]], 'perfil só com Produtos recebe 403 ao salvar ou restaurar layout');

// --- catalog: categories -----------------------------------------------------------------------------
$categoryRow = static fn (string $urlKey) => $pdo->query("SELECT * FROM catalog_category WHERE url_key = '{$urlKey}'")->fetch(PDO::FETCH_ASSOC);
$saveCategory = static fn (array $fields) => $admin->submit('/admin/category/create', '/admin/category/save', $fields + ['website_id' => $defaultWebsite, 'is_active' => '1'])[1];
assertSame(['Categoria criada com sucesso.'], $saveCategory(['name' => 'Selftest Roupas']), 'categoria criada pelo admin');
$roupasId = (int) ($categoryRow('selftest-roupas')['category_id'] ?? 0);
assertSame(['selftest-roupas', 'higiene-cosmeticos'], [$categoryRow('selftest-roupas')['url_key'] ?? null, CategoryService::slug('Higiene & Cosméticos')], 'chave de URL gerada a partir do nome');
assertSame(["Já existe uma categoria com a chave de URL 'selftest-roupas' neste website."], $saveCategory(['name' => 'Outra', 'url_key' => 'Selftest Roupas']), 'chave de URL duplicada é recusada');
$saveCategory(['name' => 'Camisetas', 'url_key' => 'selftest-camisetas', 'parent_id' => $roupasId, 'position' => '2']);
$saveCategory(['name' => 'Oculta', 'url_key' => 'selftest-oculta', 'parent_id' => $roupasId, 'position' => '1', 'is_active' => '']);
$camisetasId = (int) $categoryRow('selftest-camisetas')['category_id'];
$ocultaId = (int) $categoryRow('selftest-oculta')['category_id'];
assertSame([$roupasId, 0], [(int) $categoryRow('selftest-camisetas')['parent_id'], (int) $categoryRow('selftest-oculta')['is_active']], 'subcategoria criada; inativa grava is_active 0');
assertSame('CATEGORY_PARENT_INVALID', $throws(static fn () => (new CategoryService())->update($roupasId, ['name' => 'Selftest Roupas', 'parent_id' => $camisetasId])), 'categoria não vai para dentro de uma subcategoria sua');
$categoryList = $admin->request('/admin/category/index')['body'];
assertSame([true, true], [strpos($categoryList, 'Oculta') < strpos($categoryList, 'Camisetas'), str_contains($admin->request('/admin/index')['body'], '/admin/category/index')], 'árvore ordenada pela posição; menu de categorias');
assertSame(false, str_contains($admin->request('/admin/category/edit?id=' . $roupasId)['body'], '<option value="' . $camisetasId . '"'), 'form não oferece subcategoria como pai');
[, $msgs] = $admin->submit('/admin/category/index', '/admin/category/delete', ['category_id' => $roupasId]);
assertSame(["A categoria 'Selftest Roupas' tem subcategorias. Remova ou mova as subcategorias antes."], $msgs, 'categoria com subcategorias não é removida');

// products in categories
assertSame(['Produto criado com sucesso.'], $saveProduct(['sku' => 'selftest-cat', 'category_ids' => [$camisetasId, $ocultaId], 'attributes' => ['price' => '5']]), 'produto criado com categorias pelo admin');
$catProduct = (int) $productRow('selftest-cat')['product_id'];
assertSame([$roupasId, $camisetasId, $ocultaId], (new ProductService())->getCategoryIds($catProduct), 'categorias do produto gravadas, com a categoria pai das subcategorias');
assertSame(true, str_contains($admin->request('/admin/product/edit?id=' . $catProduct)['body'], 'name="category_ids[]" data-required-group data-category-parent="' . $roupasId . '" value="' . $camisetasId . '" checked'), 'edição marca as categorias do produto');
assertSame([1, 0], [
    substr_count($admin->request('/admin/product/index?category_id=' . $roupasId)['body'], '/admin/product/edit?id='),
    substr_count($admin->request('/admin/product/index?q=selftest-est&category_id=' . $roupasId)['body'], '/admin/product/edit?id='),
], 'filtro por categoria inclui as subcategorias');
assertSame(true, str_contains($admin->request('/admin/product/index?q=selftest-cat')['body'], '<td>Selftest Roupas, Oculta, Camisetas</td>'), 'lista de produtos mostra as categorias em ordem de árvore');
assertSame(['Categoria não encontrada: 999999.'], $saveProduct(['sku' => 'selftest-cat2', 'category_ids' => [999999], 'attributes' => ['price' => '5']]), 'categoria inexistente é recusada');

$catRead = 'query ($key: String) { category(url_key: $key) { name children { url_key } products { total items { sku categories { url_key } } } } }';
$r = $gql($guest, $catRead, ['key' => 'selftest-roupas'])['data']['category'] ?? null;
assertSame(['name' => 'Selftest Roupas', 'children' => [['url_key' => 'selftest-camisetas']], 'products' => ['total' => 1, 'items' => [['sku' => 'selftest-cat', 'categories' => [['url_key' => 'selftest-roupas'], ['url_key' => 'selftest-camisetas']]]]]], $r, 'GraphQL: visitante vê a árvore e os produtos, sem as inativas');
assertSame([['category' => null], 'selftest-oculta'], [
    $gql($guest, $catRead, ['key' => 'selftest-oculta'])['data'] ?? null,
    $gql($admin, '{ category(id: "' . $ocultaId . '") { url_key } }')['data']['category']['url_key'] ?? null,
], 'GraphQL: categoria inativa só para o admin');
assertSame(true, in_array('selftest-roupas', array_column($gql($guest, '{ categories { url_key } }')['data']['categories'] ?? [], 'url_key'), true), 'GraphQL: categorias principais');
$setCats = static fn (string $args) => $gql($admin, 'mutation { updateProduct(id: "' . $catProduct . '"' . $args . ') { categories { id } } }');
assertSame([[['id' => (string) $roupasId], ['id' => (string) $ocultaId], ['id' => (string) $camisetasId]], 'CATEGORY_REQUIRED', 'CATEGORY_NOT_FOUND'], [
    $setCats(', name: "Com categorias"')['data']['updateProduct']['categories'] ?? null,
    $setCats(', categoryIds: []')['errors'][0]['extensions']['code'] ?? null,
    $setCats(', categoryIds: ["999999"]')['errors'][0]['extensions']['code'] ?? null,
], 'GraphQL: categoryIds omitido mantém, [] e id inexistente são recusados');
assertSame('CATEGORY_REQUIRED', $gql($admin, 'mutation { createProduct(sku: "selftest-semcat", name: "S", attributeSetId: "' . $defaultProductSet . '", attributes: [{code: "price", value: "1"}]) { id } }')['errors'][0]['extensions']['code'] ?? null, 'GraphQL: criar produto sem categoria é recusado');
$setCats(', categoryIds: ["' . $camisetasId . '"]');
[, $msgs] = $admin->submit('/admin/category/index', '/admin/category/delete', ['category_id' => $camisetasId]);
assertSame([["A categoria 'Camisetas' tem produtos. Mova os produtos para outra categoria antes."], true], [$msgs, (bool) $categoryRow('selftest-camisetas')], 'categoria com produtos não é removida');
$setCats(', categoryIds: ["' . $roupasId . '"]');
$admin->submit('/admin/category/index', '/admin/category/delete', ['category_id' => $camisetasId]);
assertSame([false, [$roupasId]], [$categoryRow('selftest-camisetas'), (new ProductService())->getCategoryIds($catProduct)], 'categoria sem produtos é removida');
(new ProductService())->delete($catProduct);
assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM catalog_category_product WHERE product_id = {$catProduct}")->fetchColumn(), 'remover o produto apaga os vínculos');

// --- websites: categories, products and stock sources belong to them -------------------------------
assertSame(['Selecione um website.'], $saveCategory(['name' => 'Sem site', 'url_key' => 'selftest-semsite', 'website_id' => '']), 'categoria sem website é recusada');
$saveCategory(['name' => 'Site W', 'url_key' => 'selftest-site-w', 'website_id' => $websiteId]);
$siteWCat = (int) $categoryRow('selftest-site-w')['category_id'];
$saveCategory(['name' => 'Sub W', 'url_key' => 'selftest-sub-w', 'parent_id' => $siteWCat, 'website_id' => $defaultWebsite]);
assertSame([$websiteId, $defaultWebsite], [(int) $categoryRow('selftest-sub-w')['website_id'], (int) $categoryRow('selftest-base')['website_id']], 'subcategoria fica no website da categoria pai');
assertSame('CATEGORY_WEBSITE_MISMATCH', $throws(static fn () => (new CategoryService())->update($baseCategory, ['name' => 'Selftest Base', 'url_key' => 'selftest-base', 'parent_id' => $siteWCat])), 'categoria não vai para dentro de uma de outro website');
assertSame([['Categoria criada com sucesso.'], ["Já existe uma categoria com a chave de URL 'selftest-site-w' neste website."]], [
    $saveCategory(['name' => 'Site W', 'url_key' => 'selftest-site-w']),
    $saveCategory(['name' => 'Outra', 'url_key' => 'selftest-site-w', 'website_id' => $websiteId]),
], 'chave de URL repetida pode em outro website, não no mesmo');
$byKey = static fn (string $args) => $gql($guest, '{ category(url_key: "selftest-site-w"' . $args . ') { websiteId } }')['data']['category']['websiteId'] ?? null;
assertSame([(string) $defaultWebsite, (string) $websiteId], [$byKey(''), $byKey(', websiteId: "' . $websiteId . '"')], 'GraphQL: category por url_key usa o website padrão, ou o informado');
$webData = ['attribute_set_id' => $defaultProductSet, 'sku' => 'selftest-web', 'name' => 'Web'];
assertSame(['WEBSITE_INVALID', 'CATEGORY_WEBSITE_MISMATCH'], [
    $throws(static fn () => $productService->create($webData + ['website_ids' => [], 'category_ids' => [$baseCategory]], ['price' => '1'])),
    $throws(static fn () => $productService->create($webData + ['website_ids' => [$defaultWebsite], 'category_ids' => [$siteWCat]], ['price' => '1'])),
], 'produto sem website e categoria de website em que o produto não está são recusados');
$emptySite = (int) (new Website())->addData(['code' => 'selftest_vazio', 'name' => 'Vazio'])->save()->getId();
assertSame([
    "O produto não tem categoria no website 'Selftest W'. Escolha uma categoria desse website ou desmarque o website.",
    "O produto não tem categoria no website 'Vazio'. Esse website ainda não tem categorias: cadastre uma em Catálogo → Categorias ou desmarque o website.",
], [
    $saveProduct(['sku' => 'selftest-web', 'website_ids' => [$defaultWebsite, $websiteId], 'attributes' => ['price' => '1']])[0] ?? null,
    $saveProduct(['sku' => 'selftest-web', 'website_ids' => [$defaultWebsite, $emptySite], 'attributes' => ['price' => '1']])[0] ?? null,
], 'produto em website sem nenhuma das categorias dele é recusado, com o aviso');
(new Website())->setId($emptySite)->delete();
$webId = (int) $productService->create($webData + ['website_ids' => [$defaultWebsite, $websiteId], 'category_ids' => [$baseCategory, $siteWCat]], ['price' => '1'])->getId();
assertSame('CATEGORY_WEBSITE_MISMATCH', $throws(static fn () => $productService->update($webId, $webData + ['website_ids' => [$defaultWebsite]], ['price' => '1'])), 'tirar o website de uma categoria mantida é recusado');
$productService->update($webId, $webData + ['website_ids' => [$defaultWebsite], 'category_ids' => [$baseCategory]], ['price' => '1']);
$inW = static fn () => array_column($gql($admin, '{ products(websiteId: "' . $websiteId . '", search: "selftest-web") { items { sku websiteIds } } }')['data']['products']['items'] ?? [], 'sku');
assertSame([[(string) $defaultWebsite], []], [$gql($admin, '{ product(sku: "selftest-web") { websiteIds } }')['data']['product']['websiteIds'] ?? null, $inW()], 'GraphQL: websiteIds do produto e filtro por website');
assertSame([[(string) $websiteId]], [array_column($gql($guest, '{ categories(websiteId: "' . $websiteId . '") { websiteId } }')['data']['categories'] ?? [], 'websiteId')], 'GraphQL: categorias do website');
$sourceService->create(['code' => 'selftest_w_cd', 'name' => 'CD W', 'website_id' => $websiteId]);
assertSame(['WEBSITE_INVALID', 'SOURCE_WEBSITE_MISMATCH', false], [
    $throws(static fn () => $sourceService->create(['code' => 'selftest_semsite', 'name' => 'S'])),
    $throws(static fn () => $stock->save($webId, ['selftest_w_cd' => ['quantity' => '3']])),
    in_array('selftest_w_cd', array_column($stock->getItems($webId), 'code'), true),
], 'origem sem website é recusada; produto só tem estoque nas origens dos websites dele');
$productService->update($webId, $webData + ['website_ids' => [$defaultWebsite, $websiteId], 'category_ids' => [$baseCategory, $siteWCat]], ['price' => '1']);
$stock->save($webId, ['selftest_w_cd' => ['quantity' => '3']]);
$productService->update($webId, $webData + ['website_ids' => [$defaultWebsite], 'category_ids' => [$baseCategory]], ['price' => '1']);
assertSame(['0', false], [$stock->getSalable([$webId])[$webId], in_array('selftest_w_cd', array_column($stock->getItems($webId), 'code'), true)], 'estoque de origem de website que o produto deixou não entra no saldo');
$productService->delete($webId);

// --- variações (produto com grade) -------------------------------------------------------------
$service->create(['entity_type' => 'product', 'code' => 'selftest_vcor', 'label' => 'Cor grade', 'input_type' => 'select', 'is_variant' => 1, 'options' => [['value' => 'az', 'label' => 'Azul Marinho'], ['value' => 'pr', 'label' => 'Preto']]]);
$service->create(['entity_type' => 'product', 'code' => 'selftest_tec', 'label' => 'Tecido', 'input_type' => 'select', 'is_variant' => 1, 'options' => [['value' => 'al', 'label' => 'Algodão']]]);
$service->create(['entity_type' => 'product', 'code' => 'selftest_gola', 'label' => 'Gola', 'input_type' => 'select', 'is_variant' => 1, 'options' => [['value' => 'v', 'label' => 'V']]]);
$service->create(['entity_type' => 'product', 'code' => 'selftest_manga', 'label' => 'Manga', 'input_type' => 'select', 'options' => [['value' => 'c', 'label' => 'Curta']]]);
$setService->update($roupa, ['label' => 'Roupa', 'items' => [$attrId('price') => ['in' => 1], $attrId('selftest_tam') => ['in' => 1], $attrId('selftest_vcor') => ['in' => 1], $attrId('selftest_tec') => ['in' => 1], $attrId('selftest_gola') => ['in' => 1], $attrId('selftest_manga') => ['in' => 1], $attrId('selftest_cor') => ['in' => 1]]]);
$varService = new VariantService();
$parentData = ['attribute_set_id' => $roupa, 'sku' => 'selftest-var', 'name' => 'Blusa', 'is_active' => 1, 'website_ids' => [$defaultWebsite], 'category_ids' => [$baseCategory], 'type' => 'configurable'];
assertSame(['VARIANT_ATTRIBUTE_INVALID', 'VARIANT_ATTRIBUTE_INVALID', 'VARIANT_ATTRIBUTE_INVALID', 'VARIANT_ATTRIBUTE_INVALID'], [
    $throws(static fn () => $productService->create($parentData + ['variant_attributes' => []])),
    $throws(static fn () => $productService->create($parentData + ['variant_attributes' => ['selftest_vcor', 'selftest_tam', 'selftest_tec', 'selftest_gola']])),
    $throws(static fn () => $productService->create($parentData + ['variant_attributes' => ['selftest_cor']])),
    $throws(static fn () => $productService->create($parentData + ['variant_attributes' => ['selftest_manga']])),
], 'grade pede de 1 a 3 atributos do conjunto marcados "Usar na grade"');
$parentId = (int) $productService->create($parentData + ['variant_attributes' => ['selftest_vcor', 'selftest_tam']], ['price' => '99'])->getId();
assertSame([[], ['selftest_vcor', 'selftest_tam']], [$productService->getValues($parentId), array_column($varService->getAttributes($parentId), 'code')], 'pai com grade não grava valores EAV; atributos da grade em ordem');
$combos = [];
foreach (['az', 'pr'] as $cor) {
    foreach (['p', 'm'] as $tam) {
        $combos[] = ['options' => ['selftest_vcor' => $cor, 'selftest_tam' => $tam], 'price' => '50', 'is_active' => 1];
    }
}
$varService->save($parentId, $combos);
$variants = $varService->getVariants($parentId);
assertSame([
    ['selftest-var-AZUL-MARINHO-P', 'selftest-var-AZUL-MARINHO-M', 'selftest-var-PRETO-P', 'selftest-var-PRETO-M'],
    'Blusa - Azul Marinho / p',
    [$defaultWebsite],
    [$baseCategory],
    ['selftest_vcor' => 'az', 'selftest_tam' => 'p'],
], [array_column($variants, 'sku'), $variants[0]['name'], $productService->getWebsiteIds($variants[0]['product_id']), $productService->getCategoryIds($variants[0]['product_id']), $variants[0]['options']], 'variações: SKU sugerido, nome, websites e categorias do pai');
$keep = array_map(static fn ($v) => ['product_id' => $v['product_id'], 'sku' => $v['sku'], 'is_active' => 1], $variants);
assertSame(['VARIANT_DUPLICATE', 'SKU_DUPLICATE', 'VARIANT_OPTION_INVALID', 'SKU_DUPLICATE', 'VARIANT_PARENT_INVALID'], [
    $throws(static fn () => $varService->save($parentId, [...$keep, $combos[0]])),
    $throws(static fn () => $varService->save($parentId, [$keep[0], ['product_id' => $keep[1]['product_id'], 'sku' => $keep[0]['sku']]])),
    $throws(static fn () => $varService->save($parentId, [['options' => ['selftest_vcor' => 'xx', 'selftest_tam' => 'p']]])),
    $throws(static fn () => $varService->save($parentId, [['options' => ['selftest_vcor' => 'az', 'selftest_tam' => 'p'], 'sku' => 'selftest-var']])),
    $throws(static fn () => $varService->save($variants[0]['product_id'], [])),
], 'combinação repetida, SKU repetido, opção inválida e variação de produto simples são recusados');
$varService->save($parentId, array_slice($keep, 0, 3));
assertSame([3, false], [count($varService->getVariants($parentId)), (bool) (new ProductService())->getValues($variants[3]['product_id'])], 'variação fora da lista é removida com os valores');
$productService->update($parentId, ['sku' => 'selftest-var', 'name' => 'Camisa', 'is_active' => 1, 'website_ids' => [$defaultWebsite, $websiteId], 'category_ids' => [$baseCategory, $siteWCat]]);
assertSame(['Camisa - Azul Marinho / p', [$defaultWebsite, $websiteId]], [$varService->getVariants($parentId)[0]['name'], $productService->getWebsiteIds($variants[0]['product_id'])], 'pai renomeado leva nome e websites às variações');
$productService->update($variants[0]['product_id'], ['sku' => 'selftest-var-az-p', 'name' => 'Outro', 'is_active' => 1, 'website_ids' => [$defaultWebsite]], ['price' => '55', 'selftest_vcor' => 'pr']);
assertSame(['Camisa - Azul Marinho / p', 'selftest-var-az-p', ['selftest_vcor' => 'az', 'selftest_tam' => 'p'], '55', [$defaultWebsite, $websiteId]], [
    $varService->getVariants($parentId)[0]['name'], $varService->getVariants($parentId)[0]['sku'], $varService->getVariants($parentId)[0]['options'], $varService->getVariants($parentId)[0]['price'], $productService->getWebsiteIds($variants[0]['product_id']),
], 'variação editada: SKU e preço mudam; nome, websites e combinação ficam os do pai');
assertSame([['selftest_vcor' => 'pr', 'selftest_tam' => 'm']], $varService->generate($parentId, ['selftest_vcor' => ['az', 'pr', 'xx'], 'selftest_tam' => ['p', 'm']]), 'gerar devolve só as combinações que faltam');
$createForm = $admin->request('/admin/product/create?set=' . $roupa . '&type=configurable')['body'];
assertSame([true, false, false, false], [str_contains($createForm, 'name="variant_attributes[]" value="selftest_vcor"'), str_contains($createForm, 'value="selftest_cor"'), str_contains($createForm, 'value="selftest_manga"'), str_contains($createForm, 'name="attributes[price]"')], 'criar com grade oferece só os atributos marcados "Usar na grade", sem campos EAV');
[, $msgs] = $admin->submit('/admin/product/create?set=' . $roupa . '&type=configurable', '/admin/product/save', ['attribute_set_id' => $roupa, 'type' => 'configurable', 'variant_attributes' => ['selftest_tam'], 'sku' => 'selftest-var2', 'name' => 'Meia', 'is_active' => 1, 'website_ids' => [$defaultWebsite], 'category_ids' => [$baseCategory]]);
assertSame([['Produto criado com sucesso.'], 'configurable'], [$msgs, $productRow('selftest-var2')['type'] ?? null], 'tela: cria produto com grade');
$v = $varService->getVariants($parentId);
$post = ['product_id' => $parentId, 'attribute_set_id' => $roupa, 'sku' => 'selftest-var', 'name' => 'Camisa', 'is_active' => 1, 'website_ids' => [$defaultWebsite, $websiteId], 'category_ids' => [$baseCategory, $siteWCat],
    'variants' => [$v[0]['product_id'] => ['sku' => $v[0]['sku'], 'price' => '56', 'is_active' => 1], $v[1]['product_id'] => ['sku' => $v[1]['sku'], 'price' => '50', 'remove' => 1], $v[2]['product_id'] => ['sku' => $v[2]['sku'], 'price' => '50', 'is_active' => 1]],
    'grade' => ['selftest_vcor' => ['pr'], 'selftest_tam' => ['m']], 'grade_price' => '60'];
[, $msgs] = $admin->submit('/admin/product/edit?id=' . $parentId, '/admin/product/save', $post);
$v = $varService->getVariants($parentId);
assertSame([['Produto atualizado com sucesso.'], ['selftest-var-az-p', 'selftest-var-PRETO-P', 'selftest-var-PRETO-M'], ['56', '50', '60']], [$msgs, array_column($v, 'sku'), array_column($v, 'price')], 'tela: edita, remove e gera variações ao salvar o pai');
$post['name'] = 'Outro';
$post['variants'] = [$v[0]['product_id'] => ['sku' => $v[1]['sku'], 'price' => '56']];
[, $msgs] = $admin->submit('/admin/product/edit?id=' . $parentId, '/admin/product/save', $post);
assertSame([["Já existe um produto com o SKU 'selftest-var-PRETO-P'."], 'Camisa'], [$msgs, $productRow('selftest-var')['name']], 'erro numa variação não salva o pai');
$page = $admin->request('/admin/product/edit?id=' . $parentId)['body'];
assertSame([true, false], [str_contains($page, 'name="grade[selftest_vcor][]" value="pr" checked'), str_contains($page, 'name="attributes[price]"')], 'pai mostra a grade, sem campos EAV');
$varPage = $admin->request('/admin/product/edit?id=' . $v[0]['product_id'])['body'];
assertSame([true, false, true], [str_contains($varPage, 'Variação de <a href="/admin/product/edit?id=' . $parentId . '"'), str_contains($varPage, 'name="attributes[selftest_vcor]"'), str_contains($varPage, 'name="attributes[price]"')], 'variação mostra o pai e os campos EAV, sem os da grade');
$list = $admin->request('/admin/product/index?q=selftest-var')['body'];
assertSame([2, true, true], [substr_count($list, '/admin/product/edit?id='), str_contains($list, '(3 variações)'), str_contains($list, 'R$ 50,00 – R$ 60,00')], 'lista mostra só os pais, com a contagem e a faixa de preço');
assertSame(1, substr_count($admin->request('/admin/product/index?q=PRETO-M')['body'], '/admin/product/edit?id=' . $parentId . '"'), 'busca pelo SKU da variação acha o pai');
$listTotal = (int) $pdo->query('SELECT count(*) FROM catalog_product WHERE parent_id IS NULL')->fetchColumn();
$lastPage = (int) ceil($listTotal / 8);
assertSame([min(8, $listTotal), $listTotal - ($lastPage - 1) * 8, $lastPage > 1], [
    substr_count($admin->request('/admin/product/index')['body'], '/admin/product/edit?id='),
    substr_count($admin->request('/admin/product/index?page=' . $lastPage)['body'], '/admin/product/edit?id='),
    str_contains($admin->request('/admin/product/index')['body'], 'href="/admin/product/index?page=2" title="Próxima página"'),
], 'lista de produtos pagina de 8 em 8');
$pager = $admin->request('/admin/product/index?limit=5&page=999&q=')['body'];
$lastFrom = (int) (floor(($listTotal - 1) / 5) * 5 + 1);
assertSame([$listTotal - $lastFrom + 1, true, true], [
    substr_count($pager, '/admin/product/edit?id='),
    str_contains($pager, '<option value="5" selected>'),
    str_contains($pager, $lastFrom . '-' . $listTotal . ' de ' . $listTotal),
], 'paginação: "Mostrar linhas" muda o tamanho; página além do fim mostra a última');
$skusOf = static fn (string $html) => preg_match_all('/<tr>\s*<td>([^<]*)<\/td>/', $html, $m) ? $m[1] : [];
$byName = $admin->request('/admin/product/index?q=selftest-var')['body'];
$bySkuDesc = $admin->request('/admin/product/index?q=selftest-var&sort=sku&dir=desc')['body'];
assertSame([
    true, true, true,
    ['selftest-var2', 'selftest-var'],
    true,
], [
    str_contains($byName, '<th class="admin-sortable is-sorted" aria-sort="ascending"><a href="/admin/product/index?q=selftest-var&amp;sort=name&amp;dir=desc">Nome ▲</a></th>'),
    str_contains($byName, '<a href="/admin/product/index?q=selftest-var&amp;sort=stock&amp;dir=asc">Estoque</a>'),
    str_contains($bySkuDesc, 'name="sort" value="sku"'),
    $skusOf($bySkuDesc),
    str_contains($bySkuDesc, 'SKU ▼'),
], 'título da lista ordena pela coluna (clicado de novo, inverte) e fica destacado; filtros e paginação mantêm a ordem');
$stockAsc = $skusOf($admin->request('/admin/product/index?q=selftest&sort=stock&dir=asc&limit=20')['body']);
$stockOf = (new StockService())->getSalable(array_map(static fn ($sku) => (new \Iter\Catalog\Model\Product())->getResource()->findIdBySku($sku), $stockAsc));
$sorted = array_values($stockOf);
sort($sorted, SORT_NUMERIC);
assertSame($sorted, array_values($stockOf), 'ordenar pelo Estoque (coluna do Inventory) usa o saldo vendável');
$sourceService->create(['code' => 'selftest_var_cd', 'name' => 'CD Var', 'website_id' => $defaultWebsite, 'is_enabled' => 1]);
assertSame('PRODUCT_IS_CONFIGURABLE', $throws(static fn () => $stock->save($parentId, ['selftest_var_cd' => ['quantity' => '1']])), 'estoque no pai com grade é recusado');
$stock->saveGrid($parentId, 'selftest_var_cd', [$v[0]['product_id'] => '2', $v[1]['product_id'] => '3,5', $v[2]['product_id'] => '4']);
assertSame(['2', '9.5'], [$stock->getSalable([$v[0]['product_id']])[$v[0]['product_id']], $stock->getSalable([$parentId])[$parentId]], 'grade grava as células; saldo do pai é a soma das variações');
assertSame(['QUANTITY_INVALID', '2', 'PRODUCT_NOT_FOUND'], [
    $throws(static fn () => $stock->saveGrid($parentId, 'selftest_var_cd', [$v[0]['product_id'] => '7', $v[1]['product_id'] => 'abc'])),
    $stock->getSalable([$v[0]['product_id']])[$v[0]['product_id']],
    $throws(static fn () => $stock->saveGrid($parentId, 'selftest_var_cd', [0 => '1'])),
], 'grade com erro não grava nada; só variações do produto');
$productService->update($v[2]['product_id'], ['sku' => $v[2]['sku'], 'is_active' => 0], ['price' => '60']);
assertSame('5.5', $stock->getSalable([$parentId])[$parentId], 'variação inativa não conta no saldo do pai');
// stock per website + reservations (COMMERCE-PLAN delivery 1); selftest_var_cd is a default-website source
$v0 = (int) $v[0]['product_id'];
$reservations = static fn () => (int) $pdo->query("SELECT COUNT(*) FROM inventory_reservation WHERE product_id = {$v0}")->fetchColumn();
assertSame(['2', '2', '0'], [$stock->getSalable([$v0], $defaultWebsite)[$v0], $stock->getSalable([$v0])[$v0], $stock->getSalable([$v0], $websiteId)[$v0]],
    'saldo por website: só as origens daquele website contam');
$stock->reserve($defaultWebsite, [$v0 => 1], 'order', 'selftest-1');
$apiStock = static fn (string $website) => $gql($guest, '{ product(sku: "' . $v[0]['sku'] . '") { stock' . $website . ' { salable_quantity } } }')['data']['product']['stock']['salable_quantity'] ?? null;
assertSame(['1', '4.5', 1, 1, 0], [
    $stock->getSalable([$v0], $defaultWebsite)[$v0], $stock->getSalable([$parentId])[$parentId],
    $apiStock('(websiteId: "' . $defaultWebsite . '")'), $apiStock(''), $apiStock('(websiteId: "' . $websiteId . '")'),
], 'reserva: o saldo vendável cai (também no pai e na API por website) sem mexer na quantidade física');
$physical = (string) $pdo->query("SELECT quantity FROM inventory_source_item WHERE product_id = {$v0}")->fetchColumn();
assertSame(['INSUFFICIENT_STOCK', 'INSUFFICIENT_STOCK', 'INSUFFICIENT_STOCK', 'PRODUCT_IS_CONFIGURABLE', 1, '2.0000'], [
    $throws(static fn () => $stock->reserve($defaultWebsite, [$v0 => 2], 'order', 'selftest-2')),
    $throws(static fn () => $stock->reserve($defaultWebsite, [(int) $v[1]['product_id'] => 1, $v0 => 2], 'order', 'selftest-2')),
    $throws(static fn () => $stock->reserve($websiteId, [$v0 => 1], 'order', 'selftest-2')),
    $throws(static fn () => $stock->reserve($defaultWebsite, [$parentId => 1], 'order', 'selftest-2')),
    $reservations(), $physical,
], 'reserva: acima do saldo do website, em outro website ou no pai com grade é recusada, sem gravar nada (tudo ou nada)');
$stock->release($defaultWebsite, [$v0 => 1], 'order_canceled', 'selftest-1');
assertSame(['2', 2], [$stock->getSalable([$v0], $defaultWebsite)[$v0], $reservations()], 'cancelar devolve a reserva com um lançamento oposto');
assertSame(['P0001', 2], [$sqlError("UPDATE inventory_reservation SET quantity = 5 WHERE product_id = {$v0}"), $reservations()], 'reservas só aceitam inclusão: alterar uma é recusado pelo banco');
// the lock queues concurrent orders: while one reserves, another connection can't take the product's lock
$db = parse_ini_file(ETC . 'database.ini');
$second = new PDO(sprintf('pgsql:host=%s;port=%d;dbname=%s', $db['host'], $db['port'], $db['database']), $db['user'], $db['password']);
$lockFree = static fn () => $second->query("SELECT pg_try_advisory_xact_lock(hashtext('inventory_reservation'), {$v0})")->fetchColumn();
Connection::get()->adapter()->beginTransaction();
$stock->reserve($defaultWebsite, [$v0 => 2], 'order', 'selftest-3');
$second->beginTransaction();
$whileHeld = $lockFree();
$second->rollBack();
Connection::get()->adapter()->rollBack();
$second->beginTransaction();
$afterRollback = $lockFree();
$second->rollBack();
assertSame([false, true, '2'], [$whileHeld, $afterRollback, $stock->getSalable([$v0], $defaultWebsite)[$v0]], 'reserva trava o produto até o fim da transação; desfeita, o saldo volta');
$gridPage = $admin->request('/admin/product/edit?id=' . $parentId . '&source=selftest_var_cd')['body'];
assertSame([true, true, 1, true], [str_contains($gridPage, 'Estoque por grade'), str_contains($gridPage, 'name="grid[' . $v[0]['product_id'] . ']" value="2"'), substr_count($gridPage, '<td>–</td>'), str_contains($gridPage, '<td>9,5</td>')],
    'painel da grade: célula por variação, hífen onde não há combinação, total');
[, $msgs] = $admin->submit('/admin/product/edit?id=' . $parentId . '&source=selftest_var_cd', '/admin/stock/grid', ['product_id' => $parentId, 'source' => 'selftest_var_cd', 'grid' => [$v[0]['product_id'] => '1']]);
assertSame([['Estoque atualizado com sucesso.'], true], [$msgs, str_contains($admin->request('/admin/product/index?q=selftest-var')['body'], '<td>4,5</td>')], 'tela: salva a grade; lista mostra o saldo somado');
$r = $gql($guest, '{ product(sku: "selftest-var") { type variantAttributes { code options { value label } } variants { sku variantOptions { code value label } parent { sku } } } }')['data']['product'] ?? null;
$opt = static fn ($c, $v, $l) => ['code' => $c, 'value' => $v, 'label' => $l];
assertSame([
    'type' => 'CONFIGURABLE',
    'variantAttributes' => [['code' => 'selftest_vcor', 'options' => [['value' => 'az', 'label' => 'Azul Marinho'], ['value' => 'pr', 'label' => 'Preto']]], ['code' => 'selftest_tam', 'options' => [['value' => 'p', 'label' => 'p'], ['value' => 'm', 'label' => 'm']]]],
    'variants' => [
        ['sku' => 'selftest-var-az-p', 'variantOptions' => [$opt('selftest_vcor', 'az', 'Azul Marinho'), $opt('selftest_tam', 'p', 'p')], 'parent' => ['sku' => 'selftest-var']],
        ['sku' => 'selftest-var-PRETO-P', 'variantOptions' => [$opt('selftest_vcor', 'pr', 'Preto'), $opt('selftest_tam', 'p', 'p')], 'parent' => ['sku' => 'selftest-var']],
    ],
], $r, 'GraphQL: tipo, atributos da grade e variações ativas com a combinação e o pai');
$skus = array_column($gql($guest, '{ products(search: "selftest-var") { items { sku } } }')['data']['products']['items'] ?? [], 'sku');
assertSame([true, false, 'selftest-var'], [in_array('selftest-var', $skus, true), in_array('selftest-var-az-p', $skus, true), $gql($guest, '{ product(sku: "SELFTEST-VAR-PRETO-P") { parent { sku } } }')['data']['product']['parent']['sku'] ?? null],
    'GraphQL: products lista só os pais; product(sku:) acha a variação');
$activePrices = array_map('floatval', $pdo->query("SELECT v.value FROM catalog_product p JOIN eav_value_decimal v ON v.entity_id = p.product_id AND v.attribute_id = {$attrId('price')}
    WHERE p.parent_id = {$parentId} AND p.is_active = 1")->fetchAll(PDO::FETCH_COLUMN));
assertSame([['min' => min($activePrices), 'max' => max($activePrices)], array_fill_keys(['min', 'max'], (float) $pdo->query("SELECT v.value FROM catalog_product p JOIN eav_value_decimal v ON v.entity_id = p.product_id AND v.attribute_id = {$attrId('price')} WHERE p.sku = 'selftest-var-az-p'")->fetchColumn())], [
    array_map('floatval', $gql($guest, '{ product(sku: "selftest-var") { priceRange { min max } } }')['data']['product']['priceRange'] ?? []),
    array_map('floatval', $gql($guest, '{ product(sku: "selftest-var-az-p") { priceRange { min max } } }')['data']['product']['priceRange'] ?? []),
], 'GraphQL priceRange: "a partir de" do configurável vem das variações ativas (a inativa de R$ 60 fica fora); o simples tem o próprio preço');
$service->update($attrId('selftest_vcor'), ['label' => 'Cor grade', 'input_type' => '', 'is_variant' => 1, 'is_filterable' => 1]);
$varSearch = static fn (string $args) => $gql($guest, '{ products(search: "selftest-var"' . $args . ') { total aggregations { attributes { code options { value count } } } } }')['data']['products'] ?? null;
$activeColors = $pdo->query("SELECT DISTINCT v.value FROM catalog_product p JOIN eav_value_varchar v ON v.entity_id = p.product_id AND v.attribute_id = {$attrId('selftest_vcor')}
    WHERE p.parent_id = {$parentId} AND p.is_active = 1 ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
assertSame([array_map(static fn ($c) => ['value' => $c, 'count' => 1], $activeColors), 1, 1], [
    array_column($varSearch('')['aggregations']['attributes'] ?? [], 'options', 'code')['selftest_vcor'] ?? null,
    $varSearch(', filters: [{code: "selftest_vcor", values: ["' . $activeColors[0] . '"]}]')['total'] ?? null,
    $varSearch(', filters: [{code: "selftest_vcor", values: ["' . $activeColors[0] . '"]}]')['aggregations']['attributes'][0]['options'][1]['count'] ?? 1,
], 'filtros da loja: a cor das variações ativas filtra o configurável, contado uma vez; a contagem da cor ignora a própria escolha');
$saveGql = 'mutation ($id: ID!, $vs: [VariantInput!]!) { saveVariants(productId: $id, variants: $vs) { sku } }';
$vs = [...array_map(static fn ($x) => ['id' => (string) $x['product_id']], $v), ['options' => [['code' => 'selftest_vcor', 'value' => 'az'], ['code' => 'selftest_tam', 'value' => 'm']], 'price' => '50']];
assertSame(['Acesso negado.', 'VARIANT_DUPLICATE'], [
    $gql($guest, $saveGql, ['id' => (string) $parentId, 'vs' => $vs])['errors'][0]['message'] ?? null,
    $gql($admin, $saveGql, ['id' => (string) $parentId, 'vs' => [...$vs, $vs[3]]])['errors'][0]['extensions']['code'] ?? null,
], 'GraphQL: saveVariants só para o admin; erros com código');
assertSame(['selftest-var-az-p', 'selftest-var-PRETO-P', 'selftest-var-PRETO-M', 'selftest-var-AZUL-MARINHO-M'], array_column($gql($admin, $saveGql, ['id' => (string) $parentId, 'vs' => $vs])['data']['saveVariants'] ?? [], 'sku'),
    'GraphQL: saveVariants mantém os SKUs e cria a nova com o SKU sugerido');
assertSame('CONFIGURABLE', $gql($admin, 'mutation { createProduct(sku: "selftest-var3", name: "Boné", attributeSetId: "' . $roupa . '", categoryIds: ["' . $baseCategory . '"], type: CONFIGURABLE, variantAttributes: ["selftest_tam"]) { type } }')['data']['createProduct']['type'] ?? null,
    'GraphQL: createProduct com grade');
$corOptions = static fn (array $values) => $service->update($attrId('selftest_vcor'), ['label' => 'Cor grade', 'input_type' => 'select', 'options' => array_map(static fn ($v) => ['value' => $v, 'label' => $v === 'az' ? 'Azul Marinho' : ucfirst($v)], $values)]);
$corOptions(['az', 'pr', 'vd']);
$corOptions(['az', 'pr']);
assertSame(['OPTION_IN_USE', 'OPTION_IN_USE', ['az', 'pr']], [
    $throws(static fn () => $corOptions(['az'])),
    $throws(static fn () => $service->update($attrId('selftest_vcor'), ['label' => 'Cor grade', 'input_type' => 'text'])),
    array_column($service->getOptions($attrId('selftest_vcor')), 'value'),
], 'opção usada por variação não é removida (nem trocando o tipo); a sem uso pode');
$productService->delete($parentId);
assertSame([false, []], [$productRow('selftest-var-az-p'), $values->load('product', $variants[0]['product_id'])], 'apagar o pai apaga as variações e os valores delas');
$corOptions(['az']);
assertSame(['az'], array_column($service->getOptions($attrId('selftest_vcor')), 'value'), 'sem variações, a opção pode ser removida');

// --- e-mail -------------------------------------------------------------------------------------------
$mail = new MailService();
$eml = static fn (int $id): string => quoted_printable_decode((string) @file_get_contents(MailService::logDir() . $id . '.eml'));
$status = static fn (int $id): int => (int) $mail->find($id)['status'];

$id = $mail->send('mail_test', 'Cliente@Selftest.local', ['name' => '<b>Zé</b>']);
assertSame([MailService::PENDING, ''], [$status($id), $eml($id)], 'send só enfileira; quem envia é o worker');
$mail->processQueue();
assertSame([MailService::SENT, true, true, true, true], [
    $status($id),
    str_contains($eml($id), 'Subject: Teste de envio - Selftest Loja'),
    str_contains($eml($id), 'From: Selftest Loja <loja@selftest.local>'),
    str_contains($eml($id), 'To: cliente@selftest.local'),
    str_contains($eml($id), 'Olá, &lt;b&gt;Zé&lt;/b&gt;.'),
], 'worker envia com remetente, destinatário normalizado e variáveis escapadas');
assertSame([true, true], [str_contains($eml($id), 'Olá, <b>Zé</b>.'), str_contains($eml($id), 'Content-Type: text/plain')], 'versão texto gerada do HTML, sem escapar');
assertSame(MailService::SENT, $status($mail->send('mail_test', 'agora@selftest.local', ['name' => 'X'], now: true)), 'now: envia sem esperar o worker');
assertSame('RECIPIENT_INVALID', $throws(static fn () => $mail->send('mail_test', "a@selftest.local\r\nBcc: x@evil.test")), 'destinatário com quebra de linha é recusado');
assertSame('RECIPIENT_INVALID', $throws(static fn () => $mail->send('mail_test', 'a@selftest.local', [], ['cc' => 'nao-e-email'])), 'cópia inválida é recusada');
assertSame('TEMPLATE_UNKNOWN', $throws(static fn () => $mail->send('selftest_nao_existe', 'a@selftest.local')), 'modelo inexistente é recusado');

// limit per recipient and template: 10 an hour
for ($i = 0; $i < 10; $i++) {
    $mail->send('mail_test', 'limite@selftest.local');
}
assertSame(0, $mail->send('mail_test', 'limite@selftest.local'), 'limite por hora: o 11º não entra na fila');
$mail->processQueue();

// failure: back-off, then FAILED after max_attempts
Config::save(['mail/transport' => 'smtp', 'mail/smtp/host' => '127.0.0.1', 'mail/smtp/port' => (string) ($port + 1), 'mail/smtp/encryption' => 'none', 'mail/queue/max_attempts' => '2']);
$id = $mail->send('mail_test', 'falha@selftest.local', [], now: true);
$row = $mail->find($id);
assertSame([MailService::PENDING, 1, true, true], [(int) $row['status'], (int) $row['attempts'], $row['last_error'] !== null, strtotime($row['send_after']) > time()], 'falha: continua pendente, com o erro e a próxima tentativa adiada');
assertSame(['sent' => 0, 'failed' => 0], $mail->processQueue(), 'worker respeita o intervalo antes de tentar de novo');
$pdo->exec("UPDATE mail_queue SET send_after = NOW() WHERE message_id = {$id}");
$mail->processQueue();
assertSame([MailService::FAILED, 2], [$status($id), (int) $mail->find($id)['attempts']], 'após max_attempts vira Falhou');
Config::save(['mail/transport' => 'log', 'mail/queue/max_attempts' => '5']);

// history: resend / cancel over HTTP
$resendForm = '/admin/mail/view?id=' . $id;
[$location, $msgs] = $admin->submit($resendForm, '/admin/mail/resend', ['message_id' => $id]);
$copyId = (int) substr($location, strlen('/admin/mail/view?id='));
assertSame([['Uma cópia do e-mail entrou na fila de envio.'], MailService::PENDING], [$msgs, $status($copyId)], 'reenviar põe uma cópia na fila');
assertSame(['Envio cancelado.'], $admin->submit('/admin/mail/view?id=' . $copyId, '/admin/mail/cancel', ['message_id' => $copyId])[1], 'pendente pode ser cancelado');
assertSame(['Só um e-mail pendente pode ser cancelado.'], $admin->submit('/admin/mail/view?id=' . $copyId, '/admin/mail/cancel', ['message_id' => $copyId])[1], 'cancelado não é cancelado de novo');
assertSame(['Este e-mail não pode ser reenviado.'], $admin->submit('/admin/mail/view?id=' . $copyId, '/admin/mail/resend', ['message_id' => $resetMail['message_id']])[1], 'e-mail privado não é reenviado');
$list = $admin->request('/admin/mail/index?q=falha%40selftest')['body'];
assertSame([true, false], [str_contains($list, '/admin/mail/view?id=' . $id), str_contains($list, 'agora@selftest.local')], 'histórico filtra pelo destinatário');
$view = $admin->request('/admin/mail/view?id=' . $resetMail['message_id'])['body'];
assertSame([true, false], [str_contains($view, 'Conteúdo não guardado'), str_contains($view, 'token=')], 'e-mail privado não mostra o conteúdo');
assertSame([403, 403], [$dash->request('/admin/mail/index')['status'], $dash->request('/admin/mailtemplate/index')['status']], 'histórico e modelos exigem permissão');

// template override in the admin
$templateForm = '/admin/mailtemplate/edit?code=mail_test';
[, $msgs] = $admin->submit($templateForm, '/admin/mailtemplate/save', ['code' => 'mail_test', 'subject' => 'Selftest {{ name }}', 'body_html' => '<p>Personalizado {{ name }}</p>', 'body_text' => '']);
$id = $mail->send('mail_test', 'modelo@selftest.local', ['name' => 'Ana'], now: true);
assertSame([['Modelo salvo.'], true, true], [$msgs, str_contains($eml($id), 'Subject: Selftest Ana'), str_contains($eml($id), 'Personalizado Ana')], 'modelo alterado no admin vale no próximo envio');
assertSame(['Assunto e corpo HTML são obrigatórios.'], $admin->submit($templateForm, '/admin/mailtemplate/save', ['code' => 'mail_test', 'subject' => '', 'body_html' => 'x'])[1], 'modelo sem assunto é recusado');
preg_match('/name="form_key" value="([0-9a-f]{64})"/', $admin->request($templateForm)['body'], $m);
$preview = $admin->request('/admin/mailtemplate/preview', ['form_key' => $m[1], 'code' => 'mail_test', 'subject' => 'Prévia', 'body_html' => '<p>Oi {{ name }}</p>']);
assertSame(true, str_contains($preview['body'], 'Oi Maria'), 'pré-visualização usa os valores de exemplo');
$admin->submit($templateForm, '/admin/mailtemplate/restore', ['code' => 'mail_test']);
$id = $mail->send('mail_test', 'modelo@selftest.local', ['name' => 'Ana'], now: true);
assertSame(true, str_contains($eml($id), 'Subject: Teste de envio'), 'restaurar volta ao modelo do arquivo');

// settings per website
$scoped = '/admin/config/index?website=' . $websiteId;
[, $msgs] = $admin->submit($scoped, '/admin/config/save', ['website_id' => $websiteId, 'config' => [
    'mail/from_email' => 'w@selftest.local', 'mail/from_name' => 'Loja W', 'mail/smtp/password' => 'segredo-smtp', 'admin/security/max_failures' => '50',
]]);
Config::save([]); // saved over HTTP: drop this process's cache
$secret = (string) Config::websiteValue('mail/smtp/password', $websiteId);
assertSame([['Configurações salvas.'], 'w@selftest.local', null, 'Selftest Loja'], [$msgs, Config::get('mail/from_email', $websiteId), Config::websiteValue('admin/security/max_failures', $websiteId), Config::get('mail/from_name')], 'website tem valor próprio; campo sem escopo de website é ignorado');
assertSame([true, 'segredo-smtp', false, false], [str_starts_with($secret, 'enc:'), \Iter\Core\Crypt::decrypt($secret), str_contains($admin->request($scoped)['body'], 'segredo-smtp'), str_contains($admin->request($scoped)['body'], $secret)], 'senha SMTP gravada cifrada e nunca mostrada de volta');
$id = $mail->send('mail_test', 'site@selftest.local', [], ['website_id' => $websiteId], now: true);
assertSame(true, str_contains($eml($id), 'From: Loja W <w@selftest.local>'), 'e-mail do website sai com o remetente dele');
$admin->submit($scoped, '/admin/config/save', ['website_id' => $websiteId, 'config_default' => ['mail/from_name' => '1']]);
Config::save([]);
assertSame([null, 'Selftest Loja'], [Config::websiteValue('mail/from_name', $websiteId), Config::get('mail/from_name', $websiteId)], '"Usar o valor padrão" apaga o valor do website');
[, $msgs] = $admin->submit($scoped, '/admin/mailtest/send', ['website_id' => $websiteId]);
assertSame(['E-mail de teste enviado para selftest_admin@selftest.local.'], $msgs, 'e-mail de teste pelas Configurações');

// store info: logo upload, address, text
$png = sys_get_temp_dir() . '/selftest_logo.png';
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
$txt = sys_get_temp_dir() . '/selftest_fake.png';
file_put_contents($txt, '<?php echo 1;');
$logoOf = static function (?int $website = null): string {
    Config::save([]); // saved over HTTP: drop this process's cache
    clearstatcache(); // the server deleted files: is_file() here must not answer from PHP's stat cache
    return (string) ($website ? Config::websiteValue('store/info/logo', $website) : Config::get('store/info/logo'));
};
[, $msgs] = $admin->submit('/admin/config/index', '/admin/config/save', ['config' => ['store/info/logo' => new CURLFile($txt, 'image/png', 'logo.png')]]);
assertSame([['"Logo da loja (PNG, JPG ou WEBP, até 2 MB)": envie uma imagem PNG, JPG ou WEBP.'], ''], [$msgs, $logoOf()], 'logo: arquivo que não é imagem é recusado, mesmo com nome .png');
[, $msgs] = $admin->submit('/admin/config/index', '/admin/config/save', ['config' => [
    'store/info/logo' => new CURLFile($png, 'image/png', 'logo.png'),
    'store/info/name' => 'Selftest Loja',
    'store/address/street' => 'Rua <Teste>',
    'store/info/details' => "Seg a sex\n9h às 18h",
]]);
$defaultLogo = $logoOf();
assertSame([['Configurações salvas.'], true, true], [$msgs, (bool) preg_match('#^config/[0-9a-f]{32}\.png$#', $defaultLogo), is_file(Config::mediaDir() . $defaultLogo)], 'logo enviada é gravada em public/media/config com nome aleatório');
$page = $admin->request('/admin/config/index')['body'];
assertSame([true, true, true, "Seg a sex\n9h às 18h"], [
    str_contains($page, 'src="/media/' . $defaultLogo . '"'),
    str_contains($page, 'Rua &lt;Teste&gt;'),
    str_contains($page, 'enctype="multipart/form-data"'),
    Config::get('store/info/details'),
], 'logo, endereço e informações aparecem na tela e no Config::get');
$infoGql = 'query ($w: ID) { storeInfo(websiteId: $w) { name logoUrl details address { street country } } }';
assertSame(
    [['name' => 'Selftest Loja', 'logoUrl' => '/media/' . $defaultLogo, 'details' => "Seg a sex\n9h às 18h", 'address' => ['street' => 'Rua <Teste>', 'country' => 'Brasil']], 'Selftest Loja', ['storeInfo' => null], ['storeInfo' => null]],
    [$gql($guest, $infoGql)['data']['storeInfo'] ?? null, $gql($guest, $infoGql, ['w' => $websiteId])['data']['storeInfo']['name'] ?? null, $gql($guest, $infoGql, ['w' => 999999])['data'] ?? null, $gql($guest, $infoGql, ['w' => 32766])['data'] ?? null],
    'GraphQL storeInfo: dados da loja do padrão, website herda, website inexistente dá null'
);
$scoped = '/admin/config/index?website=' . $websiteId;
assertSame($defaultLogo, Config::get('store/info/logo', $websiteId), 'website sem logo própria usa a do padrão');
$admin->submit($scoped, '/admin/config/save', ['website_id' => $websiteId, 'config' => ['store/info/logo' => new CURLFile($png, 'image/png', 'w.png')]]);
$websiteLogo = $logoOf($websiteId);
assertSame([true, $defaultLogo], [$websiteLogo !== '' && $websiteLogo !== $defaultLogo, Config::get('store/info/logo')], 'website tem logo própria sem mexer na do padrão');
$admin->submit($scoped, '/admin/config/save', ['website_id' => $websiteId, 'config_default' => ['store/info/logo' => '1']]);
assertSame(['', false, true], [$logoOf($websiteId), is_file(Config::mediaDir() . $websiteLogo), is_file(Config::mediaDir() . $defaultLogo)], '"Usar o valor padrão" apaga a logo do website e o arquivo dela');
$admin->submit('/admin/config/index', '/admin/config/save', ['config' => ['store/info/logo' => new CURLFile($png, 'image/png', 'nova.png')]]);
$newLogo = $logoOf();
assertSame([true, false], [is_file(Config::mediaDir() . $newLogo), is_file(Config::mediaDir() . $defaultLogo)], 'trocar a logo apaga o arquivo antigo');
$admin->submit('/admin/config/index', '/admin/config/save', ['config_clear' => ['store/info/logo' => '1']]);
assertSame(['', false], [$logoOf(), is_file(Config::mediaDir() . $newLogo)], '"Remover a imagem" apaga a logo');
unlink($png);
unlink($txt);

// --- system log -----------------------------------------------------------------------------------
putenv('ITER_LOG_DIR=' . $logDir . 'unit/');
Config::save(['system/log/enabled' => '0']);
Log::error('selftest desligado');
assertSame(false, is_file(Log::file()), 'log desligado não grava nada');
Config::save(['system/log/enabled' => '1']);
Log::error("selftest erro\nsegunda linha", ['id' => 7]);
Log::info('selftest info');
Log::notify('selftest notify');
Log::alert('selftest alert');
$lines = file(Log::file(), FILE_IGNORE_NEW_LINES);
assertSame(
    [4, true, true, 'iter-' . date('Y-m-d') . '.log'],
    [
        count($lines),
        (bool) preg_match('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] ERROR: selftest erro\\\\nsegunda linha \{"id":7\}$/', $lines[0]),
        array_map(static fn ($l) => explode(':', substr($l, 22))[0], $lines) === ['ERROR', 'INFO', 'NOTIFY', 'ALERT'],
        basename(Log::file()),
    ],
    'log ligado: uma linha por mensagem, com data, hora, tipo e contexto, no arquivo do dia'
);
$now = strtotime('2026-03-31 12:00');
foreach (['iter-2026-02-28.log', 'iter-2026-03-01.log', 'iter-2026-03-30.log', 'outro-2020-01-01.log', 'iter-2020-01-01.log.bak'] as $name) {
    touch(Log::dir() . $name);
}
$left = static fn () => array_values(array_diff(scandir(Log::dir()), ['.', '..', basename(Log::file())]));
assertSame([[], [], 5], [Log::clean(0, $now), Log::clean(-1, $now), count($left())], 'limpeza com 0 dias não apaga nada');
assertSame([['iter-2026-02-28.log'], ['iter-2020-01-01.log.bak', 'iter-2026-03-01.log', 'iter-2026-03-30.log', 'outro-2020-01-01.log']], [Log::clean(30, $now), $left()], 'limpeza apaga só iter-*.log mais velhos que o prazo');
array_map('unlink', glob(Log::dir() . '*'));
rmdir(Log::dir());
putenv('ITER_LOG_DIR=' . $logDir);
$page = $admin->request('/admin/config/index')['body'];
assertSame([true, true], [str_contains($page, 'name="config[system/log/enabled]"'), str_contains($page, 'name="config[system/log/retention_days]"')], 'ajustes do log aparecem nas Configurações');
[, $msgs] = $admin->submit('/admin/config/index', '/admin/config/save', ['config' => ['system/log/retention_days' => '']]);
Config::save([]); // saved over HTTP: drop this process's cache
assertSame([['Configurações salvas.'], 0], [$msgs, (int) Config::get('system/log/retention_days')], 'dias do log vazio = nunca apagar');

// --- customers --------------------------------------------------------------------------------
$admin->submit('/admin/website/create', '/admin/website/save', ['code' => 'selftest_w2', 'name' => 'Selftest W2']);
$website2 = (int) $pdo->query("SELECT website_id FROM store_website WHERE code = 'selftest_w2'")->fetchColumn();
$admin->submit('/admin/customergroup/create', '/admin/customergroup/save', ['code' => 'selftest_cg', 'name' => 'Selftest CG']);
$custGroup = (int) $pdo->query("SELECT customer_group_id FROM customer_group WHERE code = 'selftest_cg'")->fetchColumn();
// own set: the default one is the user's to edit (extra required attributes would break these checks)
$custAttr = static fn (string $code) => (int) $pdo->query("SELECT attribute_id FROM eav_attribute a JOIN eav_entity_type et USING (entity_type_id) WHERE et.code = 'customer' AND a.code = '{$code}'")->fetchColumn();
$service->create(['entity_type' => 'customer', 'code' => 'selftest_prof', 'label' => 'Profissão', 'input_type' => 'text']);
$custSet = (int) $setService->create(['entity_type' => 'customer', 'code' => 'selftest_cli', 'label' => 'Cli'])->getId();
$setService->update($custSet, ['label' => 'Cli', 'items' => [
    $custAttr('gender') => ['in' => 1, 'group' => 'Dados pessoais'], $custAttr('phone') => ['in' => 1, 'group' => 'Dados pessoais'],
    $custAttr('selftest_prof') => ['in' => 1, 'group' => 'Extra'],
]]);
$custRow = static fn (string $email, ?int $website = null) => $pdo->query("SELECT * FROM customer_flat WHERE email = '{$email}' AND website_id = " . ($website ?? $websiteId))->fetch(PDO::FETCH_ASSOC);
$saveCustomer = static fn (array $fields) => $admin->submit('/admin/customer/create', '/admin/customer/save', $fields + [
    'attribute_set_id' => $custSet, 'website_id' => $websiteId, 'group_id' => $custGroup, 'email' => 'ana@selftest.local',
    'firstname' => 'Ana', 'lastname' => 'Silva', 'customer_type' => 'pf', 'cpf' => '529.982.247-25', 'is_active' => '1',
])[1];

$menu = $admin->request('/admin/index')['body'];
assertSame([true, true, true], [
    str_contains($menu, 'href="/admin/customer/index"'),
    str_contains($menu, 'href="/admin/attribute/index?entity_type=customer"'),
    str_contains($menu, 'href="/admin/attribute/index?entity_type=product"'),
], 'menu Clientes, com os atributos de cliente e de produto separados');
$custForm = $admin->request('/admin/customer/create?set=' . $custSet)['body'];
assertSame([true, true, true, true, false], [
    str_contains($custForm, 'name="attributes[gender]"'),
    (bool) preg_match('/name="attributes\[phone\]"[^>]*data-mask="phone"/', $custForm),
    str_contains($custForm, 'name="attributes[selftest_prof]"'),
    (bool) preg_match('/data-customer-type-block="pj" hidden/', $custForm),
    str_contains($admin->request('/admin/product/create')['body'], 'selftest_prof'),
], 'form de cliente: atributos EAV com máscara, bloco PJ escondido; atributo de cliente fora do produto');
assertSame(['Informe um CPF válido.'], $saveCustomer(['cpf' => '529.982.247-24']), 'CPF com dígito errado é recusado');
assertSame(['Informe um CPF válido.'], $saveCustomer(['cpf' => '11.222.333/0001-81']), 'PF não aceita CNPJ');
assertSame(['Cliente criado com sucesso.'], $saveCustomer(['email' => 'Ana@Selftest.local', 'dob' => '1990-05-20', 'attributes' => ['gender' => 'f', 'phone' => '(11) 98765-4321', 'selftest_prof' => 'Médica']]), 'cliente PF criado');
$ana = $custRow('ana@selftest.local');
$anaId = (int) $ana['customer_id'];
assertSame(['pf', '52998224725', '1990-05-20', null], [$ana['customer_type'], $ana['taxvat'], $ana['dob'], $ana['company_name']], 'e-mail em minúsculas e CPF gravado só com dígitos');
assertSame(['gender' => 'f', 'phone' => '(11) 98765-4321', 'selftest_prof' => 'Médica'], (new CustomerService())->getValues($anaId), 'atributos do cliente gravados como valores EAV');
// customer form layout: same editor as the product's, saved apart from it
$anaPage = $admin->request('/admin/customer/edit?id=' . $anaId)['body'];
$custBlocks = $blocksOf($anaPage);
assertSame([['account', 'pf', 'pj', 'addresses', 'group:Dados pessoais', 'group:Extra'], true, true], [$custBlocks, str_contains($anaPage, 'data-layout-toggle'), str_contains($anaPage, 'data-save-url="/admin/customerlayout/save"')], 'form de cliente em blocos, com "Organizar tela"');
$custLayout = ['blocks' => [['id' => 'group:Extra', 'wide' => true], ['id' => 'account', 'wide' => false]], 'fields' => ['group:Dados pessoais' => ['phone', 'gender']], 'columns' => 2];
assertSame([200, 'Layout salvo.'], $layoutPost($admin, '/admin/customerlayout/save', ['layout' => json_encode($custLayout)]), 'layout do cliente salvo pelo admin');
$anaPage = $admin->request('/admin/customer/edit?id=' . $anaId)['body'];
assertSame([['group:Extra (largura total)', 'account', 'pf', 'pj', 'addresses', 'group:Dados pessoais'], true, true], [
    $blocksOf($anaPage),
    strpos($anaPage, 'data-field="phone"') < strpos($anaPage, 'data-field="gender"'),
    str_contains($anaPage, '--layout-columns: 2'),
], 'form de cliente segue o layout salvo (blocos, campos e colunas)');
assertSame(true, str_contains($anaPage, 'data-customer-type-block="pj" hidden'), 'bloco do outro tipo continua escondido com o layout');
assertSame($defaultBlocks, $blocksOf($admin->request('/admin/product/edit?id=' . $estId)['body']), 'layout do cliente não muda o do produto');
assertSame([403, false], [$layoutPost($editor, '/admin/customerlayout/save', ['layout' => '{"blocks":[]}'])[0], str_contains($admin2->request('/admin/customer/edit?id=' . $anaId)['body'], 'group:Extra (largura total)')],
    'perfil sem "Organizar tela do cliente" recebe 403; outro admin mantém o padrão');
assertSame([200, 'Layout padrão restaurado.'], $layoutPost($admin, '/admin/customerlayout/reset'), 'restaurar layout do cliente');
assertSame($custBlocks, $blocksOf($admin->request('/admin/customer/edit?id=' . $anaId)['body']), 'depois de restaurar, cliente volta à ordem padrão');
// addresses: saved with the customer form; blank rows ignored, one default of each kind
$anaPost = static fn (array $addresses) => $admin->submit('/admin/customer/edit?id=' . $anaId, '/admin/customer/save', ['customer_id' => $anaId, 'website_id' => $websiteId, 'group_id' => $custGroup,
    'email' => 'ana@selftest.local', 'firstname' => 'Ana', 'lastname' => 'Silva', 'customer_type' => 'pf', 'cpf' => '529.982.247-25', 'is_active' => '1', 'addresses' => $addresses])[1];
$addr = ['postcode' => '01310-100', 'street' => 'Avenida Paulista', 'number' => '1000', 'district' => 'Bela Vista', 'city' => 'São Paulo', 'region' => 'SP'];
$addrRows = static fn () => $pdo->query("SELECT address_id, postcode, number, is_default_shipping, is_default_billing FROM customer_address_flat WHERE customer_id = {$anaId} ORDER BY address_id")->fetchAll(PDO::FETCH_ASSOC);
assertSame([true, true, true], [
    str_contains($anaPage, 'data-add-row="customer-address-rows"'),
    (bool) preg_match('/name="addresses\[0\]\[postcode\]"[^>]*data-mask="cep" data-cep="lookup"/', $anaPage),
    str_contains($anaPage, 'data-cep-scope'),
], 'form de cliente: bloco de endereços com CEP e máscara');
assertSame([['Endereço 1: informe o bairro.'], []], [$anaPost([['district' => ''] + $addr]), $addrRows()], 'endereço sem bairro é recusado');
assertSame([['Endereço 1: escolha o estado (UF).'], []], [$anaPost([['region' => 'XX'] + $addr]), $addrRows()], 'UF inválida é recusada');
assertSame(['Cliente atualizado com sucesso.'], $anaPost([$addr, ['number' => '20', 'is_default_shipping' => '1'] + $addr, ['postcode' => '', 'street' => '']]), 'cliente salvo com dois endereços e uma linha em branco');
$rows = $addrRows();
assertSame([2, '01310100', [0, 1], [1, 0]], [count($rows), $rows[0]['postcode'], array_map('intval', array_column($rows, 'is_default_shipping')), array_map('intval', array_column($rows, 'is_default_billing'))],
    'CEP só com dígitos; padrão de entrega o marcado, de cobrança o primeiro');
assertSame(['Cliente atualizado com sucesso.'], $anaPost([['address_id' => $rows[0]['address_id'], 'number' => '1500'] + $addr]), 'endereço editado e o outro removido');
$rows2 = $addrRows();
assertSame([[$rows[0]['address_id'], '1500', 1, 1]], array_map(static fn ($r) => [$r['address_id'], $r['number'], (int) $r['is_default_shipping'], (int) $r['is_default_billing']], $rows2), 'o endereço que ficou mantém o id e vira o padrão');
assertSame(['O endereço 999999 não é deste cliente.'], $anaPost([['address_id' => '999999'] + $addr]), 'endereço de outro cliente é recusado');
assertSame(true, str_contains($admin->request('/admin/customer/edit?id=' . $anaId)['body'], 'value="Avenida Paulista"'), 'form mostra o endereço salvo');
// address fields are system attributes of customer_address (CUSTOMER-EAV-PLAN delivery 5)
$addrValues = static fn (array $ids) => (int) $pdo->query('SELECT COUNT(*) FROM eav_value_varchar v JOIN eav_attribute a USING (attribute_id) JOIN eav_entity_type et USING (entity_type_id)
    WHERE et.code = \'customer_address\' AND v.entity_id IN (' . implode(',', array_map('intval', $ids)) . ')')->fetchColumn();
assertSame([6, 0, 0], [$addrValues([$rows[0]['address_id']]), $addrValues([$rows[1]['address_id']]),
    (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'customer_address' AND column_name NOT IN ('address_id', 'customer_id', 'is_default_shipping', 'is_default_billing', 'created_at', 'updated_at')")->fetchColumn()],
    'endereço: campos só no EAV, sem colunas legacy_*; o removido perde os valores');
// the address block is built from the address set, like the customer's groups (ADDRESS-EAV)
$addrAttr = static fn (string $code) => (int) $pdo->query("SELECT attribute_id FROM eav_attribute JOIN eav_entity_type et USING (entity_type_id) WHERE et.code = 'customer_address' AND eav_attribute.code = '{$code}'")->fetchColumn();
$editable = static fn (string $code) => AttributeService::editable((new AttributeService())->get($addrAttr($code))->getData());
assertSame([['label', 'sort_order'], ['label', 'sort_order', 'is_visible', 'is_required']], [$editable('postcode'), $editable('telephone')],
    'endereço: CEP, rua, número, bairro, cidade e UF fixos; complemento, destinatário e telefone mudam também visibilidade e obrigatório');
assertSame('INPUT_TYPE_INVALID', $throws(static fn () => $service->create(['entity_type' => 'customer_address', 'code' => 'selftest_foto', 'label' => 'Selftest foto fachada', 'input_type' => 'image'])),
    'endereço: não aceita campo de imagem');
$refId = (int) $service->create(['entity_type' => 'customer_address', 'code' => 'selftest_referencia', 'label' => 'Ponto de referência selftest', 'input_type' => 'text'])->getId();
$pdo->exec("INSERT INTO eav_attribute_set_item (attribute_set_id, attribute_id, group_name, sort_order)
    SELECT attribute_set_id, {$refId}, 'Complemento e contato', 99 FROM eav_attribute_set WHERE is_default = 1 AND entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE code = 'customer_address')");
$anaPage = $admin->request('/admin/customer/edit?id=' . $anaId)['body'];
assertSame([true, true, false], [
    str_contains($anaPage, 'name="addresses[0][selftest_referencia]"'),
    (bool) preg_match('/name="addresses\[0\]\[telephone\]"[^>]*data-mask="phone"/', $anaPage),
    (bool) preg_match('/name="addresses\[\d+\]\[[a-z_]+\]"[^>]*required/', $anaPage),
], 'form de cliente: o endereço mostra os campos do conjunto (com máscara), sem required no navegador');
assertSame(['Cliente atualizado com sucesso.'], $anaPost([['address_id' => $rows[0]['address_id'], 'number' => '1500', 'selftest_referencia' => 'Perto do metrô'] + $addr]), 'endereço salvo com o campo novo');
assertSame('Perto do metrô', (new AddressService())->getList($anaId)[0]['selftest_referencia'] ?? null, 'o campo novo vem na lista de endereços');
$pdo->exec("UPDATE eav_attribute SET is_required = 1 WHERE attribute_id = {$refId}");
assertSame([["Endereço 1: 'Ponto de referência selftest' é obrigatório."], ['Cliente atualizado com sucesso.']], [
    $anaPost([['address_id' => $rows[0]['address_id'], 'number' => '1500'] + $addr]),
    $anaPost([['address_id' => $rows[0]['address_id'], 'number' => '1500', 'selftest_referencia' => 'Perto do metrô'] + $addr, ['postcode' => '', 'street' => '', 'selftest_referencia' => '']]),
], 'endereço: campo obrigatório do conjunto é validado; a linha em branco continua ignorada');
$pdo->exec("UPDATE eav_attribute SET is_required = 0 WHERE attribute_id = {$refId}");

assertSame(['Já existe um cliente com este e-mail neste website.'], $saveCustomer(['email' => 'ANA@selftest.local', 'cpf' => '111.444.777-35']), 'e-mail repetido no website é recusado');
assertSame(['Já existe um cliente com este CPF neste website.'], $saveCustomer(['email' => 'outra@selftest.local']), 'CPF repetido no website é recusado');
assertSame(['Cliente criado com sucesso.'], $saveCustomer(['website_id' => $website2]), 'o mesmo e-mail e CPF em outro website pode');
// registration fields as system attributes (CUSTOMER-EAV-PLAN): values in EAV, e-mail and CPF keyed by website
$custAttr = static fn (string $code) => (int) $pdo->query("SELECT attribute_id FROM eav_attribute JOIN eav_entity_type et USING (entity_type_id) WHERE et.code = 'customer' AND eav_attribute.code = '{$code}'")->fetchColumn();
assertSame([[['email', $websiteId], ['taxvat', $websiteId]], 0, ['ana@selftest.local', 'Ana']], [
    array_map(static fn ($r) => [$r[0], (int) $r[1]], $pdo->query("SELECT a.code, u.scope_id FROM eav_unique_value u JOIN eav_attribute a USING (attribute_id) WHERE u.entity_id = {$anaId} AND a.code IN ('email', 'taxvat') ORDER BY a.code")->fetchAll(PDO::FETCH_NUM)),
    (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'customer_entity' AND column_name LIKE 'legacy\\_%'")->fetchColumn(),
    $pdo->query("SELECT string_agg(value, ',' ORDER BY attribute_id) FROM eav_value_varchar WHERE entity_id = {$anaId} AND attribute_id IN ({$custAttr('email')}, {$custAttr('firstname')})")->fetchColumn() === 'ana@selftest.local,Ana' ? ['ana@selftest.local', 'Ana'] : null,
], 'cliente: e-mail, nome e CPF gravados no EAV, com a chave única no website; sem colunas legacy_*');
try {
    $pdo->exec("INSERT INTO eav_value_varchar (attribute_id, entity_id, value) VALUES ({$custAttr('email')}, 999999, 'direto@selftest.local')");
    $raw = 'gravou';
} catch (PDOException $e) {
    $raw = $e->getCode();
}
assertSame('23505', $raw, 'cliente: e-mail gravado direto no banco, sem a chave única, é recusado');
// another save committed the key between the form and this save: the check inside the transaction sees it
$pdo->exec("INSERT INTO eav_unique_value (attribute_id, entity_id, scope_id, value_key) VALUES ({$custAttr('email')}, 999999, {$websiteId}, 'corrida@selftest.local')");
$race = $saveCustomer(['email' => 'Corrida@selftest.local', 'cpf' => '111.444.777-35']);
$pdo->exec('DELETE FROM eav_unique_value WHERE entity_id = 999999');
assertSame(['Já existe um cliente com este e-mail neste website.'], $race, 'cliente: e-mail já com chave de outro cadastro recebe EMAIL_DUPLICATE');
assertSame(['ATTRIBUTE_SYSTEM_PROTECTED', 'ATTRIBUTE_SYSTEM_PROTECTED'], [
    $throws(static fn () => $service->delete($custAttr('email'))),
    $throws(static fn () => $service->update($custAttr('customer_type'), ['label' => 'Tipo', 'options' => [['value' => 'pf', 'label' => 'Pessoa física']]])),
], 'cliente: e-mail não é removido e o tipo não perde a opção pj');
// registration fields have a fixed place in the form: only label and position change
assertSame('ATTRIBUTE_FIXED', $throws(static fn () => $setService->update($custSet, ['label' => 'Cli', 'items' => [$custAttr('email') => ['in' => 1]]])),
    'cliente: campo de cadastro não entra em conjunto');
$service->update($custAttr('trade_name'), ['label' => 'Selftest fantasia', 'is_visible' => 0]);
$fantasia = [(int) $pdo->query('SELECT is_visible FROM eav_attribute WHERE attribute_id = ' . $custAttr('trade_name'))->fetchColumn(),
    str_contains($admin->request('/admin/customer/create?set=' . $custSet)['body'], 'Selftest fantasia')];
$service->update($custAttr('trade_name'), ['label' => 'Nome fantasia']);
assertSame([1, true], $fantasia, 'cliente: campo de cadastro não some; o rótulo editado em Atributos aparece no formulário');

$pj = ['customer_type' => 'pj', 'email' => 'empresa@selftest.local', 'cnpj' => '11.222.333/0001-81', 'company_name' => 'Selftest Ltda', 'state_registration' => '123.456.789'];
assertSame(['Informe um CNPJ válido.'], $saveCustomer(['cnpj' => '529.982.247-25'] + $pj), 'PJ não aceita CPF');
assertSame(['Razão social é obrigatória (máx. 255 caracteres).'], $saveCustomer(['company_name' => ''] + $pj), 'PJ sem razão social é recusada');
assertSame(['Informe a inscrição estadual ou marque "Isento".'], $saveCustomer(['state_registration' => ''] + $pj), 'PJ sem inscrição estadual nem isento é recusada');
assertSame(['Cliente criado com sucesso.'], $saveCustomer(['state_registration' => '', 'is_ie_exempt' => '1', 'dob' => '1990-01-01'] + $pj), 'PJ isenta criada');
$emp = $custRow('empresa@selftest.local');
assertSame(['11222333000181', null, null, 1], [$emp['taxvat'], $emp['dob'], $emp['state_registration'], (int) $emp['is_ie_exempt']], 'PJ grava o CNPJ e ignora o nascimento');
[, $msgs] = $admin->submit('/admin/customer/edit?id=' . $emp['customer_id'], '/admin/customer/save', ['customer_id' => $emp['customer_id'], 'website_id' => $websiteId, 'group_id' => $custGroup,
    'email' => 'empresa@selftest.local', 'firstname' => 'Bia', 'lastname' => 'Souza', 'customer_type' => 'pf', 'cpf' => '111.444.777-35', 'company_name' => 'Selftest Ltda']);
$emp = $custRow('empresa@selftest.local');
assertSame([['Cliente atualizado com sucesso.'], 'pf', '11144477735', null, 0, 0], [$msgs, $emp['customer_type'], $emp['taxvat'], $emp['company_name'], (int) $emp['is_ie_exempt'], (int) $emp['is_active']], 'virar PF limpa os campos de PJ');

$list = $admin->request('/admin/customer/index?q=529.982.247-25')['body'];
assertSame([2, true], [substr_count($list, '/admin/customer/edit?id='), str_contains($list, '529.982.247-25')], 'busca por CPF formatado, lista mostra o CPF com máscara');
assertSame(1, substr_count($admin->request('/admin/customer/index?q=selftest.local&website_id=' . $website2)['body'], '/admin/customer/edit?id='), 'filtro por website');
assertSame(403, $dash->request('/admin/customer/index')['status'], 'perfil sem Clientes recebe 403');
assertSame([true, false], [str_contains($admin->request('/admin/index')['body'], '<h2>Clientes</h2>'), str_contains($dash->request('/admin/index')['body'], '<h2>Clientes</h2>')], 'card Clientes no painel só para quem tem Clientes');

// --- GraphQL: customers (admin only) --------------------------------------------------------------
$custGql = '{ customer(email: "ANA@selftest.local", websiteId: "' . $websiteId . '") { id email type taxvat addresses { number is_default_shipping } } }';
assertSame(['UNAUTHENTICATED', 'FORBIDDEN'], [$gql($guest, $custGql)['errors'][0]['extensions']['code'] ?? null, $gql($dash, $custGql)['errors'][0]['extensions']['code'] ?? null], 'GraphQL cliente: visitante e perfil sem Clientes são negados');
assertSame(['id' => (string) $anaId, 'email' => 'ana@selftest.local', 'type' => 'PF', 'taxvat' => '52998224725', 'addresses' => [['number' => '1500', 'is_default_shipping' => true]]],
    $gql($admin, $custGql)['data']['customer'] ?? null, 'GraphQL: cliente por e-mail (sem diferenciar maiúsculas), com endereços');
$createCust = 'mutation($a: [CustomerAddressInput!]) { createCustomer(websiteId: "' . $websiteId . '", groupId: "' . $custGroup . '", attributeSetId: "' . $custSet . '", email: "api@selftest.local",
    firstname: "Api", lastname: "Cliente", type: PF, taxvat: "390.533.447-05", attributes: [{code: "selftest_prof", value: "Dev"}, {code: "gender", value: "f"}], addresses: $a)
    { id attributes { code value option_label } addresses { id postcode is_default_billing } } }';
$apiAddr = ['postcode' => '01310-100', 'street' => 'Avenida Paulista', 'number' => '10', 'district' => 'Bela Vista', 'city' => 'São Paulo', 'region' => 'SP'];
$created = $gql($admin, $createCust, ['a' => [$apiAddr, ['number' => '20', 'isDefaultBilling' => true] + $apiAddr]])['data']['createCustomer'] ?? [];
$attrOf = static fn (array $c) => array_column($c['attributes'] ?? [], null, 'code');
assertSame([['code' => 'gender', 'value' => 'f', 'option_label' => 'Feminino'], 'Dev', ['01310100', '01310100'], [false, true]],
    [$attrOf($created)['gender'] ?? null, $attrOf($created)['selftest_prof']['value'] ?? null, array_column($created['addresses'] ?? [], 'postcode'), array_column($created['addresses'] ?? [], 'is_default_billing')],
    'GraphQL: createCustomer com atributos, rótulo da opção e endereços');
$apiId = $created['id'] ?? '0';
$updated = $gql($admin, 'mutation { updateCustomer(id: "' . $apiId . '", firstname: "Api2") { firstname taxvat attributes { code value } addresses { id } } }')['data']['updateCustomer'] ?? [];
assertSame(['Api2', '39053344705', 'Dev', 2], [$updated['firstname'] ?? null, $updated['taxvat'] ?? null, $attrOf($updated)['selftest_prof']['value'] ?? null, count($updated['addresses'] ?? [])], 'GraphQL: updateCustomer parcial mantém atributos e endereços');
$keep = $created['addresses'][1]['id'] ?? '0';
$updated = $gql($admin, 'mutation($a: [CustomerAddressInput!]) { updateCustomer(id: "' . $apiId . '", addresses: $a) { addresses { id is_default_shipping } } }', ['a' => [['id' => $keep] + $apiAddr]])['data']['updateCustomer'] ?? [];
assertSame([['id' => $keep, 'is_default_shipping' => true]], $updated['addresses'] ?? null, 'GraphQL: a lista de endereços enviada substitui a anterior');
assertSame(['EMAIL_DUPLICATE', 'ATTRIBUTE_NOT_FOUND', 'ADDRESS_INVALID'], [
    $gql($admin, 'mutation { updateCustomer(id: "' . $apiId . '", email: "ana@selftest.local") { id } }')['errors'][0]['extensions']['code'] ?? null,
    $gql($admin, 'mutation { updateCustomer(id: "' . $apiId . '", attributes: [{code: "sku", value: "x"}]) { id } }')['errors'][0]['extensions']['code'] ?? null,
    $gql($admin, 'mutation($a: [CustomerAddressInput!]) { updateCustomer(id: "' . $apiId . '", addresses: $a) { id } }', ['a' => [['region' => 'XX'] + $apiAddr]])['errors'][0]['extensions']['code'] ?? null,
], 'GraphQL: erros de cliente com código');
assertSame([3, 1], [
    $gql($admin, '{ customers(search: "selftest.local", websiteId: "' . $websiteId . '") { total } }')['data']['customers']['total'] ?? null,
    $gql($admin, '{ customers(search: "390.533.447-05", type: PF, isActive: true) { total } }')['data']['customers']['total'] ?? null,
], 'GraphQL: customers com busca e filtros');
assertSame([true, ['customer' => null]], [
    $gql($admin, 'mutation { deleteCustomer(id: "' . $apiId . '") }')['data']['deleteCustomer'] ?? null,
    $gql($admin, '{ customer(id: "' . $apiId . '") { id } }')['data'] ?? [],
], 'GraphQL: deleteCustomer');

[, $msgs] = $admin->submit('/admin/customergroup/index', '/admin/customergroup/delete', ['customer_group_id' => $custGroup]);
assertSame(['Não é possível remover: há clientes neste grupo.'], $msgs, 'grupo com clientes não é removido');
[, $msgs] = $admin->submit('/admin/customer/index', '/admin/customer/delete', ['customer_id' => $anaId]);
assertSame([['Cliente removido com sucesso.'], false, [], [], 0], [$msgs, $custRow('ana@selftest.local'), $values->load('customer', $anaId), $addrRows(), $addrValues([$rows[0]['address_id']])], 'cliente removido com os seus valores e endereços');

// --- modules on/off -------------------------------------------------------------------------------
$toggle = static fn (string $module, int $enabled) => $admin->submit('/admin/config/index', '/admin/config/module', ['module' => $module, 'enabled' => (string) $enabled])[1];
$configPage = $admin->request('/admin/config/index')['body'];
assertSame([true, true, true, true], [
    (bool) preg_match('/name="module" value="iter_payment">\s*<input type="hidden" name="enabled" value="0">/', $configPage),
    str_contains($configPage, 'Essencial'),
    str_contains($configPage, 'Usado por Catalog'),
    str_contains($configPage, 'Usado por Inventory'),
], 'lista de módulos: botão Desativar, essenciais e usados por outro sem botão');
assertSame(['O módulo Core é essencial e não pode ser desativado.'], $toggle('iter_core', 0), 'módulo essencial não é desativado');
assertSame(['Desative antes os módulos que dependem de Eav: Catalog, Customer.'], $toggle('iter_eav', 0), 'módulo com dependentes ativos não é desativado');
assertSame(['Desative antes os módulos que dependem de Catalog: Inventory, Sales.'], $toggle('iter_catalog', 0), 'Catalog não é desativado com Inventory e Sales ativos');
assertSame(['Desative antes os módulos que dependem de Sales: Shipping, Payment.'], $toggle('iter_sales', 0), 'Sales não é desativado com Shipping e Payment ativos');
assertSame(['Módulo Payment desativado. Os dados dele continuam no banco.'], $toggle('iter_payment', 0), 'Payment desativado pelo admin');
assertSame(true, str_starts_with($gql($admin, '{ cart { paymentMethod { code } } }')['errors'][0]['message'] ?? '', 'Cannot query field "paymentMethod" on type "Cart".'), 'Payment desativado: sem pagamento no carrinho');
assertSame(['Módulo Shipping desativado. Os dados dele continuam no banco.'], $toggle('iter_shipping', 0), 'Shipping desativado pelo admin');
assertSame([true, true], [
    str_starts_with($gql($admin, '{ cart { shippingMethod { code } } }')['errors'][0]['message'] ?? '', 'Cannot query field "shippingMethod" on type "Cart".'),
    str_starts_with($gql($admin, '{ shippingTableRates { price } }')['errors'][0]['message'] ?? '', 'Cannot query field "shippingTableRates" on type "Query".'),
], 'Shipping desativado: sem frete no carrinho nem faixas na API');
assertSame(['Módulo Sales desativado. Os dados dele continuam no banco.'], $toggle('iter_sales', 0), 'Sales desativado pelo admin');
assertSame('Cannot query field "cart" on type "Query".', $gql($admin, '{ cart { total } }')['errors'][0]['message'] ?? null, 'Sales desativado: sem carrinho na API');
assertSame(['Módulo Inventory desativado. Os dados dele continuam no banco.'], $toggle('iter_inventory', 0), 'Inventory desativado pelo admin');
assertSame([false, false, 404, 'Cannot query field "stock" on type "Product".'], [
    str_contains($admin->request('/admin/product/edit?id=' . $estId)['body'], '/admin/stock/save'),
    str_contains($admin->request('/admin/index')['body'], '/admin/source/index'),
    $admin->request('/admin/source/index')['status'],
    $gql($admin, '{ products { items { stock { salable_quantity } } } }')['errors'][0]['message'] ?? null,
], 'Inventory desativado: sem painel, menu, rotas e campos na API');
assertSame(['Módulo Catalog desativado. Os dados dele continuam no banco.'], $toggle('iter_catalog', 0), 'módulo desativado pelo admin');
assertSame([404, false, 'Cannot query field "products" on type "Query".'], [
    $admin->request('/admin/product/index')['status'],
    str_contains($admin->request('/admin/index')['body'], '/admin/product/index'),
    $gql($admin, '{ products { total } }')['errors'][0]['message'] ?? null,
], 'módulo desativado: sem rotas, sem menu e sem API');
assertSame(true, (bool) $productRow('selftest-up'), 'desativar não apaga os dados');
assertSame(['Módulo Customer desativado. Os dados dele continuam no banco.'], $toggle('iter_customer', 0), 'Customer desativado pelo admin');
assertSame([404, false, 'Cannot query field "customers" on type "Query". Did you mean "stores"?'], [$admin->request('/admin/customer/index')['status'], str_contains($admin->request('/admin/index')['body'], '/admin/customer/index'),
    $gql($admin, '{ customers { total } }')['errors'][0]['message'] ?? null], 'Customer desativado: sem rotas, sem menu e sem API');
$toggle('iter_eav', 0);
assertSame(['Ative antes os módulos de que Catalog depende: Eav.'], $toggle('iter_catalog', 1), 'módulo não é ativado sem as dependências');
$toggle('iter_eav', 1);
assertSame([true, 200], [str_starts_with($toggle('iter_catalog', 1)[0] ?? '', 'Módulo Catalog ativado.'), $admin->request('/admin/product/index')['status']], 'módulo reativado volta a funcionar');
$toggle('iter_inventory', 1);
$toggle('iter_customer', 1);
$toggle('iter_sales', 1);
$toggle('iter_shipping', 1);
$toggle('iter_payment', 1);
assertSame('7', (new StockService())->getSalable([$estId])[$estId], 'Inventory reativado: o estoque continua lá');
assertSame($savedModules, file_get_contents(ETC . 'config.json'), 'config.json volta ao que era');
assertSame(403, $dash->request('/admin/config/index')['status'], 'perfil sem Configurações não chega aos módulos');

assertSame(file_get_contents(__DIR__ . '/../schema.graphql'), GraphQL\Utils\SchemaPrinter::doPrint(Iter\Core\GraphQl\Schema::build()), 'schema.graphql em dia com a API (rode php bin/schema-dump.php)');

// --- GraphQL: admin login by token ------------------------------------------------------------
$api = static function (string $query, ?string $token = null) use ($base): array { // no cookies: a separate app
    $ch = curl_init($base . '/core/graphql/index');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POSTFIELDS => json_encode(['query' => $query]),
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $token === null ? [] : ["Authorization: Bearer {$token}"])]);
    $body = json_decode((string) curl_exec($ch), true);

    return [curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $body];
};
$login = static fn (string $password) => $api('mutation { generateAdminToken(username: "selftest_admin", password: "' . $password . '") { token expires_at user { username role } } }');
[, $r] = $login('errada1234');
assertSame(['INVALID_CREDENTIALS', 1], [$r['errors'][0]['extensions']['code'] ?? null, (int) $pdo->query("SELECT failures_num FROM admin_user WHERE user_id = {$adminId}")->fetchColumn()], 'API: senha errada é recusada e conta como falha');
[, $r] = $login(PASSWORD);
$token = $r['data']['generateAdminToken']['token'] ?? '';
assertSame([64, 'selftest_admin', 0], [strlen($token), $r['data']['generateAdminToken']['user']['username'] ?? null, (int) $pdo->query("SELECT failures_num FROM admin_user WHERE user_id = {$adminId}")->fetchColumn()], 'API: login devolve o token');
assertSame([null, 'selftest_admin'], [$api('{ currentAdmin { username } }')[1]['data']['currentAdmin'], $api('{ currentAdmin { username } }', $token)[1]['data']['currentAdmin']['username'] ?? null], 'API: o token identifica o admin sem sessão');
$update = 'mutation { updateProduct(id: "0") { id } }';
assertSame(['Acesso negado.', 'PRODUCT_NOT_FOUND'], [$api($update)[1]['errors'][0]['message'] ?? null, $api($update, $token)[1]['errors'][0]['extensions']['code'] ?? null], 'API: o token dá as permissões do admin');
assertSame([401, 'TOKEN_INVALID'], [$api('{ currentAdmin { username } }', 'nada')[0], $api('{ currentAdmin { username } }', 'nada')[1]['errors'][0]['extensions']['code'] ?? null], 'API: token desconhecido é recusado');
assertSame([true, 401], [$api('mutation { revokeAdminToken }', $token)[1]['data']['revokeAdminToken'] ?? null, $api('{ currentAdmin { username } }', $token)[0]], 'API: logout revoga o token');
[, $r] = $login(PASSWORD);
$pdo->exec("UPDATE admin_api_token SET expires_at = NOW() - INTERVAL '1 second' WHERE user_id = {$adminId}");
assertSame(401, $api('{ currentAdmin { username } }', $r['data']['generateAdminToken']['token'])[0], 'API: token vencido é recusado');

$pdo->exec("DELETE FROM api_rate_limit WHERE rate_key LIKE 'admin_login:%'");
$pdo->exec("INSERT INTO core_config_data (scope, scope_id, path, value) VALUES ('default', 0, 'admin/security/api_login_per_minute', '2') ON CONFLICT (scope, scope_id, path) DO UPDATE SET value = '2'");
$codes = array_map(static fn () => $login('errada1234'), [1, 2, 3]);
assertSame([[200, 'INVALID_CREDENTIALS'], [200, 'INVALID_CREDENTIALS'], [429, 'TOO_MANY_REQUESTS']], array_map(static fn ($r) => [$r[0], $r[1]['errors'][0]['extensions']['code'] ?? null], $codes), 'API: login passa do limite por IP e recebe 429');
$pdo->exec("DELETE FROM api_rate_limit WHERE rate_key LIKE 'admin_login:%'");
$pdo->exec("UPDATE admin_user SET failures_num = 0, lock_expires = NULL WHERE user_id = {$adminId}");

// --- API: storefront customer login ---------------------------------------------------------------
$pdo->exec("DELETE FROM api_rate_limit WHERE rate_key LIKE 'customer_login:%'");
$register = static fn (string $email, string $cpf, string $password = 'segredo123') => $api('mutation { registerCustomer(email: "' . $email . '", password: "' . $password . '", firstname: "Lu", lastname: "Loja", type: PF, taxvat: "' . $cpf . '")
    { token expires_at confirmationRequired customer { id email groupId } } }');
$custLogin = static fn (string $password) => $api('mutation { generateCustomerToken(email: "LOJA@selftest.local", password: "' . $password . '") { token customer { email } } }');
assertSame('PASSWORD_INVALID', $register('loja@selftest.local', '390.533.447-05', 'curta')[1]['errors'][0]['extensions']['code'] ?? null, 'API cliente: senha curta é recusada');
$reg = $register('loja@selftest.local', '390.533.447-05')[1]['data']['registerCustomer'] ?? [];
$general = $pdo->query("SELECT customer_group_id FROM customer_group WHERE code = 'general'")->fetchColumn();
assertSame([64, false, 'loja@selftest.local', (string) $general], [strlen((string) ($reg['token'] ?? '')), $reg['confirmationRequired'] ?? null, $reg['customer']['email'] ?? null, $reg['customer']['groupId'] ?? null], 'API cliente: cadastro ativo na hora, no grupo Geral, já com token');
assertSame([200, 'loja@selftest.local'], [$api('{ me { email } }', $reg['token'] ?? '')[0], $api('{ me { email } }', $reg['token'] ?? '')[1]['data']['me']['email'] ?? null], 'API cliente: o token identifica o cliente em me');
assertSame([null, null, 'UNAUTHENTICATED'], [$api('{ me { email } }')[1]['data']['me'] ?? null, $api('{ currentAdmin { username } }', $reg['token'] ?? '')[1]['data']['currentAdmin'] ?? null,
    $api('{ customers { total } }', $reg['token'] ?? '')[1]['errors'][0]['extensions']['code'] ?? null], 'API cliente: sem token, me é null; o token do cliente não dá acesso de admin');
assertSame([['INVALID_CREDENTIALS', 'E-mail ou senha inválidos.'], 'loja@selftest.local'], [
    [$custLogin('errada123')[1]['errors'][0]['extensions']['code'] ?? null, $custLogin('errada123')[1]['errors'][0]['message'] ?? null],
    $custLogin('segredo123')[1]['data']['generateCustomerToken']['customer']['email'] ?? null,
], 'API cliente: login com senha errada é recusado, com a certa entra (e-mail sem diferenciar maiúsculas)');
$old = $custLogin('segredo123')[1]['data']['generateCustomerToken']['token'] ?? '';
$new = $api('mutation { refreshCustomerToken { token } }', $old)[1]['data']['refreshCustomerToken']['token'] ?? '';
assertSame([64, 401, 200], [strlen($new), $api('{ me { id } }', $old)[0], $api('{ me { id } }', $new)[0]], 'API cliente: refresh troca o token e o antigo deixa de valer');
assertSame([true, 401], [$api('mutation { revokeCustomerToken }', $new)[1]['data']['revokeCustomerToken'] ?? null, $api('{ me { id } }', $new)[0]], 'API cliente: logout revoga o token');
assertSame('EMAIL_DUPLICATE', $register('loja@selftest.local', '529.982.247-25')[1]['errors'][0]['extensions']['code'] ?? null, 'API cliente: e-mail repetido no website é recusado');

// "Minha conta" (API-PLAN delivery 7)
$pdo->exec("DELETE FROM api_rate_limit WHERE rate_key LIKE 'customer_login:%'");
$t = $reg['token'] ?? '';
$gqlCode = static fn (array $r) => $r[1]['errors'][0]['extensions']['code'] ?? null;
$updateMe = static fn (string $args) => $api('mutation { updateMe(' . $args . ') { email firstname } }', $t);
assertSame(['UNAUTHENTICATED', ['email' => 'loja@selftest.local', 'firstname' => 'Luiza'], 'INVALID_CREDENTIALS', 'INVALID_CREDENTIALS'], [
    $gqlCode($api('mutation { updateMe(firstname: "X") { id } }')),
    $updateMe('firstname: "Luiza"')[1]['data']['updateMe'] ?? null,
    $gqlCode($updateMe('email: "loja2@selftest.local"')),
    $gqlCode($updateMe('taxvat: "529.982.247-25", currentPassword: "errada123"')),
], 'API minha conta: só com login; muda o nome; e-mail e CPF só com a senha atual');
assertSame(['loja2@selftest.local', 'loja@selftest.local'], [
    $updateMe('email: "Loja2@selftest.local", currentPassword: "segredo123"')[1]['data']['updateMe']['email'] ?? null,
    $updateMe('email: "loja@selftest.local", currentPassword: "segredo123"')[1]['data']['updateMe']['email'] ?? null,
], 'API minha conta: troca o e-mail com a senha');
$t2 = $custLogin('segredo123')[1]['data']['generateCustomerToken']['token'] ?? '';
$changePassword = static fn (string $current, string $new) => $api('mutation { changeMyPassword(currentPassword: "' . $current . '", newPassword: "' . $new . '") }', $t);
assertSame(['INVALID_CREDENTIALS', true, 401, 200, 64], [
    $gqlCode($changePassword('errada123', 'novasenha1')),
    $changePassword('segredo123', 'novasenha1')[1]['data']['changeMyPassword'] ?? null,
    $api('{ me { id } }', $t2)[0],
    $api('{ me { id } }', $t)[0],
    strlen((string) ($custLogin('novasenha1')[1]['data']['generateCustomerToken']['token'] ?? '')),
], 'API minha conta: troca a senha; os outros logins caem, o atual continua');
$changePassword('novasenha1', 'segredo123');
$addrFields = 'id number is_default_shipping is_default_billing';
$myAddr = static fn (string $mutation, string $args) => $api("mutation { {$mutation}({$args}) { {$addrFields} } }", $t);
$addrIn = static fn (string $number, string $extra = '') => 'address: {postcode: "01310-100", street: "Av Paulista", number: "' . $number . '", district: "Bela Vista", city: "São Paulo", region: "SP"' . $extra . '}';
$flags = static fn (array $r, string $key) => array_map(static fn ($a) => [$a['number'], $a['is_default_shipping'], $a['is_default_billing']], $r[1]['data'][$key] ?? []);
$a1 = $myAddr('createMyAddress', $addrIn('1'))[1]['data']['createMyAddress'][0]['id'] ?? '';
assertSame([['2', true, false], ['1', false, true]], $flags($myAddr('createMyAddress', $addrIn('2', ', isDefaultShipping: true')), 'createMyAddress'), 'API minha conta: novo endereço marcado vira o padrão de entrega');
assertSame([['1', true, true], ['2', false, false]], $flags($myAddr('setMyDefaultAddress', 'id: "' . $a1 . '", billing: false'), 'setMyDefaultAddress'), 'API minha conta: define o padrão de entrega sem tirar o de cobrança');
assertSame([['10', true, true], ['2', false, false]], $flags($myAddr('updateMyAddress', $addrIn('10', ', id: "' . $a1 . '"')), 'updateMyAddress'), 'API minha conta: edita o endereço e mantém os padrões');
assertSame([[['2', true, true]], 'ADDRESS_NOT_FOUND', 'ADDRESS_NOT_FOUND'], [
    $flags($myAddr('deleteMyAddress', 'id: "' . $a1 . '"'), 'deleteMyAddress'),
    $gqlCode($myAddr('deleteMyAddress', 'id: "999999"')),
    $gqlCode($myAddr('updateMyAddress', $addrIn('3', ', id: "999999"'))),
], 'API minha conta: remove, e o que sobra vira o padrão; endereço de outro é recusado');
$withRef = $api('mutation { createMyAddress(' . $addrIn('4', ', attributes: [{code: "selftest_referencia", value: "Em frente à praça"}]') . ') { number attributes { code value } } }', $t)[1]['data']['createMyAddress'] ?? [];
assertSame([[['code' => 'selftest_referencia', 'value' => 'Em frente à praça']], 'ATTRIBUTE_NOT_FOUND'], [
    array_values(array_filter(array_map(static fn ($a) => $a['number'] === '4' ? $a['attributes'] : null, $withRef)))[0] ?? null,
    $gqlCode($api('mutation { createMyAddress(' . $addrIn('5', ', attributes: [{code: "nada_disso", value: "x"}]') . ') { id } }', $t)),
], 'API minha conta: o endereço grava e devolve os campos do conjunto; código fora do conjunto é recusado');
$pdo->exec("UPDATE customer_entity SET is_active = 0 WHERE customer_id IN (SELECT customer_id FROM customer_flat WHERE email = 'loja@selftest.local')");
assertSame([401, 'INVALID_CREDENTIALS'], [$api('{ me { id } }', $reg['token'] ?? '')[0], $custLogin('segredo123')[1]['errors'][0]['extensions']['code'] ?? null], 'API cliente: cliente desativado perde o token e não entra');

$pdo->exec("DELETE FROM api_rate_limit WHERE rate_key LIKE 'customer_login:%'");
$pdo->exec("INSERT INTO core_config_data (scope, scope_id, path, value) VALUES ('default', 0, 'customer/account/confirm_email', '1'), ('default', 0, 'customer/account/confirm_url', 'https://front.selftest/confirmar?token={token}')
    ON CONFLICT (scope, scope_id, path) DO UPDATE SET value = EXCLUDED.value");
$reg = $register('conf@selftest.local', '529.982.247-25')[1]['data']['registerCustomer'] ?? [];
$confMail = (string) $pdo->query("SELECT body_html FROM mail_queue WHERE to_email = 'conf@selftest.local' AND template_code = 'customer_confirm_email'")->fetchColumn();
preg_match('#https://front\.selftest/confirmar\?token=([0-9a-f]{64})#', $confMail, $m);
assertSame([true, true, 64], [array_key_exists('token', $reg) && $reg['token'] === null, $reg['confirmationRequired'] ?? null, strlen($m[1] ?? '')], 'API cliente: com a confirmação ligada, o cadastro não traz token e o e-mail leva o link do frontend');
$confLogin = static fn () => $api('mutation { generateCustomerToken(email: "conf@selftest.local", password: "segredo123") { token } }')[1];
assertSame('EMAIL_NOT_CONFIRMED', $confLogin()['errors'][0]['extensions']['code'] ?? null, 'API cliente: sem confirmar o e-mail não entra');
$confirmed = $api('mutation { confirmCustomerEmail(token: "' . ($m[1] ?? '') . '") { token customer { email } } }')[1]['data']['confirmCustomerEmail'] ?? [];
assertSame([64, 'conf@selftest.local', 64, 'TOKEN_INVALID'], [
    strlen((string) ($confirmed['token'] ?? '')), $confirmed['customer']['email'] ?? null, strlen((string) ($confLogin()['data']['generateCustomerToken']['token'] ?? '')),
    $api('mutation { confirmCustomerEmail(token: "' . ($m[1] ?? '') . '") { token } }')[1]['errors'][0]['extensions']['code'] ?? null,
], 'API cliente: o link confirma e já entra; depois o login funciona e o link não vale de novo');

// forgot password (API-PLAN delivery 8)
$pdo->exec("DELETE FROM api_rate_limit WHERE rate_key LIKE 'customer_login:%'");
$pdo->exec("INSERT INTO core_config_data (scope, scope_id, path, value) VALUES ('default', 0, 'customer/account/reset_url', 'https://front.selftest/nova-senha?token={token}')
    ON CONFLICT (scope, scope_id, path) DO UPDATE SET value = EXCLUDED.value");
$requestReset = static fn (string $email) => $api('mutation { requestCustomerPasswordReset(email: "' . $email . '") }')[1]['data']['requestCustomerPasswordReset'] ?? null;
$resetMails = static fn () => $pdo->query("SELECT body_html FROM mail_queue WHERE to_email = 'conf@selftest.local' AND template_code = 'customer_password_reset' ORDER BY message_id")->fetchAll(PDO::FETCH_COLUMN);
$resetToken = static fn (): string => preg_match('#https://front\.selftest/nova-senha\?token=([0-9a-f]{64})#', (string) ($resetMails()[1] ?? $resetMails()[0] ?? ''), $m) ? $m[1] : '';
$resetPassword = static fn (string $token, string $password) => $api('mutation { resetCustomerPassword(token: "' . $token . '", password: "' . $password . '") }');
$session = $confirmed['token'] ?? '';
assertSame([true, true, true, 1, 64], [$requestReset('ninguem@selftest.local'), $requestReset('conf@selftest.local'), $requestReset('conf@selftest.local'), count($resetMails()), strlen($resetToken())],
    'API senha esquecida: sempre true; o e-mail leva o link do frontend e não é reenviado dentro de um minuto');
$link = $resetToken();
assertSame(['PASSWORD_INVALID', true, 401, 'TOKEN_INVALID', 64], [
    $gqlCode($resetPassword($link, 'curta')),
    $resetPassword($link, 'trocada123')[1]['data']['resetCustomerPassword'] ?? null,
    $api('{ me { id } }', $session)[0],
    $gqlCode($resetPassword($link, 'outra12345')),
    strlen((string) ($api('mutation { generateCustomerToken(email: "conf@selftest.local", password: "trocada123") { token } }')[1]['data']['generateCustomerToken']['token'] ?? '')),
], 'API senha esquecida: o link troca a senha uma vez, os logins caem e a nova senha entra');
$requestReset('conf@selftest.local');
$pdo->exec("UPDATE customer_password_reset SET created_at = NOW() - INTERVAL '3 hours'");
assertSame([2, 'TOKEN_INVALID'], [count($resetMails()), $gqlCode($resetPassword($resetToken(), 'trocada456'))], 'API senha esquecida: link vencido é recusado');

// --- API: cart (COMMERCE-PLAN delivery 2) ---------------------------------------------------------
$pdo->exec("DELETE FROM api_rate_limit WHERE rate_key LIKE 'customer_login:%' OR rate_key LIKE 'cart_create:%'");
// its own simple product with 3 in stock (the configurable one was deleted by the catalog tests)
$v0Sku = 'selftest-cart';
$v0 = (int) $productService->create(['attribute_set_id' => $defaultProductSet, 'sku' => $v0Sku, 'name' => 'Caneca', 'is_active' => 1, 'website_ids' => [$defaultWebsite], 'category_ids' => [$baseCategory]], ['price' => '24.9'])->getId();
$stock->save($v0, ['selftest_var_cd' => ['quantity' => '3']]);
// the cart only looks at the type: a product marked "com grade" is enough
$cartParent = (int) $productService->create(['attribute_set_id' => $defaultProductSet, 'sku' => 'selftest-cart-grade', 'name' => 'Blusa grade', 'is_active' => 1, 'website_ids' => [$defaultWebsite], 'category_ids' => [$baseCategory]], ['price' => '10'])->getId();
$pdo->exec("UPDATE catalog_product SET type = 'configurable' WHERE product_id = {$cartParent}");
$v0Price = (float) $pdo->query("SELECT value FROM eav_value_decimal WHERE entity_id = {$v0} AND attribute_id = {$attrId('price')}")->fetchColumn();
$cartFields = '{ isGuest email items { id sku quantity price rowTotal error errorCode } subtotal total hasErrors shippingAddress { postcode city } billingSameAsShipping }';
$cartCall = static fn (string $call, ?string $token = null) => $api("mutation { {$call} {$cartFields} }", $token)[1];
$cartOf = static fn (array $r, string $key) => $r['data'][$key] ?? null; // $r = the response body
$guestCart = $api('mutation { createCart }')[1]['data']['createCart'] ?? '';
$c = '"' . $guestCart . '"';
assertSame([64, 'PRODUCT_IS_CONFIGURABLE', 'PRODUCT_NOT_FOUND', 'QUANTITY_INVALID'], [
    strlen($guestCart),
    $gqlCode([0, $cartCall("addToCart(cartId: {$c}, sku: \"selftest-cart-grade\")")]),
    $gqlCode([0, $cartCall("addToCart(cartId: {$c}, sku: \"selftest-nada\")")]),
    $gqlCode([0, $cartCall("addToCart(cartId: {$c}, sku: \"{$v0Sku}\", quantity: -1)")]),
], 'carrinho: visitante recebe o cartId; produto com grade, inexistente ou quantidade inválida são recusados');
$added = $cartOf($cartCall("addToCart(cartId: {$c}, sku: \"{$v0Sku}\", quantity: 2)"), 'addToCart');
$itemId = $added['items'][0]['id'] ?? '0';
assertSame([true, 2.0, round($v0Price * 2, 2), 'INSUFFICIENT_STOCK', 3.0], [
    $added['isGuest'] ?? null, (float) ($added['items'][0]['quantity'] ?? 0), (float) ($added['subtotal'] ?? 0),
    $gqlCode([0, $cartCall("addToCart(cartId: {$c}, sku: \"{$v0Sku}\", quantity: 2)")]),
    (float) ($cartOf($cartCall("updateCartItem(cartId: {$c}, itemId: \"{$itemId}\", quantity: 3)"), 'updateCartItem')['items'][0]['quantity'] ?? 0),
], 'carrinho: soma ao item, confere o saldo do website (2 + 2 > 3) e muda a quantidade');
$pdo->exec("UPDATE eav_value_decimal SET value = value + 10 WHERE entity_id = {$v0} AND attribute_id = {$attrId('price')}");
$pdo->exec("UPDATE catalog_product SET is_active = 0 WHERE product_id = {$v0}");
$stale = $api("{ cart(cartId: {$c}) {$cartFields} }")[1]['data']['cart'] ?? [];
$pdo->exec("UPDATE catalog_product SET is_active = 1 WHERE product_id = {$v0}");
$pdo->exec("UPDATE eav_value_decimal SET value = value - 10 WHERE entity_id = {$v0} AND attribute_id = {$attrId('price')}");
assertSame([$v0Price + 10, 'PRODUCT_NOT_SALABLE', true], [(float) ($stale['items'][0]['price'] ?? 0), $stale['items'][0]['errorCode'] ?? null, $stale['hasErrors'] ?? null],
    'carrinho: preço lido na hora; produto que saiu de venda fica com erro e marca o carrinho');
assertSame(['CART_NOT_FOUND', 'UNAUTHENTICATED', 'CART_ITEM_NOT_FOUND'], [
    $gqlCode($api('{ cart(cartId: "nada") { total } }')),
    $gqlCode($api('{ cart { total } }')),
    $gqlCode([0, $cartCall("removeCartItem(cartId: {$c}, itemId: \"999999\")")]),
], 'carrinho: cartId desconhecido, sem cartId nem login, item de outro carrinho são recusados');
$addr = 'address: {postcode: "01310-100", street: "Av Paulista", number: "1000", district: "Bela Vista", city: "São Paulo", region: "SP"}';
assertSame(['TAXVAT_INVALID', 'loja-visitante@selftest.local', 'Endereço de entrega: informe o bairro.', ['postcode' => '01310100', 'city' => 'São Paulo'], true], [
    $gqlCode([0, $cartCall("setCartGuestInfo(cartId: {$c}, email: \"x@selftest.local\", firstname: \"Vi\", lastname: \"Sita\", taxvat: \"111.111.111-11\")")]),
    $cartOf($cartCall("setCartGuestInfo(cartId: {$c}, email: \"Loja-Visitante@selftest.local\", firstname: \"Vi\", lastname: \"Sita\", taxvat: \"529.982.247-25\")"), 'setCartGuestInfo')['email'] ?? null,
    $cartCall('setCartShippingAddress(cartId: ' . $c . ', ' . str_replace('district: "Bela Vista", ', '', $addr) . ')')['errors'][0]['message'] ?? null,
    $cartOf($cartCall("setCartShippingAddress(cartId: {$c}, {$addr})"), 'setCartShippingAddress')['shippingAddress'] ?? null,
    $cartOf($cartCall("setCartBillingAddress(cartId: {$c})"), 'setCartBillingAddress')['billingSameAsShipping'] ?? null,
], 'carrinho: dados do visitante validados (CPF, e-mail em minúsculas); endereço com as regras do cliente; cobrança igual à entrega');
// a customer's cart and the merge on login
$ct = $api('mutation { generateCustomerToken(email: "conf@selftest.local", password: "trocada123") { token } }')[1]['data']['generateCustomerToken']['token'] ?? '';
$mine = $cartOf($cartCall("addToCart(sku: \"{$v0Sku}\")", $ct), 'addToCart');
$merged = $cartOf($cartCall("mergeCarts(cartId: {$c})", $ct), 'mergeCarts');
assertSame([false, 'conf@selftest.local', 1.0, 'INSUFFICIENT_STOCK', true, 'CART_NOT_FOUND', 'CART_IS_CUSTOMERS'], [
    $mine['isGuest'] ?? null, $mine['email'] ?? null, (float) ($mine['items'][0]['quantity'] ?? 0),
    $merged['items'][0]['errorCode'] ?? null, isset($merged['shippingAddress']),
    $gqlCode($api("{ cart(cartId: {$c}) { total } }")),
    $gqlCode([0, $cartCall('setCartGuestInfo(email: "a@selftest.local", firstname: "a", lastname: "b", taxvat: "529.982.247-25")', $ct)]),
], 'carrinho do cliente: dados da conta; ao logar, mergeCarts soma os itens do visitante (1 + 3 > 3 fica com erro de estoque), traz o endereço e apaga o do visitante');
assertSame(['ADDRESS_NOT_FOUND', 'UNAUTHENTICATED'], [
    $gqlCode([0, $cartCall('setCartShippingAddress(customerAddressId: "999999")', $ct)]),
    $gqlCode($api('mutation { mergeCarts(cartId: "nada") { total } }')),
], 'carrinho: endereço salvo de outro cliente é recusado; mergeCarts exige login');
$oldCart = $api('mutation { createCart }')[1]['data']['createCart'] ?? '';
$api('mutation { setCartGuestInfo(cartId: "' . $oldCart . '", email: "velho@selftest.local", firstname: "V", lastname: "Elho", taxvat: "529.982.247-25") { total } }');
$pdo->exec('ALTER TABLE sales_quote DISABLE TRIGGER sales_quote_set_updated_at'); // it would set NOW()
$pdo->exec("UPDATE sales_quote SET updated_at = NOW() - INTERVAL '31 days' WHERE token_hash = '" . hash('sha256', $oldCart) . "'");
$pdo->exec('ALTER TABLE sales_quote ENABLE TRIGGER sales_quote_set_updated_at');
assertSame([true, 'CART_NOT_FOUND', false], [
    (new \Iter\Sales\Model\CartService())->cleanGuests(30) >= 1,
    $gqlCode($api('{ cart(cartId: "' . $oldCart . '") { total } }')),
    $gqlCode($api('{ cart { total } }', $ct)) !== null,
], 'limpeza: carrinho de visitante sem uso há mais de 30 dias é apagado; o do cliente fica');

// --- shipping (COMMERCE-PLAN delivery 3) ------------------------------------------------------------
$setConfig = static function (array $values) use ($pdo): void {
    $stmt = $pdo->prepare("INSERT INTO core_config_data (scope, scope_id, path, value) VALUES ('default', 0, ?, ?) ON CONFLICT (scope, scope_id, path) DO UPDATE SET value = EXCLUDED.value");
    foreach ($values as $path => $value) {
        $stmt->execute([$path, $value]);
    }
};
$setConfig(['shipping/tablerate/active' => '1', 'shipping/tablerate/label' => 'Transportadora', 'shipping/freeshipping/active' => '1', 'shipping/freeshipping/min_subtotal' => '100',
    'shipping/freeshipping/days' => '9', 'shipping/pickup/active' => '1', 'shipping/pickup/days' => '1']);
$ratesGql = 'mutation ($r: [ShippingTableRateInput!]!) { saveShippingTableRates(rates: $r) { postcodeFrom postcodeTo weightTo price days } }';
$ratesIn = [['postcodeFrom' => '01000-000', 'postcodeTo' => '09999-999', 'weightTo' => 1, 'price' => 15, 'days' => 3],
    ['postcodeFrom' => '01000000', 'postcodeTo' => '09999999', 'price' => 30, 'days' => 5], ['postcodeFrom' => '20000000', 'postcodeTo' => '29999999', 'price' => 40, 'days' => 7]];
assertSame(['UNAUTHENTICATED', 'SHIPPING_RATE_INVALID', 3, '01000000'], [
    $gql($guest, $ratesGql, ['r' => $ratesIn])['errors'][0]['extensions']['code'] ?? null,
    $gql($admin, $ratesGql, ['r' => [['postcodeFrom' => '09000000', 'postcodeTo' => '01000000', 'price' => 1]]])['errors'][0]['extensions']['code'] ?? null,
    count($gql($admin, $ratesGql, ['r' => $ratesIn])['data']['saveShippingTableRates'] ?? []),
    $gql($admin, '{ shippingTableRates { postcodeFrom } }')['data']['shippingTableRates'][0]['postcodeFrom'] ?? null,
], 'frete: faixas só pelo admin, validadas, gravadas com o CEP sem máscara');
$values->save('product', $v0, ['weight' => '0.4', 'price' => '24.9']); // save() writes the whole set
$shipCart = $api('mutation { createCart }')[1]['data']['createCart'] ?? '';
$sc = '"' . $shipCart . '"';
$shipFields = '{ subtotal total shippingAmount shippingMethod { code } availableShippingMethods { code label amount days } }';
$ship = static fn (string $call) => $api("mutation { {$call} {$shipFields} }")[1];
$codes = static fn (array $r, string $key) => array_column($r['data'][$key]['availableShippingMethods'] ?? [], 'amount', 'code');
$api("mutation { setCartGuestInfo(cartId: {$sc}, email: \"frete@selftest.local\", firstname: \"Fre\", lastname: \"Te\", taxvat: \"529.982.247-25\") { total } }");
assertSame([['pickup' => 0.0], 'SHIPPING_METHOD_UNAVAILABLE'], [
    array_map('floatval', $codes($ship("addToCart(cartId: {$sc}, sku: \"{$v0Sku}\", quantity: 2)"), 'addToCart')),
    $gqlCode([0, $ship("setCartShippingMethod(cartId: {$sc}, code: \"tablerate\")")]),
], 'frete: sem endereço, só a retirada; frete por tabela recusado');
$withAddr = $ship("setCartShippingAddress(cartId: {$sc}, {$addr})")['data']['setCartShippingAddress'] ?? [];
assertSame([['pickup' => 0.0, 'tablerate' => 15.0], 'Transportadora', 3], [
    array_map('floatval', array_column($withAddr['availableShippingMethods'] ?? [], 'amount', 'code')),
    array_column($withAddr['availableShippingMethods'] ?? [], 'label', 'code')['tablerate'] ?? null,
    array_column($withAddr['availableShippingMethods'] ?? [], 'days', 'code')['tablerate'] ?? null,
], 'frete por tabela: 0,8 kg no CEP 01310-100 cai na faixa até 1 kg; a retirada, grátis, vem primeiro');
$chosen = $ship("setCartShippingMethod(cartId: {$sc}, code: \"tablerate\")")['data']['setCartShippingMethod'] ?? [];
$heavier = $ship("addToCart(cartId: {$sc}, sku: \"{$v0Sku}\")")['data']['addToCart'] ?? [];
assertSame([[15.0, 64.8], [30.0, 104.7], false], [
    [(float) ($chosen['shippingAmount'] ?? 0), (float) ($chosen['total'] ?? 0)],
    [(float) ($heavier['shippingAmount'] ?? 0), (float) ($heavier['total'] ?? 0)],
    isset(array_column($heavier['availableShippingMethods'] ?? [], 'amount', 'code')['freeshipping']),
], 'frete: o total soma o frete escolhido; com 1,2 kg vale a faixa sem limite de peso; R$ 74,70 não chega ao frete grátis de R$ 100');
$setConfig(['shipping/freeshipping/min_subtotal' => '70']);
$free = $api("{ cart(cartId: {$sc}) {$shipFields} }")[1]['data']['cart'] ?? [];
assertSame([['freeshipping', 0, 9], 'freeshipping'], [ // JSON gives 0.0 back as 0
    array_values(array_intersect_key(array_column($free['availableShippingMethods'] ?? [], null, 'code')['freeshipping'] ?? [], array_flip(['code', 'amount', 'days']))),
    $free['availableShippingMethods'][1]['code'] ?? null, // the pickup (0, 1 day) comes first
], 'frete grátis: aparece quando o subtotal chega ao valor configurado');
$far = $ship('setCartShippingAddress(cartId: ' . $sc . ', ' . str_replace('01310-100', '30130-000', $addr) . ')')['data']['setCartShippingAddress'] ?? [];
$setConfig(['shipping/pickup/active' => '0', 'shipping/freeshipping/active' => '0']);
assertSame([null, 0.0, (float) ($far['subtotal'] ?? -1), 'Nenhum frete atende o endereço de entrega informado.'], [
    $far['shippingMethod'] ?? null, (float) ($far['shippingAmount'] ?? -1), (float) ($far['total'] ?? 0),
    $ship("setCartShippingMethod(cartId: {$sc}, code: \"tablerate\")")['errors'][0]['message'] ?? null,
], 'frete: endereço fora das faixas tira o frete escolhido e o total volta ao subtotal; sem nenhuma opção, a mensagem diz isso');

// --- payment (COMMERCE-PLAN delivery 4) --------------------------------------------------------------
$payFields = '{ availablePaymentMethods { code label instructions } paymentMethod { code } }';
$pay = static fn (string $call) => $api("mutation { {$call} {$payFields} }")[1];
$setConfig(['payment/pix/active' => '1', 'payment/pix/key' => '', 'payment/pix/instructions' => 'Envie o comprovante.', 'payment/offline/active' => '0']);
assertSame([[], 'PAYMENT_METHOD_UNAVAILABLE', 'Nenhuma forma de pagamento está ativa nesta loja.'], [
    $api("{ cart(cartId: {$sc}) {$payFields} }")[1]['data']['cart']['availablePaymentMethods'] ?? null,
    $gqlCode([0, $pay("setCartPaymentMethod(cartId: {$sc}, code: \"pix\")")]),
    $pay("setCartPaymentMethod(cartId: {$sc}, code: \"pix\")")['errors'][0]['message'] ?? null,
], 'pagamento: Pix ligado sem chave não é oferecido');
$setConfig(['payment/pix/key' => 'pix@selftest.local', 'payment/offline/active' => '1', 'payment/offline/label' => 'Pagar na retirada', 'payment/offline/instructions' => '']);
$chosenPay = $pay("setCartPaymentMethod(cartId: {$sc}, code: \"pix\")")['data']['setCartPaymentMethod'] ?? [];
assertSame([[['pix', 'Pix', "Chave Pix: pix@selftest.local\nEnvie o comprovante."], ['offline', 'Pagar na retirada', null]], 'pix', 'PAYMENT_METHOD_UNAVAILABLE'], [
    array_map(static fn ($m) => array_values($m), $chosenPay['availablePaymentMethods'] ?? []),
    $chosenPay['paymentMethod']['code'] ?? null,
    $gqlCode([0, $pay("setCartPaymentMethod(cartId: {$sc}, code: \"boleto\")")]),
], 'pagamento: Pix com a chave e as instruções, "combinar" com o nome da loja; código fora das opções é recusado');
$setConfig(['payment/pix/active' => '0']);
assertSame(null, $api("{ cart(cartId: {$sc}) {$payFields} }")[1]['data']['cart']['paymentMethod'] ?? null, 'pagamento: forma desligada depois de escolhida sai do carrinho');
$settings = $admin->request('/admin/config/index')['body'];
assertSame([true, true, true], [str_contains($settings, 'payment/pix/key'), str_contains($settings, 'shipping/tablerate/active'), str_contains($settings, 'sales/quote/guest_days')], 'Configurações mostra os grupos Vendas, Frete e Pagamento');

// --- order + placeOrder (COMMERCE-PLAN delivery 5) ---------------------------------------------------
$pdo->exec("DELETE FROM api_rate_limit WHERE rate_key LIKE 'order_place:%'");
$orderFields = '{ id number status statusLabel email taxvat total subtotal shippingAmount shippingMethod paymentMethod paymentInstructions items { sku name options quantity price rowTotal } shippingAddress { postcode city } billingAddress { postcode } }';
$place = static fn (string $cartId) => $api('mutation { placeOrder(cartId: "' . $cartId . '") ' . $orderFields . ' }')[1];
$emptyCart = $api('mutation { createCart }')[1]['data']['createCart'] ?? '';
// a second guest cart wanting the last unit, complete, placed after $sc took the 3
$otherCart = $api('mutation { createCart }')[1]['data']['createCart'] ?? '';
$oc = '"' . $otherCart . '"';
$api("mutation { addToCart(cartId: {$oc}, sku: \"{$v0Sku}\") { total } }");
$noInfo = $place($otherCart);
$setConfig(['sales/checkout/guest' => '0']);
$noLogin = $place($otherCart);
$setConfig(['sales/checkout/guest' => '1']);
$api("mutation { setCartGuestInfo(cartId: {$oc}, email: \"outro@selftest.local\", firstname: \"Ou\", lastname: \"Tro\", taxvat: \"529.982.247-25\") { total } }");
$noAddress = $place($otherCart);
$api("mutation { setCartShippingAddress(cartId: {$oc}, {$addr}) { total } }");
assertSame(['CART_EMPTY', 'CUSTOMER_INFO_REQUIRED', 'LOGIN_REQUIRED', 'SHIPPING_ADDRESS_REQUIRED', 'SHIPPING_METHOD_REQUIRED'], [
    $gqlCode([0, $place($emptyCart)]), $gqlCode([0, $noInfo]), $gqlCode([0, $noLogin]), $gqlCode([0, $noAddress]), $gqlCode([0, $place($shipCart)]),
], 'pedido: o placeOrder diz o que falta (itens, dados do visitante, login exigido pela loja, endereço, frete)');
$setConfig(['shipping/pickup/active' => '1', 'sales/order/prefix' => 'ST-']);
$api("mutation { setCartShippingMethod(cartId: {$sc}, code: \"pickup\") { total } }");
$api("mutation { setCartShippingMethod(cartId: {$oc}, code: \"pickup\") { total } }");
$api("mutation { setCartPaymentMethod(cartId: {$oc}, code: \"offline\") { total } }");
assertSame('PAYMENT_METHOD_REQUIRED', $gqlCode([0, $place($shipCart)]), 'pedido: sem forma de pagamento escolhida é recusado');
$setConfig(['payment/pix/active' => '1']);
$api("mutation { setCartPaymentMethod(cartId: {$sc}, code: \"pix\") { total } }");
$order = $place($shipCart)['data']['placeOrder'] ?? [];
$again = $place($shipCart)['data']['placeOrder'] ?? [];
assertSame([1, 'pending_payment', 'Aguardando pagamento', 'frete@selftest.local', '52998224725', 74.7, 0, 'pickup', 'pix', "Chave Pix: pix@selftest.local\nEnvie o comprovante.",
    [['sku' => $v0Sku, 'name' => 'Caneca', 'options' => null, 'quantity' => 3, 'price' => 24.9, 'rowTotal' => 74.7]], ['postcode' => '30130000', 'city' => 'São Paulo'], '30130000'], [
    preg_match('/^ST-\d{6}$/', (string) ($order['number'] ?? '')), $order['status'] ?? null, $order['statusLabel'] ?? null, $order['email'] ?? null, $order['taxvat'] ?? null,
    $order['total'] ?? null, $order['shippingAmount'] ?? null, $order['shippingMethod'] ?? null, $order['paymentMethod'] ?? null, $order['paymentInstructions'] ?? null,
    $order['items'] ?? null, $order['shippingAddress'] ?? null, $order['billingAddress']['postcode'] ?? null,
], 'pedido: número com o prefixo, aguardando pagamento, com dados, itens, frete, pagamento e endereços copiados do carrinho');
$orderId = (int) ($order['id'] ?? 0);
assertSame([$order['number'] ?? 'x', '0', 'CART_NOT_FOUND', 1, 1], [
    $again['number'] ?? null,
    (new StockService())->getSalable([$v0], $defaultWebsite)[$v0],
    $gqlCode($api("{ cart(cartId: {$sc}) { total } }")),
    (int) $pdo->query("SELECT COUNT(*) FROM sales_order WHERE quote_id = (SELECT quote_id FROM sales_order WHERE order_id = {$orderId})")->fetchColumn(),
    (int) $pdo->query("SELECT COUNT(*) FROM mail_queue WHERE template_code = 'sales_order_placed' AND to_email = 'frete@selftest.local'")->fetchColumn(),
], 'pedido: placeOrder de novo devolve o mesmo pedido (um pedido, um e-mail); o estoque fica reservado e o carrinho fechado');
$mailBody = (string) $pdo->query("SELECT subject || body_html FROM mail_queue WHERE template_code = 'sales_order_placed' AND to_email = 'frete@selftest.local'")->fetchColumn();
assertSame([true, true, true], [str_contains($mailBody, 'Recebemos o seu pedido ' . ($order['number'] ?? 'x')), str_contains($mailBody, 'R$ 74,70'), str_contains($mailBody, 'pix@selftest.local')],
    'pedido: o e-mail "pedido recebido" traz o número, o total e a chave Pix');
$pdo->exec("UPDATE catalog_product SET name = 'Caneca nova' WHERE product_id = {$v0}");
$pdo->exec("UPDATE eav_value_decimal SET value = 99 WHERE entity_id = {$v0} AND attribute_id = {$attrId('price')}");
$copied = (new \Iter\Sales\Model\OrderService())->get($orderId);
$pdo->exec("UPDATE catalog_product SET name = 'Caneca' WHERE product_id = {$v0}");
$pdo->exec("UPDATE eav_value_decimal SET value = 24.9 WHERE entity_id = {$v0} AND attribute_id = {$attrId('price')}");
assertSame(['Caneca', 24.9, 'INSUFFICIENT_STOCK'], [$copied['items'][0]['name'] ?? null, $copied['items'][0]['price'] ?? null,
    $api("{ cart(cartId: {$oc}) { items { errorCode } } }")[1]['data']['cart']['items'][0]['errorCode'] ?? null],
    'pedido: nome e preço não mudam com o catálogo; a reserva tira a unidade do outro carrinho');
assertSame('CART_HAS_ERRORS', $gqlCode([0, $place($otherCart)]), 'pedido: carrinho com item sem estoque é recusado');
$stock->save($v0, ['selftest_var_cd' => ['quantity' => '4']]);
$next = $place($otherCart)['data']['placeOrder']['number'] ?? '';
assertSame(sprintf('ST-%06d', (int) substr((string) ($order['number'] ?? ''), 3) + 1), $next, 'pedido: o número seguinte do website');

// --- order service in the admin (COMMERCE-PLAN delivery 6) ------------------------------------------
$nextId = (int) $pdo->query("SELECT order_id FROM sales_order WHERE increment_id = '{$next}'")->fetchColumn();
$orderPage = static fn (Browser $b, int $id) => $b->request('/admin/order/view?id=' . $id)['body'];
$orderStatus = static fn (int $id) => $pdo->query("SELECT status FROM sales_order WHERE order_id = {$id}")->fetchColumn();
$orderMails = static fn (string $template) => (int) $pdo->query("SELECT COUNT(*) FROM mail_queue WHERE template_code = '{$template}' AND to_email LIKE '%@selftest.local'")->fetchColumn();
$list = $admin->request('/admin/order/index?q=' . urlencode((string) $next))['body'];
assertSame([true, false, true, 403], [
    str_contains($list, (string) $next), str_contains($list, '>' . ($order['number'] ?? 'x') . '<'), str_contains($list, '1 pedido(s) · total R$ 24,90'),
    $dash->request('/admin/order/index')['status'],
], 'pedidos: a lista filtra pelo número e mostra o total do filtro; perfil sem Iter_Sales::order recebe 403');
$page = $orderPage($admin, $orderId);
assertSame([true, true, false, false, true], [str_contains($page, 'Confirmar pagamento'), str_contains($page, 'Cancelar pedido'), str_contains($page, 'Registrar entrega'),
    str_contains($page, 'Concluir'), str_contains($page, 'Chave Pix: pix@selftest.local')], 'pedido: a tela mostra só as ações do status (a pagar: confirmar e cancelar)');
$makeUser('selftest_order', ['Iter_Sales::order']);
$seller = new Browser($base);
$seller->login('selftest_order');
[, $msgs] = $seller->submit('/admin/order/view?id=' . $orderId, '/admin/order/confirm', ['order_id' => $orderId]);
assertSame([false, true, ['O seu perfil não tem permissão para esta ação.'], 'pending_payment'], [
    str_contains($orderPage($seller, $orderId), 'Confirmar pagamento'), str_contains($orderPage($seller, $orderId), 'Comentar'), $msgs, $orderStatus($orderId),
], 'pedido: perfil só com Iter_Sales::order vê e comenta, mas não vê nem confirma o pagamento');
[, $msgs] = $admin->submit('/admin/order/view?id=' . $orderId, '/admin/order/confirm', ['order_id' => $orderId, 'comment' => 'Comprovante pelo WhatsApp']);
$history = $pdo->query("SELECT status, comment, is_customer_notified, admin_user_id FROM sales_order_status_history WHERE order_id = {$orderId} ORDER BY history_id DESC LIMIT 1")->fetch(PDO::FETCH_NUM);
assertSame([['Pagamento confirmado.'], 'processing', ['processing', 'Comprovante pelo WhatsApp', 1, $adminId], 1], [$msgs, $orderStatus($orderId), $history, $orderMails('sales_order_processing')],
    'pedido: confirmar pagamento passa a "Pagamento confirmado", grava o histórico com o admin e avisa o cliente');
[, $msgs] = $admin->submit('/admin/order/view?id=' . $orderId, '/admin/order/complete', ['order_id' => $orderId]);
assertSame(['Um pedido "Pagamento confirmado" não pode passar para "Concluído".'], $msgs, 'pedido: transição fora da tabela é recusada');
$sourceId = (int) $pdo->query("SELECT source_id FROM inventory_source WHERE code = 'selftest_var_cd'")->fetchColumn();
$page = $orderPage($admin, $orderId);
[, $msgs] = $admin->submit('/admin/order/view?id=' . $orderId, '/admin/order/ship', ['order_id' => $orderId, 'source_id' => $sourceId, 'carrier' => 'Correios', 'tracking_code' => 'AA123BR']);
$shippedMail = (string) $pdo->query("SELECT body_html FROM mail_queue WHERE template_code = 'sales_order_shipped' AND to_email = 'frete@selftest.local'")->fetchColumn();
assertSame([true, ['Entrega registrada. O cliente recebeu o rastreio por e-mail.'], 'shipped', '1', '0', ['Correios', 'AA123BR'], true], [
    str_contains($page, 'value="' . $sourceId . '"'), $msgs, $orderStatus($orderId),
    StockService::format((string) $pdo->query("SELECT quantity FROM inventory_source_item WHERE source_id = {$sourceId} AND product_id = {$v0}")->fetchColumn()),
    (new StockService())->getSalable([$v0], $defaultWebsite)[$v0],
    $pdo->query("SELECT carrier, tracking_code FROM sales_shipment WHERE order_id = {$orderId}")->fetch(PDO::FETCH_NUM),
    str_contains($shippedMail, 'AA123BR'),
], 'pedido: registrar entrega baixa o físico da origem (4 - 3 = 1), troca a reserva pela baixa (saldo 0 com o outro pedido), grava a entrega e manda o rastreio');
[, $msgs] = $admin->submit('/admin/order/view?id=' . $orderId, '/admin/order/complete', ['order_id' => $orderId]);
assertSame([['Pedido concluído.'], 'complete', []], [$msgs, $orderStatus($orderId), OrderService::actions('complete')], 'pedido: concluir; concluído não tem mais ações');
[, $msgs] = $admin->submit('/admin/order/view?id=' . $nextId, '/admin/order/cancel', ['order_id' => $nextId, 'comment' => ' ']);
[, $canceled] = $admin->submit('/admin/order/view?id=' . $nextId, '/admin/order/cancel', ['order_id' => $nextId, 'comment' => 'Pagamento não recebido']);
assertSame([['Informe o motivo do cancelamento.'], ['Pedido cancelado. O estoque reservado voltou para a venda.'], 'canceled', '1', 1], [
    $msgs, $canceled, $orderStatus($nextId), (new StockService())->getSalable([$v0], $defaultWebsite)[$v0], $orderMails('sales_order_canceled'),
], 'pedido: cancelar pede o motivo, devolve a reserva e avisa o cliente');
[, $msgs] = $seller->submit('/admin/order/view?id=' . $nextId, '/admin/order/comment', ['order_id' => $nextId, 'comment' => 'Cliente ligou', 'notify' => '1']);
assertSame([['Comentário salvo.'], 1, 3, true], [$msgs, $orderMails('sales_order_comment'),
    (int) $pdo->query("SELECT COUNT(*) FROM sales_order_status_history WHERE order_id = {$nextId}")->fetchColumn(), str_contains($orderPage($admin, $nextId), 'selftest_order')],
    'pedido: comentário em qualquer status, com aviso por e-mail e o admin no histórico');
$dashboard = $admin->request('/admin/index')['body'];
$confId = (int) $pdo->query("SELECT customer_id FROM customer_flat WHERE email = 'conf@selftest.local'")->fetchColumn();
assertSame([true, true, true], [str_contains($dashboard, 'pedido(s) hoje'), str_contains($dashboard, '/admin/order/index?status=processing'),
    str_contains($admin->request('/admin/customer/edit?id=' . $confId)['body'], 'Nenhum pedido deste cliente.')], 'painel: card de Vendas; a tela do cliente lista os pedidos dele');
$rateForm = $admin->request('/admin/shippingrate/index')['body'];
[, $msgs] = $admin->submit('/admin/shippingrate/index', '/admin/shippingrate/save', ['website_id' => $defaultWebsite, 'rates' => [
    ['postcode_from' => '01000-000', 'postcode_to' => '01999-999', 'weight_to' => '2,5', 'price' => '12,50', 'days' => '2'],
    ['postcode_from' => '', 'postcode_to' => '', 'weight_to' => '', 'price' => '', 'days' => ''],
]]);
[, $bad] = $admin->submit('/admin/shippingrate/index', '/admin/shippingrate/save', ['website_id' => $defaultWebsite, 'rates' => [['postcode_from' => '1', 'postcode_to' => '2', 'price' => '1']]]);
assertSame([true, ['Faixas de frete salvas.'], [['postcode_from' => '01000000', 'postcode_to' => '01999999', 'weight_to' => 2.5, 'price' => 12.5, 'days' => 2]], true, 403], [
    str_contains($rateForm, 'value="01000-000"'), $msgs, (new \Iter\Shipping\Model\ShippingService())->getTableRates($defaultWebsite),
    str_starts_with($bad[0] ?? '', 'Faixa 1:'), $seller->request('/admin/shippingrate/index')['status'],
], 'faixas de frete: a tela mostra e grava a tabela (vírgula decimal, linha em branco ignorada), valida e exige Iter_Shipping::rates');

// --- orders in the admin API (COMMERCE-PLAN delivery 6b) --------------------------------------------
$apiCart = $api('mutation { createCart }')[1]['data']['createCart'] ?? '';
$ac = '"' . $apiCart . '"';
foreach (["addToCart(cartId: {$ac}, sku: \"{$v0Sku}\")", "setCartGuestInfo(cartId: {$ac}, email: \"api-pedido@selftest.local\", firstname: \"Api\", lastname: \"Pedido\", taxvat: \"529.982.247-25\")",
    "setCartShippingAddress(cartId: {$ac}, {$addr})", "setCartShippingMethod(cartId: {$ac}, code: \"pickup\")", "setCartPaymentMethod(cartId: {$ac}, code: \"offline\")"] as $call) {
    $api("mutation { {$call} { total } }");
}
$pdo->exec("DELETE FROM api_rate_limit WHERE rate_key LIKE 'order_place:%'"); // the delivery 5 tests used up the minute's orders
$placed = $api("mutation { placeOrder(cartId: {$ac}) { id number history { status } } }")[1]['data']['placeOrder'] ?? [];
$apiOrder = (int) ($placed['id'] ?? 0);
$adminOrderFields = '{ id number status customerId websiteId shipment { carrier trackingCode sourceName } history { status comment customerNotified adminUsername } }';
$adminGql = static fn (string $call, ?Browser $b = null) => $gql($b ?? $admin, "mutation { {$call} {$adminOrderFields} }");
$found = $gql($admin, '{ orders(search: "' . ($placed['number'] ?? 'x') . '") { total amount items { number status history { status } } } }')['data']['orders'] ?? [];
$summary = array_column($gql($admin, '{ salesSummary { status statusLabel count amount } }')['data']['salesSummary'] ?? [], null, 'status');
assertSame([null, 1, 24.9, 'pending_payment', 1, (string) $apiOrder, null, ['complete', 'Concluído'], true, 'BAD_USER_INPUT'], [
    array_key_exists('history', $placed) ? $placed['history'] : 'x', $found['total'] ?? null, $found['amount'] ?? null, $found['items'][0]['status'] ?? null, count($found['items'][0]['history'] ?? []),
    $gql($admin, '{ order(number: "' . strtolower($placed['number'] ?? 'x') . '") { id } }')['data']['order']['id'] ?? null,
    array_key_exists('order', $gql($admin, '{ order(id: "999999") { id } }')['data'] ?? []) ? null : 'x',
    [$summary['complete']['status'] ?? null, $summary['complete']['statusLabel'] ?? null], count($summary) === 5,
    $gql($admin, '{ orders(from: "ontem") { total } }')['errors'][0]['extensions']['code'] ?? null,
], 'API pedidos: o cliente não vê o histórico; o admin busca por número, lê um pedido (inexistente = null) e o resumo por situação; data inválida é recusada');
assertSame(['UNAUTHENTICATED', 'FORBIDDEN', 'FORBIDDEN'], [
    $gql($guest, '{ orders { total } }')['errors'][0]['extensions']['code'] ?? null,
    $gql($dash, '{ orders { total } }')['errors'][0]['extensions']['code'] ?? null,
    $adminGql("confirmOrderPayment(id: \"{$apiOrder}\")", $seller)['errors'][0]['extensions']['code'] ?? null,
], 'API pedidos: sem login, sem Iter_Sales::order ou sem Iter_Sales::order_payment é recusado');
$confirmed = $adminGql("confirmOrderPayment(id: \"{$apiOrder}\", comment: \"Pago na loja\")")['data']['confirmOrderPayment'] ?? [];
$badSource = $adminGql("shipOrder(id: \"{$apiOrder}\", sourceCode: \"selftest-nada\")")['errors'][0]['extensions']['code'] ?? null;
$shipped = $adminGql("shipOrder(id: \"{$apiOrder}\", carrier: \"Motoboy\", trackingCode: \"MB-1\")")['data']['shipOrder'] ?? [];
$sourceName = (string) $pdo->query("SELECT name FROM inventory_source WHERE code = 'selftest_var_cd'")->fetchColumn();
assertSame([['processing', 'Pago na loja', true, 'selftest_admin'], 'SOURCE_INVALID', ['shipped', 'Motoboy', 'MB-1', $sourceName]], [
    [$confirmed['status'] ?? null, $confirmed['history'][0]['comment'] ?? null, $confirmed['history'][0]['customerNotified'] ?? null, $confirmed['history'][0]['adminUsername'] ?? null],
    $badSource,
    [$shipped['status'] ?? null, $shipped['shipment']['carrier'] ?? null, $shipped['shipment']['trackingCode'] ?? null, $shipped['shipment']['sourceName'] ?? null],
], 'API pedidos: confirmar pagamento com o admin do histórico; enviar sem origem usa a sugerida, origem desconhecida é recusada');
$completed = $adminGql("completeOrder(id: \"{$apiOrder}\")")['data']['completeOrder']['status'] ?? null;
$commented = $adminGql("addOrderComment(id: \"{$apiOrder}\", comment: \"Obrigado!\", notifyCustomer: true)")['data']['addOrderComment']['history'][0] ?? [];
assertSame(['complete', 'ORDER_STATUS_INVALID', ['complete', 'Obrigado!', true], 1], [
    $completed, $adminGql("cancelOrder(id: \"{$apiOrder}\", reason: \"x\")")['errors'][0]['extensions']['code'] ?? null,
    [$commented['status'] ?? null, $commented['comment'] ?? null, $commented['customerNotified'] ?? null],
    (int) $pdo->query("SELECT COUNT(*) FROM mail_queue WHERE template_code = 'sales_order_comment' AND to_email = 'api-pedido@selftest.local'")->fetchColumn(),
], 'API pedidos: concluir; cancelar depois do envio é recusado; comentário com aviso ao cliente');

// --- the customer's and the guest's orders (COMMERCE-PLAN delivery 7) --------------------------------
$pdo->exec("UPDATE sales_order SET customer_id = {$confId} WHERE order_id = {$apiOrder}"); // as if conf@ had bought it logged in
$mine = $api('{ myOrders { total items { number history { status } } } }', $ct)[1]['data']['myOrders'] ?? [];
assertSame(['UNAUTHENTICATED', 1, $placed['number'] ?? 'x', null, $placed['number'] ?? 'x', null], [
    $gqlCode($api('{ myOrders { total } }')),
    $mine['total'] ?? null, $mine['items'][0]['number'] ?? null, array_key_exists('history', $mine['items'][0] ?? []) ? $mine['items'][0]['history'] : 'x',
    $api('{ myOrder(number: "' . strtolower($placed['number'] ?? 'x') . '") { number } }', $ct)[1]['data']['myOrder']['number'] ?? null,
    array_key_exists('myOrder', $api('{ myOrder(number: "' . ($order['number'] ?? 'x') . '") { number } }', $ct)[1]['data'] ?? []) ? null : 'x',
], 'pedidos do cliente: só com login; a lista e o pedido dele pelo número (sem o histórico do admin); pedido de outro dá null');
$pdo->exec("DELETE FROM api_rate_limit WHERE rate_key LIKE 'order_lookup:%'");
$setConfig(['sales/order/guest_lookup_per_minute' => '2']);
$lookup = static fn (string $email) => $api('{ guestOrder(number: "' . ($order['number'] ?? 'x') . '", email: "' . $email . '") { number status } }');
assertSame([['number' => $order['number'] ?? 'x', 'status' => 'complete'], null, 'TOO_MANY_REQUESTS'], [
    $lookup('FRETE@selftest.local')[1]['data']['guestOrder'] ?? null,
    array_key_exists('guestOrder', $lookup('outro@selftest.local')[1]['data'] ?? []) ? null : 'x',
    $gqlCode($lookup('frete@selftest.local')),
], 'pedido do visitante: número + e-mail (sem diferença de maiúsculas); e-mail errado dá null; limite por IP');

$pdo->exec("DELETE FROM api_rate_limit WHERE rate_key LIKE 'customer_login:%'");
$pdo->exec("INSERT INTO core_config_data (scope, scope_id, path, value) VALUES ('default', 0, 'customer/security/api_login_per_minute', '2') ON CONFLICT (scope, scope_id, path) DO UPDATE SET value = '2'");
assertSame([200, 200, 429], array_map(static fn () => $custLogin('errada123')[0], [1, 2, 3]), 'API cliente: login passa do limite por IP e recebe 429');
$pdo->exec("DELETE FROM api_rate_limit WHERE rate_key LIKE 'customer_login:%'");

$cors = static function (string $origin, string $method = 'OPTIONS') use ($base): array {
    $ch = curl_init($base . '/core/graphql/index');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => ["Origin: {$origin}"]]);
    $response = (string) curl_exec($ch);
    preg_match('/^Access-Control-Allow-Origin: (.*)$/mi', $response, $m);

    return [curl_getinfo($ch, CURLINFO_RESPONSE_CODE), trim($m[1] ?? '')];
};
$pdo->exec("DELETE FROM core_config_data WHERE path = 'web/cors_origins'");
$pdo->exec("INSERT INTO core_config_data (scope, scope_id, path, value) VALUES ('default', 0, 'web/cors_origins', 'https://front.selftest/')");
assertSame([[204, 'https://front.selftest'], [204, ''], [200, 'https://front.selftest']], [$cors('https://front.selftest'), $cors('https://outro.selftest'), $cors('https://front.selftest', 'POST')], 'CORS: só as origens configuradas');
$pdo->exec("UPDATE core_config_data SET value = '*' WHERE path = 'web/cors_origins'");
assertSame([204, 'https://outro.selftest'], $cors('https://outro.selftest'), 'CORS: * libera qualquer origem');

// --- timezone ----------------------------------------------------------------------------------
$dbNow = strtotime((string) $pdo->query('SELECT NOW()::timestamp')->fetchColumn());
assertSame(true, abs($dbNow - time()) < 5, 'banco e PHP usam o mesmo fuso (' . date_default_timezone_get() . ')');

echo "\nTodos os testes passaram.\n";
