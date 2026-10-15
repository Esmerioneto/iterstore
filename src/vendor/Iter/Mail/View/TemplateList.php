<?php

declare(strict_types=1);

namespace Iter\Mail\View;

use Iter\Admin\View\Pager;
use Iter\Admin\View\Sort;
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
        [$templates, $pager, $sort, $dir, $q] = Sort::slice(array_map(static fn (string $code) => Template::get($code), array_keys(Template::all())),
            ['label' => 'label', 'code' => 'code', 'subject' => 'subject', 'is_custom' => 'is_custom'], 'label', 'asc', ['label', 'code', 'subject']);

        return ['templates' => $templates, 'pager' => $pager, 'sort' => $sort, 'dir' => $dir, 'q' => $q];
    }
}
