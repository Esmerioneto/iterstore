<?php

declare(strict_types=1);

namespace Iter\Mail\View;

use Iter\Admin\View\Pager;
use Iter\Core\View\ViewComponentInterface;
use Iter\Mail\Model\Template;

class TemplateList implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'mail/template-list';
    }

    public function getData(): array
    {
        [$templates, $pager] = Pager::slice(array_map(static fn (string $code) => Template::get($code), array_keys(Template::all())));

        return ['templates' => $templates, 'pager' => $pager];
    }
}
