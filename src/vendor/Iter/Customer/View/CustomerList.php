<?php

declare(strict_types=1);

namespace Iter\Customer\View;

use Iter\Admin\View\Pager;
use Iter\Core\View\ViewComponentInterface;
use Iter\Customer\Model\Customer;
use Iter\Customer\Model\Group;
use Iter\Store\Model\Website;

class CustomerList implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'customer/customer-list';
    }

    public function getData(): array
    {
        $filters = [];
        foreach (['customer_type', 'group_id', 'website_id', 'is_active', 'q'] as $key) {
            $filters[$key] = trim((string) ($_GET[$key] ?? ''));
        }
        [$result, $pager] = Pager::search(static fn (int $page, int $limit) => (new Customer())->getResource()->search($filters, $page, $limit));

        return [
            'customers' => $result['items'],
            'filters' => $filters,
            'pager' => $pager,
            'total' => $result['total'],
            'groups' => array_column((new Group())->getResource()->getList(), 'name', 'customer_group_id'),
            'websites' => array_column((new Website())->getList(), 'name', 'website_id'),
        ];
    }
}
