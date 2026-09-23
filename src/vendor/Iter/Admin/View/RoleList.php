<?php

declare(strict_types=1);

namespace Iter\Admin\View;

use Iter\Admin\Model\AdminRole;
use Iter\Core\View\ViewComponentInterface;

class RoleList implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'admin/role-list';
    }

    public function getData(): array
    {
        return [
            'roles' => (new AdminRole())->getList(),
        ];
    }
}
