<?php

declare(strict_types=1);

namespace Iter\Inventory\View;

use Iter\Admin\View\Pager;
use Iter\Core\View\ViewComponentInterface;
use Iter\Store\Model\Website;
use Iter\Inventory\Model\Source;

class SourceList implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'inventory/source-list';
    }

    public function getData(): array
    {
        [$sources, $pager] = Pager::slice((new Source())->getResource()->getListWithCounts());

        return [
            'sources' => $sources,
            'pager' => $pager,
            'websites' => array_column((new Website())->getList(), 'name', 'website_id'),
        ];
    }
}
