<?php

declare(strict_types=1);

namespace Iter\Customer\View;

use Iter\Core\View\ViewComponentInterface;
use Iter\Customer\Model\Customer;
use Iter\Customer\Model\Group;
use Iter\Store\Model\Website;

class CustomerList implements ViewComponentInterface
{
    private const PER_PAGE = 20;

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
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $result = (new Customer())->getResource()->search($filters, $page, self::PER_PAGE);

        return [
            'customers' => $result['items'],
            'filters' => $filters,
            'page' => $page,
            'total' => $result['total'],
            'total_pages' => max(1, (int) ceil($result['total'] / self::PER_PAGE)),
            'groups' => array_column((new Group())->getResource()->getList(), 'name', 'customer_group_id'),
            'websites' => array_column((new Website())->getList(), 'name', 'website_id'),
        ];
    }
}
