<?php

declare(strict_types=1);

namespace Iter\Customer\View;

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
        return [
            'groups' => (new Group())->getResource()->getList(),
        ];
    }
}
