<?php

declare(strict_types=1);

namespace Iter\Store\Controllers;

use Iter\Admin\Breadcrumb;
use Iter\Core\Messages;
use Iter\Core\View\Layout;
use Iter\Store\Model\Group;
use Iter\Store\Model\Store as StoreModel;

class Store
{
    public const ADMIN_RESOURCE = 'Iter_Store::store';

    public function Index(): void
    {
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Loja'],
        ]);

        echo Layout::renderAdmin('admin_store_index');
    }

    public function Create(): void
    {
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Loja', 'url' => '/admin/store/index'],
            ['label' => 'Nova'],
        ]);

        echo Layout::renderAdmin('admin_store_form');
    }

    public function Edit(): void
    {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $store = new StoreModel();
        if ($id > 0) {
            $store->load($id);
        }

        if (!$store->getId()) {
            Messages::addError('Loja não encontrada.');
            header('Location: /admin/store/index');
            exit;
        }

        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Loja', 'url' => '/admin/store/index'],
            ['label' => 'Editar'],
        ]);

        echo Layout::renderAdmin('admin_store_form');
    }

    public function Save(): void
    {
        $data = [
            'store_name' => trim($_POST['store_name'] ?? ''),
            'webview' => trim($_POST['webview'] ?? ''),
            'theme' => trim($_POST['theme'] ?? ''),
        ];
        $groupId = !empty($_POST['group_id']) ? (int) $_POST['group_id'] : null;
        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
        $redirect = $id ? '/admin/store/edit?id=' . $id : '/admin/store/create';

        // all three are NOT NULL in the store table
        $errors = in_array('', $data, true) ? ['Nome, webview e tema são obrigatórios.'] : [];
        if ($groupId !== null && !(new Group())->load($groupId)->getId()) {
            $errors[] = 'Grupo de loja inválido.';
        }
        $data['group_id'] = $groupId;

        if (!empty($errors)) {
            foreach ($errors as $error) {
                Messages::addError($error);
            }
            $_SESSION['iter_old_input'] = $data;
            header('Location: ' . $redirect);
            exit;
        }

        $model = new StoreModel();
        if ($id) {
            $model->load($id);
            if (!$model->getId()) {
                Messages::addError('Loja não encontrada.');
                header('Location: /admin/store/index');
                exit;
            }
        }

        $model->addData($data)->save();
        Messages::addSuccess($id ? 'Loja atualizada com sucesso.' : 'Loja criada com sucesso.');

        header('Location: /admin/store/index');
        exit;
    }

    public function Delete(): void
    {
        $id = !empty($_POST['id']) ? (int) $_POST['id'] : 0;
        if ($id > 0) {
            (new StoreModel())->setId($id)->delete();
            Messages::addSuccess('Loja removida com sucesso.');
        }

        header('Location: /admin/store/index');
        exit;
    }
}
