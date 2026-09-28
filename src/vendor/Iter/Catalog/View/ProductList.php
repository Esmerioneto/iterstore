<?php

declare(strict_types=1);

namespace Iter\Catalog\View;

use Iter\Catalog\Model\Product;
use Iter\Core\View\ViewComponentInterface;

class ProductList implements ViewComponentInterface
{
    private const PER_PAGE = 20;

    public function getTemplate(): string
    {
        return 'catalog/product-list';
    }

    public function getData(): array
    {
        $filters = [];
        foreach (['attribute_set_id', 'is_active', 'q'] as $key) {
            $filters[$key] = trim((string) ($_GET[$key] ?? ''));
        }
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $result = (new Product())->getResource()->search($filters, $page, self::PER_PAGE);

        return [
            'products' => $result['items'],
            'filters' => $filters,
            'page' => $page,
            'total' => $result['total'],
            'total_pages' => max(1, (int) ceil($result['total'] / self::PER_PAGE)),
            'sets' => ProductForm::productSets(),
        ];
    }
}
