<?php

declare(strict_types=1);

namespace Iter\Admin\View;

use Iter\Core\View\ViewComponentInterface;

class DashboardShell implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'admin/dashboard-shell';
    }

    public function getData(): array
    {
        return [];
    }
}
