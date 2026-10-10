<?php

declare(strict_types=1);

namespace Iter\Admin\View;

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
        [$users, $pager] = Pager::slice((new AdminUser())->getList());

        return [
            'users' => $users,
            'pager' => $pager,
        ];
    }
}
