<?php

declare(strict_types=1);

namespace Iter\Inventory\Controllers;

use Iter\Admin\Breadcrumb;
use Iter\Core\Messages;
use Iter\Core\View\Layout;
use Iter\Inventory\Model\InventoryException;
use Iter\Inventory\Model\Source as SourceModel;
use Iter\Inventory\Model\SourceService;

class Source
{
    public const ADMIN_RESOURCE = 'Iter_Inventory::source';

    public function Index(): void
    {
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Origens de estoque'],
        ]);

        echo Layout::renderAdmin('admin_source_index');
    }

    public function Create(): void
    {
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Origens de estoque', 'url' => '/admin/source/index'],
            ['label' => 'Nova'],
        ]);

        echo Layout::renderAdmin('admin_source_form');
    }

    public function Edit(): void
    {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id <= 0 || !(new SourceModel())->load($id)->getId()) {
            Messages::addError('Origem não encontrada.');
            header('Location: /admin/source/index');
            exit;
        }

        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Origens de estoque', 'url' => '/admin/source/index'],
            ['label' => 'Editar'],
        ]);

        echo Layout::renderAdmin('admin_source_form');
    }

    public function Save(): void
    {
        $data = [];
        foreach (['website_id', 'code', 'name', 'description', 'postcode', 'city', 'region', 'country_code', 'priority'] as $key) {
            $data[$key] = trim((string) ($_POST[$key] ?? ''));
        }
        $data['is_enabled'] = !empty($_POST['is_enabled']) ? 1 : 0;
        $id = !empty($_POST['source_id']) ? (int) $_POST['source_id'] : null;

        try {
            $service = new SourceService();
            $id ? $service->update($id, $data) : $service->create($data);
            Messages::addSuccess($id ? 'Origem atualizada com sucesso.' : 'Origem criada com sucesso.');
        } catch (InventoryException $e) {
            Messages::addError($e->getMessage());
            if ($e->errorCode === 'SOURCE_NOT_FOUND') {
                header('Location: /admin/source/index');
                exit;
            }
            $_SESSION['iter_old_input'] = $data;
            header('Location: ' . ($id ? '/admin/source/edit?id=' . $id : '/admin/source/create'));
            exit;
        }

        header('Location: /admin/source/index');
        exit;
    }

    public function Delete(): void
    {
        $id = !empty($_POST['source_id']) ? (int) $_POST['source_id'] : 0;
        try {
            (new SourceService())->delete($id);
            Messages::addSuccess('Origem removida com sucesso.');
        } catch (InventoryException $e) {
            Messages::addError($e->getMessage());
        }

        header('Location: /admin/source/index');
        exit;
    }
}
