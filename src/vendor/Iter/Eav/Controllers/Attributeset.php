<?php

declare(strict_types=1);

namespace Iter\Eav\Controllers;

use Iter\Admin\Breadcrumb;
use Iter\Core\Messages;
use Iter\Core\View\Layout;
use Iter\Eav\Model\AttributeException;
use Iter\Eav\Model\AttributeSet as AttributeSetModel;
use Iter\Eav\Model\AttributeSetService;

class Attributeset
{
    public const ADMIN_RESOURCE = 'Iter_Eav::attribute_set';

    public function Index(): void
    {
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Conjuntos de atributos'],
        ]);

        echo Layout::renderAdmin('admin_attributeset_index');
    }

    public function Create(): void
    {
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Conjuntos de atributos', 'url' => '/admin/attributeset/index'],
            ['label' => 'Novo'],
        ]);

        echo Layout::renderAdmin('admin_attributeset_form');
    }

    public function Edit(): void
    {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id <= 0 || !(new AttributeSetModel())->load($id)->getId()) {
            Messages::addError('Conjunto não encontrado.');
            header('Location: /admin/attributeset/index');
            exit;
        }

        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Conjuntos de atributos', 'url' => '/admin/attributeset/index'],
            ['label' => 'Editar'],
        ]);

        echo Layout::renderAdmin('admin_attributeset_form');
    }

    public function Preview(): void
    {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id <= 0 || !(new AttributeSetModel())->load($id)->getId()) {
            Messages::addError('Conjunto não encontrado.');
            header('Location: /admin/attributeset/index');
            exit;
        }

        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Conjuntos de atributos', 'url' => '/admin/attributeset/index'],
            ['label' => 'Pré-visualizar'],
        ]);

        echo Layout::renderAdmin('admin_attributeset_preview');
    }

    public function Save(): void
    {
        $data = [
            'entity_type' => trim($_POST['entity_type'] ?? ''),
            'code' => strtolower(trim($_POST['code'] ?? '')),
            'label' => trim($_POST['label'] ?? ''),
            'is_default' => !empty($_POST['is_default']) ? 1 : 0,
        ];
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            $data['items'] = $_POST['items'];
        }
        $id = !empty($_POST['attribute_set_id']) ? (int) $_POST['attribute_set_id'] : null;

        try {
            $service = new AttributeSetService();
            $set = $id ? $service->update($id, $data) : $service->create($data);
        } catch (AttributeException $e) {
            Messages::addError($e->getMessage());
            if ($e->errorCode === 'ATTRIBUTE_SET_NOT_FOUND') {
                header('Location: /admin/attributeset/index');
                exit;
            }
            $_SESSION['iter_old_input'] = $data;
            header('Location: ' . ($id ? '/admin/attributeset/edit?id=' . $id : '/admin/attributeset/create'));
            exit;
        }

        if ($id) {
            Messages::addSuccess('Conjunto atualizado com sucesso.');
            header('Location: /admin/attributeset/index');
        } else {
            // a new set is empty: go straight to picking its attributes
            Messages::addSuccess('Conjunto criado. Agora escolha os atributos.');
            header('Location: /admin/attributeset/edit?id=' . (int) $set->getId());
        }
        exit;
    }

    public function Delete(): void
    {
        $id = !empty($_POST['attribute_set_id']) ? (int) $_POST['attribute_set_id'] : 0;
        try {
            (new AttributeSetService())->delete($id);
            Messages::addSuccess('Conjunto removido com sucesso.');
        } catch (AttributeException $e) {
            Messages::addError($e->getMessage());
        }

        header('Location: /admin/attributeset/index');
        exit;
    }
}
