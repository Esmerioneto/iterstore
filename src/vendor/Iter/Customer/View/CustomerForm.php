<?php

declare(strict_types=1);

namespace Iter\Customer\View;

use Iter\Admin\Auth;
use Iter\Core\View\ViewComponentInterface;
use Iter\Customer\Model\AddressService;
use Iter\Customer\Model\Customer;
use Iter\Customer\Model\CustomerService;
use Iter\Customer\Model\Group;
use Iter\Eav\Form\FormSchemaGenerator;
use Iter\Eav\Model\FormLayout;
use Iter\Store\Model\Website;

class CustomerForm implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'customer/customer-form';
    }

    public function getData(): array
    {
        $id = !empty($_GET['id']) ? (int) $_GET['id'] : null;
        $model = new Customer();
        $customer = $id && $model->load($id)->getId() ? $model->getData() : null;

        $old = $_SESSION['iter_old_input'] ?? null;
        unset($_SESSION['iter_old_input']);

        $sets = CustomerService::sets();
        // the set is fixed once the customer exists; a new one uses ?set= or the default
        $setId = (int) ($customer['attribute_set_id'] ?? $_GET['set'] ?? 0);
        if (!isset($sets[$setId])) {
            $setId = (int) key(array_filter($sets, static fn ($s) => $s['is_default']));
        }
        $values = array_merge(
            [
                'website_id' => '', 'group_id' => '', 'email' => '', 'firstname' => '', 'lastname' => '', 'customer_type' => 'pf',
                'taxvat' => '', 'dob' => '', 'company_name' => '', 'trade_name' => '', 'state_registration' => '', 'is_ie_exempt' => 0, 'is_active' => 1,
            ],
            $customer ?? [],
            $old ?? []
        );
        $groups = (new Group())->getResource()->getList();
        // a new customer starts in the first group (Geral)
        $values['group_id'] = $values['group_id'] ?: ($groups[0]['customer_group_id'] ?? '');

        return [
            'is_edit' => $customer !== null,
            'customer_id' => $id,
            'values' => $values,
            'sets' => $sets,
            'attribute_set_id' => $setId,
            'types' => Customer::TYPES,
            // after a failed save, what was posted; else the saved ones
            'addresses' => $old['addresses'] ?? ($id ? (new AddressService())->getList($id) : []),
            'regions' => AddressService::REGIONS,
            // without the permission, the default layout (even if one was saved before it was revoked)
            'can_arrange' => $canArrange = Auth::isAllowed('Iter_Customer::customer_layout'),
            'layout' => (new FormLayout(Customer::ENTITY_TYPE))->get($canArrange ? (int) Auth::user()->getId() : null),
            'groups' => array_column($groups, 'name', 'customer_group_id'),
            'websites' => array_column((new Website())->getList(), 'name', 'website_id'),
            'schema' => $sets ? (new FormSchemaGenerator())->generate(Customer::ENTITY_TYPE, $sets[$setId]['code']) : ['fields' => []],
            'attribute_values' => array_merge(
                $id ? (new CustomerService())->getValues($id) : [],
                array_filter($old['attributes'] ?? [], static fn ($v) => !is_array($v) || array_is_list($v))
            ),
        ];
    }
}
