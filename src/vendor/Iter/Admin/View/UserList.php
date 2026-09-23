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
        return [
            'users' => (new AdminUser())->getList(),
        ];
    }
}
