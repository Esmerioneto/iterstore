<?php

declare(strict_types=1);

namespace Iter\Catalog\View;

use Iter\Catalog\Model\Category;
use Iter\Admin\View\Pager;
use Iter\Core\View\ViewComponentInterface;
use Iter\Store\Model\Website;

class CategoryList implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'catalog/category-list';
    }

    public function getData(): array
    {
        // a tree: searched (each match keeps its level), never sorted
        $q = trim((string) ($_GET['q'] ?? ''));
        [$categories, $pager] = Pager::slice(\Iter\Admin\View\Sort::search((new Category())->getResource()->getTree(), ['name', 'url_key'], $q));

        return [
            'q' => $q,
            'categories' => $categories,
            'pager' => $pager,
            'websites' => array_column((new Website())->getList(), 'name', 'website_id'),
        ];
    }
}
