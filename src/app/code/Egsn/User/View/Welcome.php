<?php

declare(strict_types=1);

namespace Egsn\User\View;

use Iter\Core\View\ViewComponentInterface;

class Welcome implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'user/welcome';
    }

    public function getData(): array
    {
        return [
            'message' => 'Bem-vindo ao modulo de usuarios!',
        ];
    }
}
