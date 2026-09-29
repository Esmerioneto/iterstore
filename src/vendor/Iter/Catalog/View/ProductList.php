<?php

declare(strict_types=1);

namespace Iter\Catalog\View;

use Iter\Catalog\Model\Category;
use Iter\Catalog\Model\Product;
use Iter\Core\View\ViewComponentInterface;
use Iter\Store\Model\Website;

class ProductList implements ViewComponentInterface
{
    /** "Mostrar linhas" choices; the first one is the default */
    public const PAGE_SIZES = [8, 5, 10, 20];

    public function getTemplate(): string
    {
        return 'catalog/product-list';
    }

    public function getData(): array
    {
        $filters = [];
        foreach (['website_id', 'attribute_set_id', 'category_id', 'is_active', 'q'] as $key) {
            $filters[$key] = trim((string) ($_GET[$key] ?? ''));
        }
        $limit = in_array((int) ($_GET['limit'] ?? 0), self::PAGE_SIZES, true) ? (int) $_GET['limit'] : self::PAGE_SIZES[0];
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $result = (new Product())->getResource()->search($filters, $page, $limit);
        $totalPages = max(1, (int) ceil($result['total'] / $limit));
        if ($page > $totalPages) { // "Ir para a página" past the end, or fewer pages after a bigger page size
            $page = $totalPages;
            $result = (new Product())->getResource()->search($filters, $page, $limit);
        }
        $categoryResource = (new Category())->getResource();
        $tree = $categoryResource->getTree();
        $productCategories = $categoryResource->getProductsCategoryIds(array_map('intval', array_column($result['items'], 'product_id')));

        return [
            'products' => $result['items'],
            'filters' => $filters,
            'page' => $page,
            'limit' => $limit,
            'total' => $result['total'],
            'total_pages' => $totalPages,
            'sets' => ProductForm::productSets(),
            'websites' => array_column((new Website())->getList(), 'name', 'website_id'),
            'categories' => $tree,
            // product id => its category names, in tree order
            'product_categories' => array_map(
                static fn (array $ids) => array_column(array_filter($tree, static fn ($c) => in_array((int) $c['category_id'], $ids, true)), 'name'),
                $productCategories
            ),
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
