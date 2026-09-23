<?php

declare(strict_types=1);

namespace Iter\Store\Controllers;

use Iter\Admin\Breadcrumb;
use Iter\Core\Messages;
use Iter\Core\View\Layout;
use Iter\Store\Model\Group as GroupModel;
use Iter\Store\Model\Website as WebsiteModel;

class Group
{
    public const ADMIN_RESOURCE = 'Iter_Store::group';

    public function Index(): void
    {
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Grupos de loja'],
        ]);

        echo Layout::renderAdmin('admin_group_index');
    }

    public function Create(): void
    {
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Grupos de loja', 'url' => '/admin/group/index'],
            ['label' => 'Novo'],
        ]);

        echo Layout::renderAdmin('admin_group_form');
    }

    public function Edit(): void
    {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id <= 0 || !(new GroupModel())->load($id)->getId()) {
            Messages::addError('Grupo não encontrado.');
            header('Location: /admin/group/index');
            exit;
        }

        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Grupos de loja', 'url' => '/admin/group/index'],
            ['label' => 'Editar'],
        ]);

        echo Layout::renderAdmin('admin_group_form');
    }

    public function Save(): void
    {
        $data = [
            'website_id' => (int) ($_POST['website_id'] ?? 0),
            'code' => strtolower(trim($_POST['code'] ?? '')),
            'name' => trim($_POST['name'] ?? ''),
        ];
        $id = !empty($_POST['group_id']) ? (int) $_POST['group_id'] : null;
        $redirect = $id ? '/admin/group/edit?id=' . $id : '/admin/group/create';

        $errors = [];
        if ($data['website_id'] <= 0 || !(new WebsiteModel())->load($data['website_id'])->getId()) {
            $errors[] = 'Selecione um website.';
        }
        if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/', $data['code'])) {
            $errors[] = 'Código deve começar com letra e conter apenas a-z, 0-9 e _ (máx. 32).';
        }
        if ($data['name'] === '') {
            $errors[] = 'Nome é obrigatório.';
        }
        if (!empty($errors)) {
            foreach ($errors as $error) {
                Messages::addError($error);
            }
            $_SESSION['iter_old_input'] = $data;
            header('Location: ' . $redirect);
            exit;
        }

        $model = new GroupModel();
        if ($id && !$model->load($id)->getId()) {
            Messages::addError('Grupo não encontrado.');
            header('Location: /admin/group/index');
            exit;
        }

        try {
            $model->addData($data)->save();
            Messages::addSuccess($id ? 'Grupo atualizado com sucesso.' : 'Grupo criado com sucesso.');
        } catch (\PDOException $e) {
            Messages::addError('Não foi possível salvar: código já existe.');
            $_SESSION['iter_old_input'] = $data;
            header('Location: ' . $redirect);
            exit;
        }

        header('Location: /admin/group/index');
        exit;
    }

    public function Delete(): void
    {
        $id = !empty($_POST['group_id']) ? (int) $_POST['group_id'] : 0;
        if ($id > 0) {
            try {
                (new GroupModel())->setId($id)->delete();
                Messages::addSuccess('Grupo removido com sucesso.');
            } catch (\PDOException $e) {
                Messages::addError('Não é possível remover: há lojas vinculadas a este grupo.');
            }
        }

        header('Location: /admin/group/index');
        exit;
    }
}
