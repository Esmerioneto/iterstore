<?php

declare(strict_types=1);

namespace Iter\Catalog\View;

use Iter\Catalog\Model\Category;
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
        foreach (['attribute_set_id', 'category_id', 'is_active', 'q'] as $key) {
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
            'categories' => (new Category())->getResource()->getTree(),
        ];
    }

    /**
     * Extra columns other modules add through the layout ("data": {"columns": {name: {label, provider}}});
     * provider is a "Class::method" taking the page's product ids, returning id => text.
     *
     * @return array<int, array{label: string, values: array<int, string>}>
     */
    public static function columns(array $columns, array $products): array
    {
        $ids = array_map('intval', array_column($products, 'product_id'));

        return array_values(array_map(static fn (array $c) => ['label' => (string) $c['label'], 'values' => $ids ? ($c['provider'])($ids) : []], $columns));
    }
}
