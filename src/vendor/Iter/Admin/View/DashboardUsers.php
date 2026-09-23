<?php

declare(strict_types=1);

namespace Iter\Admin\View;

use Iter\Admin\Auth;
use Iter\Admin\Model\AdminUser;
use Iter\Core\View\ViewComponentInterface;

class DashboardUsers implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'admin/dashboard-users';
    }

    public function getData(): array
    {
        // the dashboard is open to every admin; this card only to who manages users
        if (!Auth::isAllowed('Iter_Admin::user')) {
            return ['summary' => null];
        }

        return ['summary' => (new AdminUser())->getResource()->getSummary()];
    }
}
