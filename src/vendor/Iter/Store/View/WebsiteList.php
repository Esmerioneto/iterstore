<?php

declare(strict_types=1);

namespace Iter\Store\View;

use Iter\Admin\View\Pager;
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
        [$websites, $pager] = Pager::slice((new Website())->getList());

        return [
            'websites' => $websites,
            'pager' => $pager,
        ];
    }
}
