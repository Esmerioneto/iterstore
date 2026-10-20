<?php

declare(strict_types=1);

namespace Iter\Admin\Controllers;

use Iter\Admin\Auth;
use Iter\Admin\Breadcrumb;
use Iter\Admin\Model\ApiKey as ApiKeyModel;
use Iter\Core\Messages;
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
        Messages::addSuccess('Chave criada. Copie-a agora: ela não aparece de novo.');
        header('Location: /admin/apikey/index');
        exit;
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
