<?php

declare(strict_types=1);

namespace Iter\Eav\Controllers;

use Iter\Admin\Breadcrumb;
use Iter\Core\Messages;
use Iter\Core\View\Layout;
use Iter\Eav\Model\Attribute as AttributeModel;
use Iter\Eav\Model\AttributeException;
use Iter\Eav\Model\AttributeService;

class Attribute
{
    public const ADMIN_RESOURCE = 'Iter_Eav::attribute';

    public function Index(): void
    {
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Atributos'],
        ]);

        echo Layout::renderAdmin('admin_attribute_index');
    }

    public function Create(): void
    {
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Atributos', 'url' => '/admin/attribute/index'],
            ['label' => 'Novo'],
        ]);

        echo Layout::renderAdmin('admin_attribute_form');
    }

    public function Edit(): void
    {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id <= 0 || !(new AttributeModel())->load($id)->getId()) {
            Messages::addError('Atributo não encontrado.');
            header('Location: /admin/attribute/index');
            exit;
        }

        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Atributos', 'url' => '/admin/attribute/index'],
            ['label' => 'Editar'],
        ]);

        echo Layout::renderAdmin('admin_attribute_form');
    }

    public function Save(): void
    {
        // is_system is deliberately not read: only migrations create system attributes
        $data = [
            'entity_type' => trim($_POST['entity_type'] ?? ''),
            'code' => strtolower(trim($_POST['code'] ?? '')),
            'label' => trim($_POST['label'] ?? ''),
            'input_type' => trim($_POST['input_type'] ?? ''),
            'default_value' => trim($_POST['default_value'] ?? ''),
            'sort_order' => (int) ($_POST['sort_order'] ?? 0),
        ];
        foreach (['is_required', 'is_visible', 'is_searchable', 'is_filterable', 'is_sortable'] as $flag) {
            $data[$flag] = !empty($_POST[$flag]) ? 1 : 0;
        }
        // absent when the form disabled the options block (input type without options)
        if (isset($_POST['options']) && is_array($_POST['options'])) {
            $data['options'] = array_values($_POST['options']);
        }
        $id = !empty($_POST['attribute_id']) ? (int) $_POST['attribute_id'] : null;

        try {
            $service = new AttributeService();
            $id ? $service->update($id, $data) : $service->create($data);
            Messages::addSuccess($id ? 'Atributo atualizado com sucesso.' : 'Atributo criado com sucesso.');
        } catch (AttributeException $e) {
            Messages::addError($e->getMessage());
            if ($e->errorCode === 'ATTRIBUTE_NOT_FOUND') {
                header('Location: /admin/attribute/index');
                exit;
            }
            $_SESSION['iter_old_input'] = $data;
            header('Location: ' . ($id ? '/admin/attribute/edit?id=' . $id : '/admin/attribute/create'));
            exit;
        }

        header('Location: /admin/attribute/index');
        exit;
    }

    public function Delete(): void
    {
        $id = !empty($_POST['attribute_id']) ? (int) $_POST['attribute_id'] : 0;
        try {
            (new AttributeService())->delete($id);
            Messages::addSuccess('Atributo removido com sucesso.');
        } catch (AttributeException $e) {
            Messages::addError($e->getMessage());
        }

        header('Location: /admin/attribute/index');
        exit;
    }
}
