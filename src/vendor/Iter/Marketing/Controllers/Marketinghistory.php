<?php

declare(strict_types=1);

namespace Iter\Marketing\Controllers;

use Iter\Admin\Breadcrumb;
use Iter\Core\View\Layout;

/** Marketing → Histórico: every suggestion and what became of it, with the return per rule */
class Marketinghistory
{
    public const ADMIN_RESOURCE = 'Iter_Marketing::log';

    public function Index(): void
    {
        Breadcrumb::set([['label' => 'Dashboard', 'url' => '/admin/index'], ['label' => 'Marketing'], ['label' => 'Histórico']]);

        echo Layout::renderAdmin('admin_marketinghistory_index');
    }
}
