<?php

declare(strict_types=1);

namespace Iter\Admin\Controllers;

use Iter\Admin\Auth;
use Iter\Admin\Breadcrumb;
use Iter\Admin\Model\ApiKey as ApiKeyModel;
use Iter\Admin\View\ApiKeyReveal;
use Iter\Core\Messages;
use Iter\Core\RateLimit;
use Iter\Core\View\Layout;
use Iter\Log\Model\OperationLog;

/** Sistema → Chaves da API: the keys the apps and sites send in X-Api-Key */
class Apikey
{
    public const ADMIN_RESOURCE = 'Iter_Admin::api_key';

    /** the key just created, in the session until the list shows it (once) */
    public const NEW_KEY = 'iter_new_api_key';

    public function Index(): void
    {
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Chaves da API'],
        ]);

        echo Layout::renderAdmin('admin_apikey_index');
    }

    public function Create(): void
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            Messages::addError('Informe o nome do aplicativo ou site (até 100 caracteres).');
            header('Location: /admin/apikey/index');
            exit;
        }
        [$id, $key] = ApiKeyModel::create($name, (int) Auth::user()->getId());
        $_SESSION[self::NEW_KEY] = ['name' => $name, 'key' => $key];
        \isModuleEnabled('iter_log') && OperationLog::record('create', 'api_key', $id, $name, [], ['Nome' => $name, 'Chave' => ApiKeyModel::get($id)['key_hint']]);
        Messages::addSuccess('Chave criada. Para vê-la de novo depois, use "Ver chave" na lista.');
        header('Location: /admin/apikey/index');
        exit;
    }

    /** "Ver chave": asks the panel password again, like the backup's recovery kit */
    public function Reveal(): void
    {
        ApiKeyReveal::$key = self::revealable((int) ($_GET['id'] ?? 0));
        self::revealBreadcrumb();
        echo Layout::renderAdmin('admin_apikey_reveal');
    }

    public function Revealpost(): void
    {
        $key = self::revealable((int) ($_POST['key_id'] ?? 0));
        $back = '/admin/apikey/reveal?id=' . (int) $key['key_id'];
        $user = Auth::user();
        if (!RateLimit::hit('api_key_reveal:' . $user->getId(), 5)) {
            Messages::addError('Muitas tentativas. Aguarde um minuto e tente de novo.');
            header('Location: ' . $back);
            exit;
        }
        if (!password_verify((string) ($_POST['password'] ?? ''), (string) $user->getData('password'))) {
            Messages::addError('Senha incorreta.');
            header('Location: ' . $back);
            exit;
        }
        ApiKeyReveal::$key = $key;
        ApiKeyReveal::$plain = ApiKeyModel::reveal((int) $key['key_id']);
        \isModuleEnabled('iter_log') && OperationLog::record('api_key_reveal', 'api_key', (int) $key['key_id'], $key['name']);
        // shown in this response only: not in the session, not in the browser's cache
        header('Cache-Control: no-store');
        self::revealBreadcrumb();
        echo Layout::renderAdmin('admin_apikey_reveal');
    }

    /** an active key that has the encrypted copy; else back to the list */
    private static function revealable(int $id): array
    {
        $key = ApiKeyModel::get($id);
        if ($key === null || !(int) $key['is_active'] || empty($key['key_encrypted'])) {
            Messages::addError('Esta chave não pode ser mostrada: ela foi revogada ou criada antes de as chaves serem guardadas. Crie uma nova e revogue esta.');
            header('Location: /admin/apikey/index');
            exit;
        }

        return $key;
    }

    private static function revealBreadcrumb(): void
    {
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Chaves da API', 'url' => '/admin/apikey/index'],
            ['label' => 'Ver chave'],
        ]);
    }

    public function Revoke(): void
    {
        $key = ApiKeyModel::get((int) ($_POST['key_id'] ?? 0));
        if ($key !== null && ApiKeyModel::revoke((int) $key['key_id'])) {
            \isModuleEnabled('iter_log') && OperationLog::record('delete', 'api_key', (int) $key['key_id'], $key['name'], ['Nome' => $key['name'], 'Chave' => $key['key_hint']], []);
            Messages::addSuccess("Chave \"{$key['name']}\" revogada: ela para de funcionar agora.");
        } else {
            Messages::addError('Chave não encontrada ou já revogada.');
        }
        header('Location: /admin/apikey/index');
        exit;
    }
}
