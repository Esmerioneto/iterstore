<?php

declare(strict_types=1);

namespace Iter\Admin\View;

use Iter\Core\View\ViewComponentInterface;

class ResetForm implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'admin/reset-form';
    }

    public function getData(): array
    {
        return [
            'id' => (int) ($_GET['id'] ?? 0),
            'token' => (string) ($_GET['token'] ?? ''),
        ];
    }
}
