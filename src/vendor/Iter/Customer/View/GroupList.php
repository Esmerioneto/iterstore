<?php

declare(strict_types=1);

namespace Iter\Customer\View;

use Iter\Admin\View\Pager;
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
        [$groups, $pager] = Pager::slice((new Group())->getResource()->getList());

        return [
            'groups' => $groups,
            'pager' => $pager,
        ];
    }
}
