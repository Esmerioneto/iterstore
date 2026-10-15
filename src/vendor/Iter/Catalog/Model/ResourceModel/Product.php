<?php

declare(strict_types=1);

namespace Iter\Catalog\Model\ResourceModel;

use Iter\Core\Db\Expr;
use Iter\Core\Model\ResourceModel\AbstractDb;
use Iter\Catalog\Model\ResourceModel\Product\Collection;

class Product extends AbstractDb
{
    protected function _construct(): void
    {
        $this->init('catalog_product', 'product_id');
    }

    /**
     * @param array{attribute_set_id?: string, is_active?: string, q?: string, category_id?: string, website_id?: string,
     *              attributes?: array<string, array{attribute: array, values: string[]}>, price_from?: ?float, price_to?: ?float} $filters
     *        category_id includes its subcategories; attributes (by code) match the product or one of its variants
     * @return array{items: array<int, array<string, mixed>>, total: int} rows + set_label, price
     */
    public function search(array $filters, int $page, int $perPage, string|Expr $orderBy = 'name', string $direction = 'ASC'): array
    {
        $collection = $this->filtered($filters)->setOrder($orderBy, $direction)->setOrder('product_id', $direction)->setPageSize($perPage)->setCurPage($page);

        return ['items' => $collection->getData(), 'total' => $collection->getSize()];
    }

    /**
     * Storefront filters: for each attribute in $filterable, how many products of the search have each
     * value. An attribute's own filter is left out of its counts, so the other options stay offered.
     *
     * @param array<string, array> $filterable eav_attribute rows by code
     * @return array{attributes: array<string, array<string, int>>, price: array{min: ?float, max: ?float}} value => count
     */
    public function aggregations(array $filters, array $filterable): array
    {
        $db = $this->getConnection();
        $counts = [];
        foreach ($filterable as $code => $attribute) {
            $ids = $this->idsSql($this->filtered(['attributes' => array_diff_key($filters['attributes'] ?? [], [$code => 0])] + $filters));
            $value = match ($attribute['storage_type']) {
                'text' => 'jsonb_array_elements_text(v.value::jsonb)',
                'boolean' => 'v.value::text', // 0/1
                default => 'v.value',
            };
            $counts[$code] = array_map('intval', $db->fetchPairs(
                "SELECT {$value} AS value, COUNT(DISTINCT COALESCE(p.parent_id, p.product_id)) FROM catalog_product p
                 JOIN eav_value_{$attribute['storage_type']} v ON v.entity_id = p.product_id AND v.attribute_id = " . (int) $attribute['attribute_id'] . "
                 WHERE (p.parent_id IS NULL OR p.is_active = 1) AND COALESCE(p.parent_id, p.product_id) IN ({$ids[0]}) GROUP BY 1",
                $ids[1]
            ));
        }
        $collection = $this->filtered(['price_from' => null, 'price_to' => null] + $filters);
        $ids = $this->idsSql($collection);
        $price = $db->fetchRow(
            "SELECT MIN(v.price) AS min, MAX(v.price) AS max FROM (SELECT ({$collection->finalPriceSql('p.product_id')}) AS price FROM catalog_product p
             WHERE (p.parent_id IS NULL OR p.is_active = 1) AND COALESCE(p.parent_id, p.product_id) IN ({$ids[0]})) v",
            $ids[1]
        ) ?: [];

        return ['attributes' => $counts, 'price' => ['min' => isset($price['min']) ? (float) $price['min'] : null, 'max' => isset($price['max']) ? (float) $price['max'] : null]];
    }

    private function filtered(array $filters): Collection
    {
        $collection = new Collection();
        $collection->addFieldToFilter('parent_id', ['null' => true]); // variants show under their parent
        if (($filters['attribute_set_id'] ?? '') !== '') {
            $collection->addFieldToFilter('attribute_set_id', (int) $filters['attribute_set_id']);
        }
        if (in_array($filters['is_active'] ?? '', ['0', '1'], true)) {
            $collection->addFieldToFilter('is_active', (int) $filters['is_active']);
        }
        if (($filters['website_id'] ?? '') !== '') {
            $collection->addWebsiteFilter((int) $filters['website_id']);
        }
        if (($filters['category_id'] ?? '') !== '') {
            $collection->addCategoryFilter((int) $filters['category_id']);
        }
        if (($filters['q'] ?? '') !== '') { // a variant's SKU finds its parent
            $q = ['ilike' => '%' . addcslashes($filters['q'], '%_\\') . '%'];
            $variantSkus = new Expr("(SELECT string_agg(sku, ' ') FROM catalog_product c WHERE c.parent_id = main_table.product_id)");
            $collection->addFieldToFilter(['sku', 'name', $variantSkus], [$q, $q, $q]);
        }
        foreach ($filters['attributes'] ?? [] as $filter) {
            $collection->addAttributeValueFilter($filter['attribute'], $filter['values']);
        }
        $collection->addPriceFilter($filters['price_from'] ?? null, $filters['price_to'] ?? null);

        return $collection;
    }

    /** @return array{0: string, 1: array} the collection's product ids as a subquery, and its bind */
    private function idsSql(Collection $collection): array
    {
        $select = $collection->getSelect();

        return ["SELECT product_id FROM ({$select}) AS ids", $select->getBind()];
    }

    /** SKUs are unique ignoring case, so the lookup ignores it too */
    public function findIdBySku(string $sku): ?int
    {
        $db = $this->getConnection();
        $id = $db->fetchOne($db->select()->from('catalog_product', ['product_id'])->where('LOWER(sku) = LOWER(?)', trim($sku)));

        return $id === false ? null : (int) $id;
    }

    /** url_key is unique among products; with $websiteId, only products of that website count */
    public function urlKeyExists(string $urlKey, ?int $exceptId = null, ?int $websiteId = null): bool
    {
        $db = $this->getConnection();
        $select = $db->select()->from('catalog_product', [new Expr('1')])->where('url_key = ?', $urlKey)->where('product_id <> ?', $exceptId ?? 0);
        if ($websiteId !== null) {
            $select->where('product_id IN (SELECT product_id FROM catalog_product_website WHERE website_id = ?)', $websiteId);
        }

        return (bool) $db->fetchOne($select);
    }

    public function skuExists(string $sku, ?int $exceptId = null): bool
    {
        $db = $this->getConnection();

        return (bool) $db->fetchOne($db->select()->from('catalog_product', [new Expr('1')])
            ->where('LOWER(sku) = LOWER(?)', $sku)
            ->where('product_id <> ?', $exceptId ?? 0));
    }

    /** @return int[] websites of the product */
    public function getWebsiteIds(int $productId): array
    {
        return $this->getWebsiteIdsMany([$productId])[$productId] ?? [];
    }

    /** @return array<int, int[]> product id => its websites, for a list's page in one query */
    public function getWebsiteIdsMany(array $productIds): array
    {
        $db = $this->getConnection();
        $ids = [];
        foreach ($productIds ? $db->fetchAll($db->select()->from('catalog_product_website', ['product_id', 'website_id'])
            ->where('product_id IN (?)', array_map('intval', $productIds))->order(['product_id', 'website_id'])) : [] as $row) {
            $ids[(int) $row['product_id']][] = (int) $row['website_id'];
        }

        return $ids;
    }

    /** @param int[] $websiteIds replaces the product's websites */
    public function setWebsites(int $productId, array $websiteIds): void
    {
        $this->getConnection()->transaction(static function ($db) use ($productId, $websiteIds): void {
            $db->delete('catalog_product_website', ['product_id = ?' => $productId]);
            $db->insertMultiple('catalog_product_website', array_map(
                static fn ($websiteId) => ['product_id' => $productId, 'website_id' => $websiteId],
                array_values(array_unique($websiteIds))
            ));
        });
    }

    /** @return array<int, int[]> product id => its grid's attribute ids in order, for many products in one query */
    public function getVariantAttributeIdsMany(array $productIds): array
    {
        $db = $this->getConnection();
        $ids = [];
        foreach ($productIds ? $db->fetchAll($db->select()->from('catalog_product_variant_attribute', ['product_id', 'attribute_id'])
            ->where('product_id IN (?)', array_map('intval', $productIds))->order(['product_id', 'position'])) : [] as $row) {
            $ids[(int) $row['product_id']][] = (int) $row['attribute_id'];
        }

        return $ids;
    }

    /** @param int[] $attributeIds in grid order */
    public function setVariantAttributes(int $productId, array $attributeIds): void
    {
        $this->getConnection()->insertMultiple('catalog_product_variant_attribute', array_map(
            static fn ($id, $position) => ['product_id' => $productId, 'attribute_id' => $id, 'position' => $position],
            $attributeIds,
            array_keys($attributeIds)
        ));
    }

    /** @param array<string, string> $options code => option value; locks those options (FK RESTRICT) */
    public function setVariantOptions(int $productId, array $options): void
    {
        $db = $this->getConnection();
        foreach ($options as $code => $value) {
            $db->insertOnConflict('catalog_product_variant_option', [
                'product_id' => $productId,
                'option_id' => (int) $db->fetchOne($db->select()->from(['o' => 'eav_attribute_option'], ['option_id'])
                    ->join(['va' => 'catalog_product_variant_attribute'], 'va.attribute_id = o.attribute_id', [])
                    ->join(['a' => 'eav_attribute'], 'a.attribute_id = o.attribute_id', [])
                    ->join(['p' => 'catalog_product'], 'p.parent_id = va.product_id', [])
                    ->where('p.product_id = ?', $productId)->where('a.code = ?', $code)->where('o.value = ?', $value)),
            ], ['product_id', 'option_id'], ['option_id']);
        }
    }

    /** @return array<int, array<string, mixed>> the variants' catalog_product rows */
    public function getVariantRows(int $parentId): array
    {
        return $this->getVariantRowsMany([$parentId])[$parentId] ?? [];
    }

    /** @return array<int, array<int, array>> parent id => its variants' rows, for a list's page in one query */
    public function getVariantRowsMany(array $parentIds): array
    {
        $db = $this->getConnection();
        $rows = [];
        foreach ($parentIds ? $db->fetchAll($db->select()->from('catalog_product')->where('parent_id IN (?)', array_map('intval', $parentIds))->order('product_id')) : [] as $row) {
            $rows[(int) $row['parent_id']][] = $row;
        }

        return $rows;
    }
}
