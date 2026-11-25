<?php

declare(strict_types=1);

namespace Iter\Lgpd\Controllers;

use Iter\Admin\Breadcrumb;
use Iter\Core\View\Layout;

/** LGPD → Inventário: the personal data of the enabled modules, and who receives it */
class Lgpdinventory
{
    public const ADMIN_RESOURCE = 'Iter_Lgpd::inventory';

    public function Index(): void
    {
        Breadcrumb::set([['label' => 'Dashboard', 'url' => '/admin/index'], ['label' => 'LGPD'], ['label' => 'Inventário']]);

        echo Layout::renderAdmin('admin_lgpdinventory_index');
    }
}
