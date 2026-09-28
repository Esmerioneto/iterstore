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
use Iter\Core\Config;
use Iter\Core\Db\Connection;
use Iter\Core\View\LayoutParser;
use Iter\Core\View\LayoutRenderer;
use Iter\Eav\Form\FormSchemaGenerator;
use Iter\Eav\InputTypeRegistry;
use Iter\Eav\Model\AttributeException;
use Iter\Eav\Model\AttributeService;
use Iter\Eav\Model\AttributeSetService;
use Iter\Eav\Model\ValueService;
use Iter\Inventory\Model\InventoryException;
use Iter\Inventory\Model\SourceService;
use Iter\Inventory\Model\StockService;
use Iter\Mail\Model\MailException;
use Iter\Mail\Model\MailService;

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
$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', dirname(__DIR__) . '/public'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes,
    null,
    ['XDEBUG_MODE' => 'off'] + getenv()
);

$savedModules = file_get_contents(ETC . 'config.json'); // module on/off tests write it; shared with a running dev server
$savedTemplates = $pdo->query('SELECT code, subject, body_html, body_text FROM mail_template')->fetchAll(PDO::FETCH_ASSOC);
$purge = static function () use ($pdo, $savedConfig, $savedModules, $savedTemplates): void {
    if (file_get_contents(ETC . 'config.json') !== $savedModules) {
        file_put_contents(ETC . 'config.json', $savedModules);
    }
    foreach ($pdo->query("SELECT product_id FROM catalog_product WHERE sku LIKE 'selftest%'")->fetchAll(PDO::FETCH_COLUMN) as $productId) {
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
    $pdo->exec("DELETE FROM eav_attribute WHERE code LIKE 'selftest%'");
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
};
register_shutdown_function(static function () use ($purge, $server): void {
    proc_terminate($server);
    $purge();
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
assertSame(['Não é possível remover: há lojas vinculadas aos grupos deste website.'], $msgs, 'website com lojas não pode ser removido');

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
    'entity_type' => 'product', 'label' => 'Selftest', 'input_type' => 'text',
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
assertSame(200, $admin->request('/admin/attribute/create')['status'], 'form de novo atributo abre');
$colorId = (int) $attributeRow('selftest_color')['attribute_id'];
$service->update($colorId, ['label' => 'C', 'input_type' => 'radio']);
assertSame('radio', $attributeRow('selftest_color')['input_type'], 'troca para tipo com o mesmo armazenamento é aceita');

// options
$optionsOf = static fn (string $code) => $pdo->query("SELECT o.value, o.label, o.sort_order, o.is_default FROM eav_attribute_option o
    JOIN eav_attribute a ON a.attribute_id = o.attribute_id WHERE a.code = '{$code}' ORDER BY o.sort_order")->fetchAll(PDO::FETCH_NUM);
$blankRow = ['value' => '', 'label' => '', 'sort_order' => ''];
assertSame(['Atributo criado com sucesso.'], $saveAttribute(['code' => 'selftest_size', 'input_type' => 'select', 'options' => [
    ['value' => 'p', 'label' => 'Pequeno', 'sort_order' => ''], ['value' => 'g', 'label' => '', 'sort_order' => '', 'is_default' => '1'], $blankRow,
]]), 'select criado com opções');
assertSame([['p', 'Pequeno', 10, 0], ['g', 'g', 20, 1]], $optionsOf('selftest_size'), 'opções gravadas; linha vazia ignorada, nome vazio usa o valor');
assertSame(["A opção 'x' aparece mais de uma vez."], $saveAttribute(['code' => 'selftest_dup', 'input_type' => 'select', 'options' => [['value' => 'x'], ['value' => 'x']]]), 'valor de opção repetido é recusado');
assertSame(["Informe o valor da opção 'Sem valor'."], $saveAttribute(['code' => 'selftest_noval', 'input_type' => 'radio', 'options' => [['value' => '', 'label' => 'Sem valor']]]), 'opção sem valor é recusada');
assertSame(["O tipo 'Seleção' aceita só uma opção padrão."], $saveAttribute(['code' => 'selftest_2def', 'input_type' => 'select', 'options' => [['value' => 'a', 'is_default' => '1'], ['value' => 'b', 'is_default' => '1']]]), 'seleção simples aceita só uma opção padrão');
assertSame(['Atributo criado com sucesso.'], $saveAttribute(['code' => 'selftest_multi', 'input_type' => 'multiselect', 'options' => [['value' => 'a', 'is_default' => '1'], ['value' => 'b', 'is_default' => '1']]]), 'múltipla seleção aceita várias opções padrão');
$saveAttribute(['code' => 'selftest_texto', 'options' => [['value' => 'ignorada']]]);
assertSame([], $optionsOf('selftest_texto'), 'tipo sem opções não grava opções');

$sizeId = (int) $attributeRow('selftest_size')['attribute_id'];
$optionId = static fn (string $value) => $pdo->query("SELECT option_id FROM eav_attribute_option WHERE attribute_id = {$sizeId} AND value = '{$value}'")->fetchColumn();
$pId = $optionId('p');
assertSame(true, str_contains($admin->request('/admin/attribute/edit?id=' . $sizeId)['body'], 'value="Pequeno"'), 'form de edição mostra as opções');
[, $msgs] = $admin->submit('/admin/attribute/edit?id=' . $sizeId, '/admin/attribute/save', [
    'attribute_id' => $sizeId, 'label' => 'Tamanho', 'input_type' => 'select', 'options' => [['value' => 'p', 'label' => 'P', 'sort_order' => '5'], $blankRow],
]);
assertSame(['Atributo atualizado com sucesso.'], $msgs, 'atributo com opções atualizado');
assertSame([[['p', 'P', 5, 0]], $pId], [$optionsOf('selftest_size'), $optionId('p')], 'opção removida some; a mantida muda de nome e conserva o id');
$service->update($sizeId, ['label' => 'Tamanho', 'input_type' => 'text']);
assertSame([], $optionsOf('selftest_size'), 'mudar para tipo sem opções remove as opções');

$sysSelect = (int) $service->create(['entity_type' => 'product', 'code' => 'selftest_sys_sel', 'label' => 'S', 'input_type' => 'select',
    'options' => [['value' => 'a'], ['value' => 'b']]], true)->getId();
assertSame('ATTRIBUTE_SYSTEM_PROTECTED', $throws(static fn () => $service->update($sysSelect, ['label' => 'S', 'options' => [['value' => 'a']]])), 'sistema: opção existente não pode ser removida');
$service->update($sysSelect, ['label' => 'S', 'options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b'], ['value' => 'c']]]);
assertSame(['A', 'b', 'c'], array_column($optionsOf('selftest_sys_sel'), 1), 'sistema: opção nova e nome alterado são aceitos');

for ($i = 1; $i <= 21; $i++) {
    $service->create(['entity_type' => 'category', 'code' => sprintf('selftest_p%02d', $i), 'label' => 'P', 'input_type' => 'text']);
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
    $service->create(['entity_type' => 'customer', 'code' => "selftest_v_{$code}", 'label' => ucfirst($code), 'input_type' => $type, 'is_required' => $code === 'name' ? 1 : 0,
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
assertSame('INPUT_TYPE_CHANGE_BLOCKED', $throws(static fn () => $service->update($vId('price'), ['label' => 'Price', 'input_type' => 'integer'])), 'atributo com valores não troca de armazenamento');
$service->update($vId('age'), ['label' => 'Age', 'input_type' => 'text']);
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
$saveProduct = static fn (array $fields) => $admin->submit('/admin/product/create', '/admin/product/save', $fields + [
    'attribute_set_id' => $defaultProductSet, 'name' => 'Camiseta', 'is_active' => '1',
])[1];
assertSame(["'Preço' é obrigatório."], $saveProduct(['sku' => 'selftest-1', 'attributes' => ['price' => '']]), 'atributo obrigatório vazio é recusado');
assertSame(false, $productRow('selftest-1'), 'erro nos atributos não cria o produto');
assertSame(['Produto criado com sucesso.'], $saveProduct(['sku' => 'selftest-1', 'attributes' => ['price' => '49,90', 'description' => 'Algodão']]), 'produto criado pelo admin');
assertSame(['description' => 'Algodão', 'price' => '49.9'], array_intersect_key($productValues('selftest-1'), ['price' => 1, 'description' => 1]), 'preço e descrição gravados como valores EAV');
assertSame(["Já existe um produto com o SKU 'SELFTEST-1'."], $saveProduct(['sku' => 'SELFTEST-1', 'attributes' => ['price' => '1']]), 'SKU repetido (maiúsculas ou não) é recusado');
assertSame(['SKU deve ter até 64 caracteres: letras, números, ponto, hífen e _ (começando por letra ou número).'], $saveProduct(['sku' => 'a b', 'attributes' => ['price' => '1']]), 'SKU com espaço é recusado');

// a product in another set gets that set's fields only
$setService->create(['entity_type' => 'product', 'code' => 'selftest_roupa', 'label' => 'Roupa']);
$roupa = (int) $setRow('selftest_roupa')['attribute_set_id'];
$service->create(['entity_type' => 'product', 'code' => 'selftest_tam', 'label' => 'Tamanho', 'input_type' => 'select', 'options' => [['value' => 'p'], ['value' => 'm']]]);
$setService->update($roupa, ['label' => 'Roupa', 'items' => [$attrId('price') => ['in' => 1], $attrId('selftest_tam') => ['in' => 1, 'group' => 'Medidas']]]);
$roupaForm = $admin->request('/admin/product/create?set=' . $roupa)['body'];
assertSame([true, false], [str_contains($roupaForm, 'name="attributes[selftest_tam]"'), str_contains($roupaForm, 'name="attributes[description]"')], 'form segue o conjunto escolhido');
$saveProduct(['sku' => 'selftest-2', 'attribute_set_id' => $roupa, 'attributes' => ['price' => '10', 'selftest_tam' => 'm', 'description' => 'fora do conjunto']]);
assertSame(['price' => '10', 'selftest_tam' => 'm'], $productValues('selftest-2'), 'só os atributos do conjunto são gravados');
$p2 = (int) $productRow('selftest-2')['product_id'];
assertSame(true, str_contains($admin->request('/admin/product/edit?id=' . $p2)['body'], '<option value="m" selected>'), 'edição mostra os valores gravados');
[, $msgs] = $admin->submit('/admin/product/edit?id=' . $p2, '/admin/product/save', ['product_id' => $p2, 'attribute_set_id' => $defaultProductSet, 'sku' => 'selftest-2', 'name' => 'Calça', 'attributes' => ['price' => '12']]);
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
$service->create(['entity_type' => 'product', 'code' => 'selftest_novo', 'label' => 'Novo', 'input_type' => 'checkbox']);
$service->create(['entity_type' => 'product', 'code' => 'selftest_cor', 'label' => 'Cor', 'input_type' => 'multiselect', 'options' => [['value' => 'az', 'label' => 'Azul'], ['value' => 'vm', 'label' => 'Vermelho']]]);
$service->create(['entity_type' => 'product', 'code' => 'selftest_marca', 'label' => 'Marca', 'input_type' => 'select', 'options' => [['value' => 'acme', 'label' => 'ACME Ltda']]]);
$setService->update($defaultProductSet, ['label' => 'Básico', 'items' => [$attrId('price') => ['in' => 1], $attrId('description') => ['in' => 1], $attrId('selftest_novo') => ['in' => 1],
    $attrId('selftest_cor') => ['in' => 1], $attrId('selftest_marca') => ['in' => 1]]]);
$createGql = 'mutation ($sku: String!, $attrs: [ProductAttributeInput!]) { createProduct(sku: $sku, name: "Boné", attributeSetId: "' . $defaultProductSet . '", attributes: $attrs) { id sku is_active attributes { code value values url } } }';
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
$service->update($attrId('selftest_cor'), ['label' => 'Cor', 'input_type' => 'multiselect', 'options' => [['value' => 'az', 'label' => 'Azul']]]);
$labels = array_column($gql($admin, 'query ($id: ID!) { product(id: $id) { attributes { code option_labels } } }', ['id' => $gqlId])['data']['product']['attributes'] ?? [], 'option_labels', 'code');
assertSame(['vm', 'Azul'], $labels['selftest_cor'] ?? null, 'GraphQL: opção removida mostra o valor no lugar do rótulo');
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
$fileError = static fn (array $attributes) => $throws(static fn () => $productService->create(['attribute_set_id' => $fotoSet->getId(), 'sku' => 'selftest-img', 'name' => 'I'], $attributes + ['price' => '1']));
assertSame(['VALUE_REQUIRED', 'FILE_TYPE_INVALID', 'FILE_TYPE_INVALID'], [$fileError([]), $fileError(['selftest_capa' => $upload('a.gif')]), $fileError(['selftest_capa' => $upload('fake.png')])],
    'imagem obrigatória, GIF e arquivo que só finge ser PNG são recusados');
assertSame(false, $productRow('selftest-img'), 'imagem recusada não cria o produto');
$media = static fn (string $sku, string $code) => ValueService::mediaDir() . ($productService->getValues((int) $productRow($sku)['product_id'])[$code] ?? 'nada');
foreach (['a.webp' => 'webp', 'a.avif' => 'avif', 'a.jpg' => 'jpg', 'a.png' => 'png'] as $file => $ext) {
    $productService->create(['attribute_set_id' => $fotoSet->getId(), 'sku' => "selftest-img-{$ext}", 'name' => 'I'], ['price' => '1', 'selftest_capa' => $upload($file), 'selftest_foto' => 'qualquer/caminho.png']);
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
$msgs = $admin->submit('/admin/product/create', '/admin/product/save', ['attribute_set_id' => $fotoSet->getId(), 'sku' => 'selftest-up', 'name' => 'U',
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
assertSame('INPUT_TYPE_CHANGE_BLOCKED', $throws(static fn () => $service->update($attrId('selftest_capa'), ['label' => 'Capa', 'input_type' => 'text'])), 'atributo imagem com valores não vira texto');
array_map('unlink', glob("{$scratch}/*"));
rmdir($scratch);

// --- inventory: sources ---------------------------------------------------------------------------
$sourceService = new SourceService();
$sourceRow = static fn (string $code) => $pdo->query("SELECT * FROM inventory_source WHERE code = '{$code}'")->fetch(PDO::FETCH_ASSOC);
$saveSource = static fn (array $fields) => $admin->submit('/admin/source/create', '/admin/source/save', $fields + ['name' => 'Depósito', 'is_enabled' => '1'])[1];
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
$estId = (int) $productService->create(['attribute_set_id' => $defaultProductSet, 'sku' => 'selftest-est', 'name' => 'Estoque', 'is_active' => 1], ['price' => '1'])->getId();
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
$estId = (int) $productService->create(['attribute_set_id' => $defaultProductSet, 'sku' => 'selftest-est', 'name' => 'Estoque', 'is_active' => 1], ['price' => '1'])->getId();
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
assertSame(['stock', 'group: (largura total)', 'base', 'categories'], array_slice($blocksOf($estPage), 0, 4), 'form segue o layout salvo; blocos fora dele vão para o fim; grupo de outro conjunto é ignorado');
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
$saveCategory = static fn (array $fields) => $admin->submit('/admin/category/create', '/admin/category/save', $fields + ['is_active' => '1'])[1];
assertSame(['Categoria criada com sucesso.'], $saveCategory(['name' => 'Selftest Roupas']), 'categoria criada pelo admin');
$roupasId = (int) ($categoryRow('selftest-roupas')['category_id'] ?? 0);
assertSame(['selftest-roupas', 'higiene-cosmeticos'], [$categoryRow('selftest-roupas')['url_key'] ?? null, CategoryService::slug('Higiene & Cosméticos')], 'chave de URL gerada a partir do nome');
assertSame(["Já existe uma categoria com a chave de URL 'selftest-roupas'."], $saveCategory(['name' => 'Outra', 'url_key' => 'Selftest Roupas']), 'chave de URL duplicada é recusada');
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
assertSame([$camisetasId, $ocultaId], (new ProductService())->getCategoryIds($catProduct), 'categorias do produto gravadas');
assertSame(true, str_contains($admin->request('/admin/product/edit?id=' . $catProduct)['body'], 'name="category_ids[]" value="' . $camisetasId . '" checked'), 'edição marca as categorias do produto');
assertSame([1, 0], [
    substr_count($admin->request('/admin/product/index?category_id=' . $roupasId)['body'], '/admin/product/edit?id='),
    substr_count($admin->request('/admin/product/index?q=selftest-est&category_id=' . $roupasId)['body'], '/admin/product/edit?id='),
], 'filtro por categoria inclui as subcategorias');
assertSame(['Categoria não encontrada: 999999.'], $saveProduct(['sku' => 'selftest-cat2', 'category_ids' => [999999], 'attributes' => ['price' => '5']]), 'categoria inexistente é recusada');

$catRead = 'query ($key: String) { category(url_key: $key) { name children { url_key } products { total items { sku categories { url_key } } } } }';
$r = $gql($guest, $catRead, ['key' => 'selftest-roupas'])['data']['category'] ?? null;
assertSame(['name' => 'Selftest Roupas', 'children' => [['url_key' => 'selftest-camisetas']], 'products' => ['total' => 1, 'items' => [['sku' => 'selftest-cat', 'categories' => [['url_key' => 'selftest-camisetas']]]]]], $r, 'GraphQL: visitante vê a árvore e os produtos, sem as inativas');
assertSame([['category' => null], 'selftest-oculta'], [
    $gql($guest, $catRead, ['key' => 'selftest-oculta'])['data'] ?? null,
    $gql($admin, '{ category(id: "' . $ocultaId . '") { url_key } }')['data']['category']['url_key'] ?? null,
], 'GraphQL: categoria inativa só para o admin');
assertSame(true, in_array('selftest-roupas', array_column($gql($guest, '{ categories { url_key } }')['data']['categories'] ?? [], 'url_key'), true), 'GraphQL: categorias principais');
$setCats = static fn (string $args) => $gql($admin, 'mutation { updateProduct(id: "' . $catProduct . '"' . $args . ') { categories { id } } }');
assertSame([[['id' => (string) $ocultaId], ['id' => (string) $camisetasId]], [], 'CATEGORY_NOT_FOUND'], [
    $setCats(', name: "Com categorias"')['data']['updateProduct']['categories'] ?? null,
    $setCats(', categoryIds: []')['data']['updateProduct']['categories'] ?? null,
    $setCats(', categoryIds: ["999999"]')['errors'][0]['extensions']['code'] ?? null,
], 'GraphQL: categoryIds omitido mantém, [] tira de todas, id inexistente é recusado');
$setCats(', categoryIds: ["' . $camisetasId . '"]');
$admin->submit('/admin/category/index', '/admin/category/delete', ['category_id' => $camisetasId]);
assertSame([false, true, []], [$categoryRow('selftest-camisetas'), (bool) $productRow('selftest-cat'), (new ProductService())->getCategoryIds($catProduct)], 'remover a categoria mantém o produto e tira o vínculo');
$pdo->exec("INSERT INTO catalog_category_product (category_id, product_id) VALUES ({$roupasId}, {$catProduct})");
(new ProductService())->delete($catProduct);
assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM catalog_category_product WHERE product_id = {$catProduct}")->fetchColumn(), 'remover o produto apaga os vínculos');

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

// --- modules on/off -------------------------------------------------------------------------------
$toggle = static fn (string $module, int $enabled) => $admin->submit('/admin/config/index', '/admin/config/module', ['module' => $module, 'enabled' => (string) $enabled])[1];
$configPage = $admin->request('/admin/config/index')['body'];
assertSame([true, true, true, true], [
    (bool) preg_match('/name="module" value="iter_inventory">\s*<input type="hidden" name="enabled" value="0">/', $configPage),
    str_contains($configPage, 'Essencial'),
    str_contains($configPage, 'Usado por Catalog'),
    str_contains($configPage, 'Usado por Inventory'),
], 'lista de módulos: botão Desativar, essenciais e usados por outro sem botão');
assertSame(['O módulo Core é essencial e não pode ser desativado.'], $toggle('iter_core', 0), 'módulo essencial não é desativado');
assertSame(['Desative antes os módulos que dependem de Eav: Catalog.'], $toggle('iter_eav', 0), 'módulo com dependentes ativos não é desativado');
assertSame(['Desative antes os módulos que dependem de Catalog: Inventory.'], $toggle('iter_catalog', 0), 'Catalog não é desativado com o Inventory ativo');
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
$toggle('iter_eav', 0);
assertSame(['Ative antes os módulos de que Catalog depende: Eav.'], $toggle('iter_catalog', 1), 'módulo não é ativado sem as dependências');
$toggle('iter_eav', 1);
assertSame([true, 200], [str_starts_with($toggle('iter_catalog', 1)[0] ?? '', 'Módulo Catalog ativado.'), $admin->request('/admin/product/index')['status']], 'módulo reativado volta a funcionar');
$toggle('iter_inventory', 1);
assertSame('7', (new StockService())->getSalable([$estId])[$estId], 'Inventory reativado: o estoque continua lá');
assertSame($savedModules, file_get_contents(ETC . 'config.json'), 'config.json volta ao que era');
assertSame(403, $dash->request('/admin/config/index')['status'], 'perfil sem Configurações não chega aos módulos');

// --- timezone ----------------------------------------------------------------------------------
$dbNow = strtotime((string) $pdo->query('SELECT NOW()::timestamp')->fetchColumn());
assertSame(true, abs($dbNow - time()) < 5, 'banco e PHP usam o mesmo fuso (' . date_default_timezone_get() . ')');

echo "\nTodos os testes passaram.\n";
