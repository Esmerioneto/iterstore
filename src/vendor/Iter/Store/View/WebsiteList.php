<?php

declare(strict_types=1);

namespace Iter\Store\View;

use Iter\Admin\View\Sort;
use Iter\Core\View\ViewComponentInterface;
use Iter\Store\Model\Website;

class WebsiteList implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'store/website-list';
    }

    public function getData(): array
    {
        [$websites, $pager, $sort, $dir, $q] = Sort::slice((new Website())->getList(), ['sort_order' => 'sort_order', 'id' => 'website_id', 'code' => 'code', 'name' => 'name'], 'sort_order', 'asc', ['code', 'name']);

        return [
        'sort' => $sort,
            'dir' => $dir,
            'q' => $q,
            'websites' => $websites,
            'pager' => $pager,
        ];
    }
}
