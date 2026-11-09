<?php

declare(strict_types=1);

namespace Iter\Catalog\View;

use Iter\Catalog\Model\Category;
use Iter\Catalog\Model\CategoryBannerService;
use Iter\Catalog\Model\VariantService;
use Iter\Catalog\Model\WebHomeService;
use Iter\Core\Db\Connection;
use Iter\Eav\Model\ValueService;
use Iter\Core\View\ViewComponentInterface;
use Iter\Store\Model\Website;

/** Catálogo → Web */
final class WebHome implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'catalog/web-home';
    }

    public function getData(): array
    {
        $websites = array_column((new Website())->getList(), 'name', 'website_id');
        $websiteId = isset($websites[(int) ($_GET['website_id'] ?? 0)]) ? (int) $_GET['website_id'] : (int) (new Website())->getResource()->getDefaultId();
        // the website's categories with their path ("Vestuário › Bermuda") and parents (the cards' select keeps the section's subcategories)
        $categories = $names = $parents = [];
        foreach ((new Category())->getResource()->getTree() as $row) { // parents come before their children
            $id = (int) $row['category_id'];
            $parent = $row['parent_id'] === null ? null : (int) $row['parent_id'];
            $names[$id] = ($parent === null ? '' : ($names[$parent] ?? '') . ' › ') . $row['name'];
            $parents[$id] = $parent === null ? [] : [...($parents[$parent] ?? []), $parent];
            if ((int) $row['website_id'] === $websiteId) {
                $categories[$id] = ['path' => $names[$id], 'parents' => $parents[$id], 'active' => (bool) $row['is_active']];
            }
        }

        $stored = (new WebHomeService())->get($websiteId);

        return ['websites' => $websites, 'website_id' => $websiteId, 'categories' => $categories, 'products' => $this->products($websiteId, array_keys($categories), $stored['sections']),
            'banners' => array_map(static fn (array $list) => count($list), (new CategoryBannerService())->lists(array_keys($categories) ?: [0]))] + $stored;
    }

    /**
     * The section's product chooser: each category's parent products in the website, newest first, as
     * [id, sku, name, active, image URL or ''] (active ones and those chosen in a section)
     * ponytail: every product of every category in one page, as in Catálogo → Mobile; a search when catalogs grow
     *
     * @return array<int, list<array{0: int, 1: string, 2: string, 3: bool, 4: string}>>
     */
    private function products(int $websiteId, array $categoryIds, array $sections): array
    {
        if (!$categoryIds) {
            return [];
        }
        $db = Connection::get()->adapter();
        $chosen = array_merge([0], ...array_column($sections, 'product_ids'));
        $rows = $db->fetchAll($db->select()->from(['cp' => 'catalog_category_product'], ['category_id'])
            ->join(['p' => 'catalog_product'], 'p.product_id = cp.product_id AND p.parent_id IS NULL', ['product_id', 'sku', 'name', 'is_active', 'type'])
            ->join(['pw' => 'catalog_product_website'], 'pw.product_id = p.product_id', [])
            ->where('pw.website_id = ?', $websiteId)->where('cp.category_id IN (?)', $categoryIds)
            ->where('(p.is_active = 1 OR p.product_id IN (?))', $chosen)
            ->order('p.product_id DESC'));
        $images = (new VariantService())->mainImages($rows);
        $products = [];
        foreach ($rows as $row) {
            $id = (int) $row['product_id'];
            $products[(int) $row['category_id']][] = [$id, (string) $row['sku'], (string) $row['name'], (bool) $row['is_active'], $images[$id] === null ? '' : ValueService::MEDIA_URL . $images[$id]];
        }

        return $products;
    }
}
