<?php

declare(strict_types=1);

namespace Iter\Admin\View;

use Iter\Core\View\ViewComponentInterface;

class Footer implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'admin/footer';
    }

    public function getData(): array
    {
        return [
            'year' => date('Y'),
        ];
    }
}
