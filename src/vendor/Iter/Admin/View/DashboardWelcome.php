<?php

declare(strict_types=1);

namespace Iter\Admin\View;

use Iter\Admin\Auth;
use Iter\Core\View\ViewComponentInterface;

class DashboardWelcome implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'admin/dashboard-welcome';
    }

    public function getData(): array
    {
        return ['name' => (string) (Auth::user()?->getData('firstname') ?: Auth::user()?->getData('username'))];
    }
}
