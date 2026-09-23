<?php

declare(strict_types=1);

namespace Iter\Admin\View;

use Iter\Admin\MenuRegistry;
use Iter\Core\View\ViewComponentInterface;

class Menu implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'admin/menu';
    }

    public function getData(): array
    {
        return [
            'items' => MenuRegistry::all(),
        ];
    }
}
