<?php

declare(strict_types=1);

namespace Iter\Catalog\Controllers;

use Iter\Admin\Breadcrumb;
use Iter\Catalog\Model\Product as ProductModel;
use Iter\Catalog\Model\ProductService;
use Iter\Core\Messages;
use Iter\Core\View\Layout;
use Iter\Eav\Model\AttributeException;
use Iter\Eav\Model\ValueService;

class Product
{
    public const ADMIN_RESOURCE = 'Iter_Catalog::product';

    public function Index(): void
    {
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Produtos'],
        ]);

        echo Layout::renderAdmin('admin_product_index');
    }

    public function Create(): void
    {
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Produtos', 'url' => '/admin/product/index'],
            ['label' => 'Novo'],
        ]);

        echo Layout::renderAdmin('admin_product_form');
    }

    public function Edit(): void
    {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id <= 0 || !(new ProductModel())->load($id)->getId()) {
            Messages::addError('Produto não encontrado.');
            header('Location: /admin/product/index');
            exit;
        }

        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Produtos', 'url' => '/admin/product/index'],
            ['label' => 'Editar'],
        ]);

        echo Layout::renderAdmin('admin_product_form');
    }

    public function Save(): void
    {
        $data = [
            'attribute_set_id' => (int) ($_POST['attribute_set_id'] ?? 0),
            'sku' => trim($_POST['sku'] ?? ''),
            'name' => trim($_POST['name'] ?? ''),
            'is_active' => !empty($_POST['is_active']) ? 1 : 0,
            'category_ids' => (array) ($_POST['category_ids'] ?? []), // the form always lists the categories
        ];
        $id = !empty($_POST['product_id']) ? (int) $_POST['product_id'] : null;

        try {
            $attributes = ValueService::fromRequest('attributes');
            $service = new ProductService();
            $id ? $service->update($id, $data, $attributes) : $service->create($data, $attributes);
            Messages::addSuccess($id ? 'Produto atualizado com sucesso.' : 'Produto criado com sucesso.');
        } catch (AttributeException $e) {
            Messages::addError($e->getMessage());
            if ($e->errorCode === 'PRODUCT_NOT_FOUND') {
                header('Location: /admin/product/index');
                exit;
            }
            // uploads can't be kept across the redirect; only the typed values go back
            $_SESSION['iter_old_input'] = $data + ['attributes' => is_array($_POST['attributes'] ?? null) ? $_POST['attributes'] : []];
            header('Location: ' . ($id ? '/admin/product/edit?id=' . $id : '/admin/product/create?set=' . $data['attribute_set_id']));
            exit;
        }

        header('Location: /admin/product/index');
        exit;
    }

    public function Delete(): void
    {
        $id = !empty($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        try {
            (new ProductService())->delete($id);
            Messages::addSuccess('Produto removido com sucesso.');
        } catch (AttributeException $e) {
            Messages::addError($e->getMessage());
        }

        header('Location: /admin/product/index');
        exit;
    }
}
