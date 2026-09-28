<?php

declare(strict_types=1);

namespace Iter\Mail\View;

use Iter\Core\View\ViewComponentInterface;
use Iter\Mail\Model\Template;

class TemplateForm implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'mail/template-form';
    }

    public function getData(): array
    {
        $old = $_SESSION['iter_old_input'] ?? [];
        unset($_SESSION['iter_old_input']);

        return ['template' => array_merge(Template::get((string) ($_GET['code'] ?? '')), $old)];
    }
}
