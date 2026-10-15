<?php

declare(strict_types=1);

namespace Iter\Admin\View;

use Iter\Admin\View\Sort;
use Iter\Admin\Model\AdminUser;
use Iter\Core\View\ViewComponentInterface;

class UserList implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'admin/user-list';
    }

    public function getData(): array
    {
        $active = $_GET['is_active'] ?? '';
        $users = (new AdminUser())->getList();
        $users = in_array($active, ['0', '1'], true) ? array_filter($users, static fn ($u) => (string) (int) $u['is_active'] === $active) : $users;
        [$users, $pager, $sort, $dir, $q] = Sort::slice($users, ['id' => 'user_id', 'username' => 'username', 'name' => 'firstname', 'email' => 'email', 'role' => 'role_name', 'is_active' => 'is_active'], 'id', 'asc', ['username', 'firstname', 'lastname', 'email']);

        return [
        'sort' => $sort,
            'dir' => $dir,
            'q' => $q,
            'is_active' => in_array($active, ['0', '1'], true) ? $active : '',
            'users' => $users,
            'pager' => $pager,
        ];
    }
}
