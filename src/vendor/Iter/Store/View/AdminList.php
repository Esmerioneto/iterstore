<?php

declare(strict_types=1);

namespace Iter\Store\View;

use Iter\Store\Model\Store;
use Iter\Admin\View\Pager;
use Iter\Core\View\ViewComponentInterface;

class AdminList implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'store/admin-list';
    }

    public function getData(): array
    {
        [$stores, $pager] = Pager::slice((new Store())->getList());

        return [
            'stores' => $stores,
            'pager' => $pager,
        ];
    }
}
