<?php

declare(strict_types=1);

namespace Iter\Admin\View;

use Iter\Core\View\ViewComponentInterface;

class ForgotForm implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'admin/forgot-form';
    }

    public function getData(): array
    {
        return [];
    }
}
