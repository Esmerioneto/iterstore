<?php

declare(strict_types=1);

namespace Iter\Admin\View;

use Iter\Core\Messages as MessagesBag;
use Iter\Core\View\ViewComponentInterface;

class Messages implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'admin/messages';
    }

    public function getData(): array
    {
        return [
            'messages' => MessagesBag::pull(),
        ];
    }
}
