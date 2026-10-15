<?php

declare(strict_types=1);

namespace Iter\Customer\View;

use Iter\Admin\View\Pager;
use Iter\Admin\View\Sort;
use Iter\Core\View\ViewComponentInterface;
use Iter\Customer\Model\Group;

class GroupList implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'customer/group-list';
    }

    public function getData(): array
    {
        [$groups, $pager, $sort, $dir, $q] = Sort::slice((new Group())->getResource()->getList(), ['id' => 'customer_group_id', 'code' => 'code', 'name' => 'name', 'customers' => 'customer_count'], 'id', 'asc', ['code', 'name']);

        return [
        'sort' => $sort,
            'dir' => $dir,
            'q' => $q,
            'groups' => $groups,
            'pager' => $pager,
        ];
    }
}
