<?php

declare(strict_types=1);

namespace Iter\Admin\Controllers;

use Iter\Admin\Acl;
use Iter\Admin\Auth;
use Iter\Admin\Breadcrumb;
use Iter\Admin\Model\AdminRole;
use Iter\Core\Messages;
use Iter\Core\View\Layout;

class Role
{
    public const ADMIN_RESOURCE = 'Iter_Admin::role';

    public function Index(): void
    {
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Perfis'],
        ]);

        echo Layout::renderAdmin('admin_role_index');
    }

    public function Create(): void
    {
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Perfis', 'url' => '/admin/role/index'],
            ['label' => 'Novo'],
        ]);

        echo Layout::renderAdmin('admin_role_form');
    }

    public function Edit(): void
    {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $role = new AdminRole();
        if ($id > 0) {
            $role->load($id);
        }

        if (!$role->getId()) {
            Messages::addError('Perfil não encontrado.');
            header('Location: /admin/role/index');
            exit;
        }

        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Perfis', 'url' => '/admin/role/index'],
            ['label' => 'Editar'],
        ]);

        echo Layout::renderAdmin('admin_role_form');
    }

    public function Save(): void
    {
        $roleName = trim($_POST['role_name'] ?? '');
        $resources = $_POST['resources'] ?? [];
        $id = !empty($_POST['role_id']) ? (int) $_POST['role_id'] : null;
        $redirect = $id ? '/admin/role/edit?id=' . $id : '/admin/role/create';

        // own role must keep access to this screen, or nobody with it can fix the grants
        if ($id !== null && $id === (int) Auth::user()->getData('role_id')
            && !Acl::covers(is_array($resources) ? $resources : [], 'Iter_Admin::role')) {
            Messages::addError('O seu próprio perfil precisa manter acesso a Perfis.');
            $_SESSION['iter_old_input'] = ['role_name' => $roleName, 'resources' => $resources];
            header('Location: ' . $redirect);
            exit;
        }

        if ($roleName === '') {
            Messages::addError('Nome do perfil é obrigatório.');
            $_SESSION['iter_old_input'] = ['role_name' => $roleName, 'resources' => $resources];
            header('Location: ' . $redirect);
            exit;
        }

        $model = new AdminRole();
        if ($id) {
            $model->load($id);
        }

        $model->setData('role_name', $roleName);
        $model->setResources($resources);

        try {
            $model->save();
            Messages::addSuccess($id ? 'Perfil atualizado com sucesso.' : 'Perfil criado com sucesso.');
        } catch (\PDOException $e) {
            Messages::addError('Não foi possível salvar: nome de perfil já existe.');
            $_SESSION['iter_old_input'] = ['role_name' => $roleName, 'resources' => $resources];
            header('Location: ' . $redirect);
            exit;
        }

        header('Location: /admin/role/index');
        exit;
    }

    public function Delete(): void
    {
        $id = !empty($_POST['role_id']) ? (int) $_POST['role_id'] : 0;
        if ($id === (int) Auth::user()->getData('role_id')) {
            Messages::addError('Você não pode remover o próprio perfil.');
        } elseif ($id > 0) {
            (new AdminRole())->setId($id)->delete();
            Messages::addSuccess('Perfil removido com sucesso.');
        }

        header('Location: /admin/role/index');
        exit;
    }
}
