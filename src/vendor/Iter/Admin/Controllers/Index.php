<?php

declare(strict_types=1);

namespace Iter\Admin\Controllers;

use Iter\Admin\Breadcrumb;
use Iter\Core\View\Layout;

class Index
{
    public const ADMIN_RESOURCE = 'Iter_Admin::dashboard';

    public function Index(): void
    {
        Breadcrumb::set([
            ['label' => 'Dashboard'],
        ]);

        echo Layout::renderAdmin('admin_dashboard');
    }
}
