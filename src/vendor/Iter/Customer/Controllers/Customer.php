<?php

declare(strict_types=1);

namespace Iter\Customer\Controllers;

use Iter\Admin\Breadcrumb;
use Iter\Core\Messages;
use Iter\Core\View\Layout;
use Iter\Customer\Model\Customer as CustomerModel;
use Iter\Customer\Model\CustomerService;
use Iter\Eav\Model\AttributeException;
use Iter\Eav\Model\ValueService;

class Customer
{
    public const ADMIN_RESOURCE = 'Iter_Customer::customer';

    public function Index(): void
    {
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Clientes'],
        ]);

        echo Layout::renderAdmin('admin_customer_index');
    }

    public function Create(): void
    {
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Clientes', 'url' => '/admin/customer/index'],
            ['label' => 'Novo'],
        ]);

        echo Layout::renderAdmin('admin_customer_form');
    }

    public function Edit(): void
    {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id <= 0 || !(new CustomerModel())->load($id)->getId()) {
            Messages::addError('Cliente não encontrado.');
            header('Location: /admin/customer/index');
            exit;
        }

        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Clientes', 'url' => '/admin/customer/index'],
            ['label' => 'Editar'],
        ]);

        echo Layout::renderAdmin('admin_customer_form');
    }

    public function Save(): void
    {
        $type = ($_POST['customer_type'] ?? '') === 'pj' ? 'pj' : 'pf';
        $data = [
            'attribute_set_id' => (int) ($_POST['attribute_set_id'] ?? 0),
            'website_id' => (int) ($_POST['website_id'] ?? 0),
            'group_id' => (int) ($_POST['group_id'] ?? 0),
            'email' => trim($_POST['email'] ?? ''),
            'firstname' => trim($_POST['firstname'] ?? ''),
            'lastname' => trim($_POST['lastname'] ?? ''),
            'customer_type' => $type,
            // each type's block has its own document field; only the chosen type's one counts
            'taxvat' => trim($_POST[$type === 'pf' ? 'cpf' : 'cnpj'] ?? ''),
            'dob' => trim($_POST['dob'] ?? ''),
            'company_name' => trim($_POST['company_name'] ?? ''),
            'trade_name' => trim($_POST['trade_name'] ?? ''),
            'state_registration' => trim($_POST['state_registration'] ?? ''),
            'is_ie_exempt' => !empty($_POST['is_ie_exempt']) ? 1 : 0,
            'is_active' => !empty($_POST['is_active']) ? 1 : 0,
        ];
        $id = !empty($_POST['customer_id']) ? (int) $_POST['customer_id'] : null;

        try {
            $attributes = ValueService::fromRequest('attributes');
            $service = new CustomerService();
            $addresses = is_array($_POST['addresses'] ?? null) ? $_POST['addresses'] : null;
            $id ? $service->update($id, $data, $attributes, $addresses) : $service->create($data, $attributes, $addresses);
            Messages::addSuccess($id ? 'Cliente atualizado com sucesso.' : 'Cliente criado com sucesso.');
        } catch (AttributeException $e) {
            Messages::addError($e->getMessage());
            if ($e->errorCode === 'CUSTOMER_NOT_FOUND') {
                header('Location: /admin/customer/index');
                exit;
            }
            $_SESSION['iter_old_input'] = $data + [
                'attributes' => is_array($_POST['attributes'] ?? null) ? $_POST['attributes'] : [],
                'addresses' => is_array($_POST['addresses'] ?? null) ? array_values(array_filter($_POST['addresses'], 'is_array')) : null,
            ];
            header('Location: ' . ($id ? '/admin/customer/edit?id=' . $id : '/admin/customer/create?set=' . $data['attribute_set_id']));
            exit;
        }

        header('Location: /admin/customer/index');
        exit;
    }

    public function Delete(): void
    {
        $id = !empty($_POST['customer_id']) ? (int) $_POST['customer_id'] : 0;
        try {
            (new CustomerService())->delete($id);
            Messages::addSuccess('Cliente removido com sucesso.');
        } catch (AttributeException $e) {
            Messages::addError($e->getMessage());
        }

        header('Location: /admin/customer/index');
        exit;
    }
}
