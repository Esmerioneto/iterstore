<?php

declare(strict_types=1);

namespace Iter\Admin\View;

use Iter\Core\View\ViewComponentInterface;

class LoginForm implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'admin/login-form';
    }

    public function getData(): array
    {
        return [];
    }
}
