<?php

declare(strict_types=1);

namespace Iter\Admin\View;

use Iter\Admin\View\Sort;
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
        [$roles, $pager, $sort, $dir, $q] = Sort::slice((new AdminRole())->getList(), ['id' => 'role_id', 'name' => 'role_name'], 'id', 'asc', ['role_name']);

        return [
        'sort' => $sort,
            'dir' => $dir,
            'q' => $q,
            'roles' => $roles,
            'pager' => $pager,
        ];
    }
}
