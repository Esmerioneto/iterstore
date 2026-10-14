<?php

declare(strict_types=1);

namespace Iter\Admin\View;

use Iter\Core\View\ViewComponentInterface;

class AboutPage implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'admin/about-page';
    }

    public function getData(): array
    {
        return [];
    }
}
