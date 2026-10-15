<?php

declare(strict_types=1);

namespace Iter\Inventory\View;

use Iter\Admin\View\Sort;
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
        $enabled = $_GET['is_enabled'] ?? '';
        $sources = (new Source())->getResource()->getListWithCounts();
        $sources = in_array($enabled, ['0', '1'], true) ? array_filter($sources, static fn ($s) => (string) (int) $s['is_enabled'] === $enabled) : $sources;
        [$sources, $pager, $sort, $dir, $q] = Sort::slice($sources, ['priority' => 'priority', 'website' => 'website_id', 'code' => 'code', 'name' => 'name', 'city' => 'city', 'is_enabled' => 'is_enabled', 'products' => 'products'], 'priority', 'asc', ['code', 'name', 'city', 'region']);

        return [
        'sort' => $sort,
            'dir' => $dir,
            'q' => $q,
            'is_enabled' => in_array($enabled, ['0', '1'], true) ? $enabled : '',
            'sources' => $sources,
            'pager' => $pager,
            'websites' => array_column((new Website())->getList(), 'name', 'website_id'),
        ];
    }
}
