<?php

declare(strict_types=1);

namespace Iter\Store\View;

use Iter\Admin\View\Pager;
use Iter\Core\View\ViewComponentInterface;
use Iter\Store\Model\Group;

class GroupList implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'store/group-list';
    }

    public function getData(): array
    {
        [$groups, $pager] = Pager::slice((new Group())->getList());

        return [
            'groups' => $groups,
            'pager' => $pager,
        ];
    }
}
