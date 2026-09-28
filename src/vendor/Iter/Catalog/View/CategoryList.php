<?php

declare(strict_types=1);

namespace Iter\Catalog\View;

use Iter\Catalog\Model\Category;
use Iter\Core\View\ViewComponentInterface;

class CategoryList implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'catalog/category-list';
    }

    public function getData(): array
    {
        return [
            'categories' => (new Category())->getResource()->getTree(),
        ];
    }
}
