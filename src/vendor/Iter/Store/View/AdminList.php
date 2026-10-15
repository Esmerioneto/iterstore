<?php

declare(strict_types=1);

namespace Iter\Store\View;

use Iter\Store\Model\Store;
use Iter\Admin\View\Pager;
use Iter\Admin\View\Sort;
use Iter\Core\View\ViewComponentInterface;

class AdminList implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'store/admin-list';
    }

    public function getData(): array
    {
        [$stores, $pager, $sort, $dir, $q] = Sort::slice((new Store())->getList(), ['id' => 'id', 'name' => 'store_name', 'website' => 'website_name', 'webview' => 'webview', 'theme' => 'theme'], 'id', 'asc', ['store_name', 'website_name', 'group_name', 'webview', 'theme']);

        return [
        'sort' => $sort,
            'dir' => $dir,
            'q' => $q,
            'stores' => $stores,
            'pager' => $pager,
        ];
    }
}
