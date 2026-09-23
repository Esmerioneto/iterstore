<?php

declare(strict_types=1);

namespace Egsn\User\View;

use Iter\Core\View\ViewComponentInterface;

class Info implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'user/info';
    }

    public function getData(): array
    {
        return [
            'status' => 'Modulo de usuarios ativo.',
        ];
    }
}
