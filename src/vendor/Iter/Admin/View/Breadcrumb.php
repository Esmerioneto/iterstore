<?php

declare(strict_types=1);

namespace Iter\Admin\View;

use Iter\Admin\Breadcrumb as BreadcrumbTrail;
use Iter\Core\View\ViewComponentInterface;

class Breadcrumb implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'admin/breadcrumb';
    }

    public function getData(): array
    {
        return [
            'items' => BreadcrumbTrail::get(),
        ];
    }
}
