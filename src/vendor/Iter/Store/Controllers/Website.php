<?php

declare(strict_types=1);

namespace Iter\Store\Controllers;

use Iter\Admin\Breadcrumb;
use Iter\Core\Messages;
use Iter\Core\View\Layout;
use Iter\Store\Model\Website as WebsiteModel;

class Website
{
    public const ADMIN_RESOURCE = 'Iter_Store::website';

    public function Index(): void
    {
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Websites'],
        ]);

        echo Layout::renderAdmin('admin_website_index');
    }

    public function Create(): void
    {
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Websites', 'url' => '/admin/website/index'],
            ['label' => 'Novo'],
        ]);

        echo Layout::renderAdmin('admin_website_form');
    }

    public function Edit(): void
    {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id <= 0 || !(new WebsiteModel())->load($id)->getId()) {
            Messages::addError('Website não encontrado.');
            header('Location: /admin/website/index');
            exit;
        }

        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Websites', 'url' => '/admin/website/index'],
            ['label' => 'Editar'],
        ]);

        echo Layout::renderAdmin('admin_website_form');
    }

    public function Save(): void
    {
        $data = [
            'code' => strtolower(trim($_POST['code'] ?? '')),
            'name' => trim($_POST['name'] ?? ''),
            'sort_order' => max(0, min(32767, (int) ($_POST['sort_order'] ?? 0))), // SMALLINT
            'is_default' => !empty($_POST['is_default']) ? 1 : 0,
        ];
        $id = !empty($_POST['website_id']) ? (int) $_POST['website_id'] : null;
        $redirect = $id ? '/admin/website/edit?id=' . $id : '/admin/website/create';

        $errors = [];
        // Magento rule: starts with a letter, then lowercase letters/digits/underscore
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

        $model = new WebsiteModel();
        if ($id && !$model->load($id)->getId()) {
            Messages::addError('Website não encontrado.');
            header('Location: /admin/website/index');
            exit;
        }

        try {
            $model->addData($data)->save();
            Messages::addSuccess($id ? 'Website atualizado com sucesso.' : 'Website criado com sucesso.');
        } catch (\PDOException $e) {
            Messages::addError('Não foi possível salvar: código já existe.');
            $_SESSION['iter_old_input'] = $data;
            header('Location: ' . $redirect);
            exit;
        }

        header('Location: /admin/website/index');
        exit;
    }

    public function Delete(): void
    {
        $id = !empty($_POST['website_id']) ? (int) $_POST['website_id'] : 0;
        if ($id > 0) {
            try {
                (new WebsiteModel())->setId($id)->delete();
                Messages::addSuccess('Website removido com sucesso.');
            } catch (\PDOException $e) {
                // store.group_id is ON DELETE RESTRICT, so the cascade to groups stops here
                Messages::addError('Não é possível remover: há lojas vinculadas aos grupos deste website.');
            }
        }

        header('Location: /admin/website/index');
        exit;
    }
}
