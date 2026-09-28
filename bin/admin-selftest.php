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
use Iter\Core\Config;
use Iter\Core\Db\Connection;
use Iter\Eav\InputTypeRegistry;
use Iter\Eav\Model\AttributeException;
use Iter\Eav\Model\AttributeService;

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
            curl_setopt($this->ch, CURLOPT_POSTFIELDS, http_build_query($post));
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

$purge = static function () use ($pdo, $savedConfig): void {
    $pdo->exec("DELETE FROM eav_attribute WHERE code LIKE 'selftest%'");
    $pdo->exec("DELETE FROM store WHERE store_name LIKE 'selftest%'");
    $pdo->exec("DELETE FROM store_group WHERE code LIKE 'selftest%'");
    $pdo->exec("DELETE FROM store_website WHERE code LIKE 'selftest%'");
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

// --- EAV: input types + attributes -------------------------------------------------------------
$caps = static fn (string $code) => [InputTypeRegistry::get($code)['supports_options'], InputTypeRegistry::get($code)['multiple']];
assertSame([true, false], $caps('select'), 'select: aceita opções, valor único');
assertSame([true, true], $caps('multiselect'), 'multiselect: aceita opções, vários valores');
assertSame([false, false], $caps('text'), 'text: sem opções');
$throws = static function (callable $fn): string {
    try {
        $fn();
    } catch (Throwable $e) {
        return $e instanceof AttributeException ? $e->errorCode : get_class($e);
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
assertSame('INPUT_TYPE_CHANGE_BLOCKED', $throws(static fn () => $service->update($colorId, ['label' => 'C', 'input_type' => 'integer'])), 'troca de tipo que muda o armazenamento é recusada');
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

// --- timezone ----------------------------------------------------------------------------------
$dbNow = strtotime((string) $pdo->query('SELECT NOW()::timestamp')->fetchColumn());
assertSame(true, abs($dbNow - time()) < 5, 'banco e PHP usam o mesmo fuso (' . date_default_timezone_get() . ')');

echo "\nTodos os testes passaram.\n";
