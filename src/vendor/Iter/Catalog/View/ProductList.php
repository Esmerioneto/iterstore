<?php

declare(strict_types=1);

namespace Iter\Catalog\View;

use Iter\Catalog\Model\Category;
use Iter\Catalog\Model\Product;
use Iter\Core\Db\Expr;
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

    /** @param array $layoutData the layout node's "data": its extra columns may bring a "sort" */
    public function getData(array $layoutData = []): array
    {
        $filters = [];
        foreach (['website_id', 'attribute_set_id', 'category_id', 'is_active', 'q'] as $key) {
            $filters[$key] = trim((string) ($_GET[$key] ?? ''));
        }
        $limit = in_array((int) ($_GET['limit'] ?? 0), self::PAGE_SIZES, true) ? (int) $_GET['limit'] : self::PAGE_SIZES[0];
        $sortable = self::sortable($layoutData['columns'] ?? []);
        $sort = isset($sortable[$_GET['sort'] ?? '']) ? (string) $_GET['sort'] : 'name';
        $dir = ($_GET['dir'] ?? '') === 'desc' ? 'desc' : 'asc';
        $search = static fn (int $page) => (new Product())->getResource()->search($filters, $page, $limit, $sortable[$sort], $dir);
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $result = $search($page);
        $totalPages = max(1, (int) ceil($result['total'] / $limit));
        if ($page > $totalPages) { // "Ir para a página" past the end, or fewer pages after a bigger page size
            $page = $totalPages;
            $result = $search($page);
        }
        $categoryResource = (new Category())->getResource();
        $tree = $categoryResource->getTree();
        $productCategories = $categoryResource->getProductsCategoryIds(array_map('intval', array_column($result['items'], 'product_id')));

        return [
            'products' => $result['items'],
            'filters' => $filters,
            'page' => $page,
            'limit' => $limit,
            'sort' => $sort,
            'dir' => $dir,
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
     * What each sortable column orders by: the list's own columns, plus extra columns
     * that declare "sort": "Class::method" returning an SQL expression on main_table.product_id
     *
     * @return array<string, string|Expr> column key (?sort=) => field or expression
     */
    private static function sortable(array $columns): array
    {
        $sortable = [
            'sku' => 'sku',
            'name' => 'name',
            'set' => new Expr('s.label'),
            'categories' => new Expr('(SELECT string_agg(c.name, \', \' ORDER BY c.name) FROM catalog_category_product cp
                JOIN catalog_category c ON c.category_id = cp.category_id WHERE cp.product_id = main_table.product_id)'),
            // a configurable product sorts by its lowest variant price (the collection's price_sort column)
            'price' => new Expr('price_sort'),
            'is_active' => 'is_active',
        ];
        foreach ($columns as $key => $column) {
            if (isset($column['sort'])) {
                $sortable[$key] = new Expr('(' . ($column['sort'])() . ')');
            }
        }

        return $sortable;
    }

    /**
     * Extra columns other modules add through the layout ("data": {"columns": {name: {label, provider}}});
     * provider is a "Class::method" taking the page's product ids, returning id => text.
     *
     * @return array<int, array{key: string, label: string, sortable: bool, values: array<int, string>}>
     */
    public static function columns(array $columns, array $products): array
    {
        $ids = array_map('intval', array_column($products, 'product_id'));

        return array_values(array_map(static fn (string $key, array $c) => [
            'key' => $key, 'label' => (string) $c['label'], 'sortable' => isset($c['sort']), 'values' => $ids ? ($c['provider'])($ids) : [],
        ], array_keys($columns), $columns));
    }
}
