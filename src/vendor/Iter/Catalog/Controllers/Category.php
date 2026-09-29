<?php

declare(strict_types=1);

namespace Iter\Catalog\Controllers;

use Iter\Admin\Breadcrumb;
use Iter\Catalog\Model\Category as CategoryModel;
use Iter\Catalog\Model\CategoryService;
use Iter\Core\Messages;
use Iter\Core\View\Layout;
use Iter\Eav\Model\AttributeException;
use Iter\Eav\Model\ValueService;

class Category
{
    public const ADMIN_RESOURCE = 'Iter_Catalog::category';

    public function Index(): void
    {
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Categorias'],
        ]);

        echo Layout::renderAdmin('admin_category_index');
    }

    public function Create(): void
    {
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Categorias', 'url' => '/admin/category/index'],
            ['label' => 'Nova'],
        ]);

        echo Layout::renderAdmin('admin_category_form');
    }

    public function Edit(): void
    {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id <= 0 || !(new CategoryModel())->load($id)->getId()) {
            Messages::addError('Categoria não encontrada.');
            header('Location: /admin/category/index');
            exit;
        }

        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Categorias', 'url' => '/admin/category/index'],
            ['label' => 'Editar'],
        ]);

        echo Layout::renderAdmin('admin_category_form');
    }

    public function Save(): void
    {
        $data = [
            'website_id' => (int) ($_POST['website_id'] ?? 0),
            'parent_id' => (int) ($_POST['parent_id'] ?? 0),
            'name' => trim($_POST['name'] ?? ''),
            'url_key' => trim($_POST['url_key'] ?? ''),
            'is_active' => !empty($_POST['is_active']) ? 1 : 0,
            'position' => (int) ($_POST['position'] ?? 0),
        ];
        $id = !empty($_POST['category_id']) ? (int) $_POST['category_id'] : null;

        try {
            $attributes = ValueService::fromRequest('attributes');
            $service = new CategoryService();
            $id ? $service->update($id, $data, $attributes) : $service->create($data, $attributes);
            Messages::addSuccess($id ? 'Categoria atualizada com sucesso.' : 'Categoria criada com sucesso.');
        } catch (AttributeException $e) {
            Messages::addError($e->getMessage());
            if ($e->errorCode === 'CATEGORY_NOT_FOUND' && $id && !(new CategoryModel())->load($id)->getId()) {
                header('Location: /admin/category/index');
                exit;
            }
            $_SESSION['iter_old_input'] = $data + ['attributes' => is_array($_POST['attributes'] ?? null) ? $_POST['attributes'] : []];
            header('Location: ' . ($id ? '/admin/category/edit?id=' . $id : '/admin/category/create'));
            exit;
        }

        header('Location: /admin/category/index');
        exit;
    }

    public function Delete(): void
    {
        $id = !empty($_POST['category_id']) ? (int) $_POST['category_id'] : 0;
        try {
            (new CategoryService())->delete($id);
            Messages::addSuccess('Categoria removida com sucesso.');
        } catch (AttributeException $e) {
            Messages::addError($e->getMessage());
        }

        header('Location: /admin/category/index');
        exit;
    }
}
