<?php

declare(strict_types=1);

namespace Iter\Catalog\Model;

use Iter\Core\Config;
use Iter\Core\Db\Connection;
use Iter\Eav\Model\AttributeException;
use Iter\Eav\Model\ValueService;
use Iter\Inventory\Model\StockService;

/**
 * The mobile app's home (MOBILE-PLAN): sections of a website, one per category, each with its first
 * products. When "Usar esta ordem no app" is on, the sections are the categories marked in Catálogo →
 * Mobile, in that order, each showing per_category products: the ones chosen by hand, then the newest
 * of the category. When off: every category in the catalog's order, the DEFAULT_PER_CATEGORY newest.
 * Only active categories (and parents), active products of the website linked straight to the
 * category (not through a subcategory); a category without such products has no section.
 */
class MobileService
{
    public const CONFIG_ENABLED = 'catalog/mobile/enabled';
    public const DEFAULT_PER_CATEGORY = 10;
    public const MAX_PER_CATEGORY = 50;

    public function isEnabled(int $websiteId): bool
    {
        return (bool) Config::get(self::CONFIG_ENABLED, $websiteId);
    }

    public function setEnabled(int $websiteId, bool $enabled): void
    {
        Config::save([self::CONFIG_ENABLED => $enabled ? '1' : '0'], $websiteId);
    }

    /**
     * Replaces the website's app order; its categories left out of $categories leave the app.
     *
     * @param array<int, array{id: int, visible?: bool, per_category?: int, product_ids?: int[], show_banner?: bool}> $categories in the app's order;
     *        product_ids: chosen by hand, in order (up to MAX_PER_CATEGORY: more than per_category is kept, shown after "mostrar mais");
     *        show_banner: its banners atop its section; omitted = as saved (a category without banners may keep it on)
     */
    public function save(int $websiteId, array $categories): void
    {
        $db = Connection::get()->adapter();
        $own = array_map('intval', $db->fetchCol($db->select()->from('catalog_category', ['category_id'])->where('website_id = ?', $websiteId)));
        $ids = array_map(static fn ($c) => (int) $c['id'], $categories);
        if (array_diff($ids, $own) || count($ids) !== count(array_unique($ids))) {
            throw new AttributeException('MOBILE_CATEGORY_INVALID', 'Categoria repetida ou de outro website.');
        }
        $linked = [];
        foreach ($ids ? $db->fetchAll($db->select()->from(['cp' => 'catalog_category_product'], ['category_id', 'product_id'])
            ->join(['p' => 'catalog_product'], 'p.product_id = cp.product_id AND p.parent_id IS NULL', [])->where('cp.category_id IN (?)', $ids)) : [] as $row) { // variants get their parent's categories too
            $linked[(int) $row['category_id']][(int) $row['product_id']] = true;
        }
        $showBanner = $own ? array_map('intval', $db->fetchPairs($db->select()->from('catalog_mobile_category', ['category_id', 'show_banner'])->where('category_id IN (?)', $own))) : [];
        $rows = $products = [];
        foreach (array_values($categories) as $position => $c) {
            $id = (int) $c['id'];
            $perCategory = (int) ($c['per_category'] ?? self::DEFAULT_PER_CATEGORY);
            if ($perCategory < 1 || $perCategory > self::MAX_PER_CATEGORY) {
                throw new AttributeException('MOBILE_PER_CATEGORY_INVALID', 'Exiba de 1 a ' . self::MAX_PER_CATEGORY . ' produtos por categoria.');
            }
            $productIds = array_map('intval', $c['product_ids'] ?? []);
            if (count($productIds) > self::MAX_PER_CATEGORY || count($productIds) !== count(array_unique($productIds))
                || array_diff_key(array_flip($productIds), $linked[$id] ?? [])) {
                throw new AttributeException('MOBILE_PRODUCT_INVALID', 'Escolha até ' . self::MAX_PER_CATEGORY . ' produtos, sem repetir, ligados à categoria (variações não).');
            }
            $rows[] = ['category_id' => $id, 'position' => $position, 'is_visible' => (int) ($c['visible'] ?? true), 'per_category' => $perCategory,
                'show_banner' => isset($c['show_banner']) ? (int) (bool) $c['show_banner'] : ($showBanner[$id] ?? 0)];
            foreach ($productIds as $i => $productId) {
                $products[] = ['category_id' => $id, 'product_id' => $productId, 'position' => $i];
            }
        }
        $db->transaction(static function ($db) use ($own, $rows, $products): void {
            $own && $db->delete('catalog_mobile_category', ['category_id IN (?)' => $own]); // their products go by FK CASCADE
            $db->insertMultiple('catalog_mobile_category', $rows);
            $db->insertMultiple('catalog_mobile_product', $products);
        });
    }

    /**
     * Catálogo → Mobile: every category of the website, the ones shown in the app first (in its order),
     * then the hidden ones, then those never saved (in the catalog's order). Each with its path
     * ("Moda › Bermudas"), whether the app can show it (it and its parents active), its settings, the
     * chosen products in order, and its parent products of the website, newest first: active ones and
     * the chosen. A product has its first image (a configurable's from its chosen variant) and, with
     * Iter_Inventory, its salable quantity in the website.
     * ponytail: every product of every category in one page; a search per category when catalogs grow
     *
     * Also "Mostrar banner" (show_banner) and the category's banner URLs, in order.
     *
     * @return array<int, array{category_id: int, path: string, active: bool, saved: bool, visible: bool, per_category: int, show_banner: bool, banners: string[], chosen: int[],
     *         products: array<int, array{product_id: int, sku: string, name: string, is_active: bool, image: ?string, salable: ?float}>}>
     */
    public function screen(int $websiteId): array
    {
        $db = Connection::get()->adapter();
        $categories = $names = $active = [];
        foreach ((new Category())->getResource()->getTree() as $row) { // parents come before their children
            $id = (int) $row['category_id'];
            $parent = $row['parent_id'] === null ? null : (int) $row['parent_id'];
            $names[$id] = ($parent === null ? '' : $names[$parent] . ' › ') . $row['name'];
            $active[$id] = $row['is_active'] && ($parent === null || $active[$parent]);
            if ((int) $row['website_id'] === $websiteId) {
                $categories[$id] = ['category_id' => $id, 'path' => $names[$id], 'active' => $active[$id], 'saved' => false, 'visible' => false,
                    'per_category' => self::DEFAULT_PER_CATEGORY, 'show_banner' => false, 'banners' => [], 'chosen' => [], 'products' => [], 'position' => PHP_INT_MAX];
            }
        }
        if (!$categories) {
            return [];
        }
        $ids = array_keys($categories);
        foreach ($db->fetchAll($db->select()->from('catalog_mobile_category')->where('category_id IN (?)', $ids)) as $row) {
            $categories[(int) $row['category_id']] = ['saved' => true, 'visible' => (bool) $row['is_visible'], 'per_category' => (int) $row['per_category'],
                'show_banner' => (bool) $row['show_banner'], 'position' => (int) $row['position']] + $categories[(int) $row['category_id']];
        }
        foreach ((new CategoryBannerService())->lists($ids) as $id => $banners) {
            $categories[$id]['banners'] = array_column($banners, 'url');
        }
        foreach ($db->fetchAll($db->select()->from('catalog_mobile_product', ['category_id', 'product_id'])->where('category_id IN (?)', $ids)->order(['category_id', 'position'])) as $row) {
            $categories[(int) $row['category_id']]['chosen'][] = (int) $row['product_id'];
        }
        $rows = $db->fetchAll($db->select()->from(['cp' => 'catalog_category_product'], ['category_id'])
            ->join(['p' => 'catalog_product'], 'p.product_id = cp.product_id AND p.parent_id IS NULL', ['product_id', 'sku', 'name', 'is_active', 'type'])
            ->join(['pw' => 'catalog_product_website'], 'pw.product_id = p.product_id', [])
            ->where('pw.website_id = ?', $websiteId)->where('cp.category_id IN (?)', $ids)
            ->where('(p.is_active = 1 OR p.product_id IN (SELECT mp.product_id FROM catalog_mobile_product mp WHERE mp.category_id = cp.category_id))')
            ->order('p.product_id DESC'));
        $productIds = array_values(array_unique(array_map('intval', array_column($rows, 'product_id'))));
        $variants = new VariantService();
        $values = (new ValueService())->loadMany(Product::ENTITY_TYPE, $productIds, $variants->imageCodes());
        $images = $variants->imageValues(array_map('intval', array_column(array_filter($rows, static fn ($r) => $r['type'] === 'configurable'), 'product_id')));
        // ponytail: Catalog doesn't depend on Inventory; without it, no "esgotado"
        $salable = \isModuleEnabled('iter_inventory') ? (new StockService())->getSalable($productIds, $websiteId) : [];
        foreach ($rows as $row) {
            $id = (int) $row['product_id'];
            $image = current(array_filter(array_map('strval', $images[$id] ?? $values[$id] ?? []), 'strlen')) ?: null;
            $categories[(int) $row['category_id']]['products'][$id] = ['product_id' => $id, 'sku' => $row['sku'], 'name' => $row['name'], 'is_active' => (bool) $row['is_active'],
                'image' => $image, 'salable' => isset($salable[$id]) ? (float) $salable[$id] : null];
        }
        foreach ($categories as &$category) { // a chosen product that left the category is dropped
            $category['chosen'] = array_values(array_filter($category['chosen'], static fn ($id) => isset($category['products'][$id])));
        }
        unset($category);
        $order = array_flip($ids); // the catalog's order
        uasort($categories, static fn ($a, $b) => [!$a['visible'], !$a['saved'], $a['position'], $order[$a['category_id']]]
            <=> [!$b['visible'], !$b['saved'], $b['position'], $order[$b['category_id']]]);

        return array_values(array_map(static function ($c) {
            unset($c['position']);

            return $c;
        }, $categories));
    }

    /**
     * banners: the category's banner URLs when the app order is on and its "Mostrar banner" too, else []
     *
     * @return array<int, array{category_id: int, product_ids: int[], total: int, banners: string[]}> in the app's order
     */
    public function sections(int $websiteId): array
    {
        $categories = $this->categories($websiteId);
        $found = $this->ordered($websiteId, array_map(static fn ($n) => [0, $n], $categories));
        $db = Connection::get()->adapter();
        $withBanner = $found && $this->isEnabled($websiteId)
            ? array_map('intval', $db->fetchCol($db->select()->from('catalog_mobile_category', ['category_id'])->where('show_banner = 1')->where('category_id IN (?)', array_keys($found))))
            : [];
        $banners = $withBanner ? (new CategoryBannerService())->lists($withBanner) : [];
        $sections = [];
        foreach (array_keys($categories) as $id) {
            isset($found[$id]) && $sections[] = ['category_id' => $id] + $found[$id] + ['banners' => array_column($banners[$id] ?? [], 'url')];
        }

        return $sections;
    }

    /**
     * "mostrar mais": the category's products after the section's first ones, in the same order.
     *
     * @return ?array{product_ids: int[], total: int} null when the category isn't in the app; total counts the section's first ones too
     */
    public function categoryProducts(int $categoryId, int $page, int $pageSize): ?array
    {
        $db = Connection::get()->adapter();
        $websiteId = (int) $db->fetchOne($db->select()->from('catalog_category', ['website_id'])->where('category_id = ?', $categoryId));
        $perCategory = $this->categories($websiteId)[$categoryId] ?? null;
        if ($perCategory === null) {
            return null;
        }
        $from = $perCategory + ($page - 1) * $pageSize;

        return $this->ordered($websiteId, [$categoryId => [$from, $from + $pageSize]])[$categoryId] ?? ['product_ids' => [], 'total' => 0];
    }

    /** @return array<int, int> category id => how many products it shows first, in the app's order */
    private function categories(int $websiteId): array
    {
        $active = [];
        foreach ((new Category())->getResource()->getTree() as $row) { // parents come before their children
            $parent = $row['parent_id'] === null ? null : (int) $row['parent_id'];
            if ((int) $row['website_id'] === $websiteId && $row['is_active'] && ($parent === null || isset($active[$parent]))) {
                $active[(int) $row['category_id']] = self::DEFAULT_PER_CATEGORY;
            }
        }
        if (!$this->isEnabled($websiteId)) {
            return $active;
        }
        $db = Connection::get()->adapter();
        $marked = array_map('intval', $db->fetchPairs($db->select()->from(['mc' => 'catalog_mobile_category'], ['category_id', 'per_category'])
            ->where('mc.is_visible = 1')->where('mc.category_id IN (?)', array_keys($active) ?: [0])->order(['mc.position', 'mc.category_id'])));

        return $marked;
    }

    /**
     * One query for every category: rows ($from, $to] of its order (by hand first when the app order is
     * on, then the newest), and its total.
     *
     * @param array<int, array{0: int, 1: int}> $windows category id => [from, to]
     * @return array<int, array{product_ids: int[], total: int}> only categories with products
     */
    private function ordered(int $websiteId, array $windows): array
    {
        if (!$windows) {
            return [];
        }
        $manual = $this->isEnabled($websiteId) ? 'TRUE' : 'FALSE';
        $limits = implode(', ', array_map(static fn ($id, $w) => sprintf('(%d, %d)', $id, $w[1]), array_keys($windows), $windows));
        // ponytail: raw SQL, as Select has no window functions or VALUES lists; every value is an int
        $rows = Connection::get()->adapter()->fetchAll(
            "SELECT x.category_id, x.product_id, x.n, x.total FROM (
                SELECT cp.category_id, cp.product_id,
                       ROW_NUMBER() OVER (PARTITION BY cp.category_id ORDER BY mp.position NULLS LAST, cp.product_id DESC) AS n,
                       COUNT(*) OVER (PARTITION BY cp.category_id) AS total
                FROM catalog_category_product cp
                JOIN catalog_product p ON p.product_id = cp.product_id AND p.parent_id IS NULL AND p.is_active = 1
                JOIN catalog_product_website pw ON pw.product_id = cp.product_id AND pw.website_id = " . $websiteId . "
                LEFT JOIN catalog_mobile_product mp ON {$manual} AND mp.category_id = cp.category_id AND mp.product_id = cp.product_id
                WHERE cp.category_id IN (" . implode(', ', array_map('intval', array_keys($windows))) . ")
            ) x JOIN (VALUES {$limits}) AS l (category_id, n_to) ON l.category_id = x.category_id
            WHERE x.n <= l.n_to ORDER BY x.category_id, x.n"
        );
        $result = [];
        foreach ($rows as $row) { // n = 1 always comes: it carries the total of a category whose window is past its end
            $id = (int) $row['category_id'];
            $result[$id] ??= ['product_ids' => [], 'total' => (int) $row['total']];
            (int) $row['n'] > $windows[$id][0] && $result[$id]['product_ids'][] = (int) $row['product_id'];
        }

        return $result;
    }
}
