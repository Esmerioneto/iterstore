<?php

declare(strict_types=1);

namespace Iter\Admin\View;

use Iter\Admin\Auth;
use Iter\Core\View\ViewComponentInterface;

class Header implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'admin/header';
    }

    public function getData(): array
    {
        return [
            'title' => 'Iterstore Admin',
            'username' => Auth::user()?->getData('username'),
        ];
    }
}
