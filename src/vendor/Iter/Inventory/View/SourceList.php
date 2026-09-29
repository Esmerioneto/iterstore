<?php

declare(strict_types=1);

namespace Iter\Inventory\View;

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
        return [
            'sources' => (new Source())->getResource()->getListWithCounts(),
            'websites' => array_column((new Website())->getList(), 'name', 'website_id'),
        ];
    }
}
