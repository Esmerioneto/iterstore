<?php

declare(strict_types=1);

namespace Iter\Mail\View;

use Iter\Admin\Auth;
use Iter\Core\View\ViewComponentInterface;

class TestForm implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'mail/test-form';
    }

    public function getData(): array
    {
        return ['email' => (string) Auth::user()?->getData('email'), 'website_id' => (int) ($_GET['website'] ?? 0)];
    }
}
