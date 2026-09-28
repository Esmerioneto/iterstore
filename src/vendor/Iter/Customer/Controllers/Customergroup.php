<?php

declare(strict_types=1);

namespace Iter\Customer\Controllers;

use Iter\Admin\Breadcrumb;
use Iter\Core\Messages;
use Iter\Core\View\Layout;
use Iter\Customer\Model\Group as GroupModel;

class Customergroup
{
    public const ADMIN_RESOURCE = 'Iter_Customer::group';

    public function Index(): void
    {
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Grupos de clientes'],
        ]);

        echo Layout::renderAdmin('admin_customergroup_index');
    }

    public function Create(): void
    {
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Grupos de clientes', 'url' => '/admin/customergroup/index'],
            ['label' => 'Novo'],
        ]);

        echo Layout::renderAdmin('admin_customergroup_form');
    }

    public function Edit(): void
    {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id <= 0 || !(new GroupModel())->load($id)->getId()) {
            Messages::addError('Grupo não encontrado.');
            header('Location: /admin/customergroup/index');
            exit;
        }

        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Grupos de clientes', 'url' => '/admin/customergroup/index'],
            ['label' => 'Editar'],
        ]);

        echo Layout::renderAdmin('admin_customergroup_form');
    }

    public function Save(): void
    {
        $data = [
            'code' => strtolower(trim($_POST['code'] ?? '')),
            'name' => trim($_POST['name'] ?? ''),
        ];
        $id = !empty($_POST['customer_group_id']) ? (int) $_POST['customer_group_id'] : null;
        $redirect = $id ? '/admin/customergroup/edit?id=' . $id : '/admin/customergroup/create';

        $errors = [];
        if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/', $data['code'])) {
            $errors[] = 'Código deve começar com letra e conter apenas a-z, 0-9 e _ (máx. 32).';
        }
        if ($data['name'] === '' || mb_strlen($data['name']) > 255) {
            $errors[] = 'Nome é obrigatório (máx. 255 caracteres).';
        }
        $model = new GroupModel();
        if ($id && !$model->load($id)->getId()) {
            $errors[] = 'Grupo não encontrado.';
        }
        if (!$errors) {
            try {
                $model->addData($data)->save();
                Messages::addSuccess($id ? 'Grupo atualizado com sucesso.' : 'Grupo criado com sucesso.');
                header('Location: /admin/customergroup/index');
                exit;
            } catch (\PDOException $e) {
                $errors[] = 'Não foi possível salvar: código já existe.';
            }
        }

        foreach ($errors as $error) {
            Messages::addError($error);
        }
        $_SESSION['iter_old_input'] = $data;
        header('Location: ' . $redirect);
        exit;
    }

    public function Delete(): void
    {
        $id = !empty($_POST['customer_group_id']) ? (int) $_POST['customer_group_id'] : 0;
        if ($id > 0) {
            try {
                (new GroupModel())->setId($id)->delete();
                Messages::addSuccess('Grupo removido com sucesso.');
            } catch (\PDOException $e) {
                Messages::addError('Não é possível remover: há clientes neste grupo.');
            }
        }

        header('Location: /admin/customergroup/index');
        exit;
    }
}
