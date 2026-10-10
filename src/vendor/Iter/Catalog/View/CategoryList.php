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
        [$categories, $pager] = Pager::slice((new Category())->getResource()->getTree());

        return [
            'categories' => $categories,
            'pager' => $pager,
            'websites' => array_column((new Website())->getList(), 'name', 'website_id'),
        ];
    }
}
