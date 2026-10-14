<?php

declare(strict_types=1);

namespace Iter\Admin\Controllers;

use Iter\Admin\Breadcrumb;
use Iter\Core\View\Layout;

class About
{
    public const ADMIN_RESOURCE = 'Iter_Admin::about';

    public function Index(): void
    {
        Breadcrumb::set([
            ['label' => 'Sobre'],
        ]);

        echo Layout::renderAdmin('admin_about');
    }
}
