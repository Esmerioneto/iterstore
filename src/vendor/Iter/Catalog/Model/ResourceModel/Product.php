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
     * @param array{attribute_set_id?: string, is_active?: string, q?: string, category_id?: string, website_id?: string} $filters category_id includes its subcategories
     * @return array{items: array<int, array<string, mixed>>, total: int} rows + set_label, price
     */
    public function search(array $filters, int $page, int $perPage): array
    {
        $collection = (new Collection())->setOrder('name', 'ASC')->setOrder('product_id', 'ASC')->setPageSize($perPage)->setCurPage($page);
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

        return ['items' => $collection->getData(), 'total' => $collection->getSize()];
    }

    /** SKUs are unique ignoring case, so the lookup ignores it too */
    public function findIdBySku(string $sku): ?int
    {
        $db = $this->getConnection();
        $id = $db->fetchOne($db->select()->from('catalog_product', ['product_id'])->where('LOWER(sku) = LOWER(?)', trim($sku)));

        return $id === false ? null : (int) $id;
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
        $db = $this->getConnection();

        return array_map('intval', $db->fetchCol($db->select()->from('catalog_product_website', ['website_id'])
            ->where('product_id = ?', $productId)->order('website_id')));
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

    /** @return int[] the configurable product's variant attributes, in grid order */
    public function getVariantAttributeIds(int $productId): array
    {
        $db = $this->getConnection();

        return array_map('intval', $db->fetchCol($db->select()->from('catalog_product_variant_attribute', ['attribute_id'])
            ->where('product_id = ?', $productId)->order('position')));
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

    /** @return array<int, array<string, mixed>> the variants' catalog_product rows */
    public function getVariantRows(int $parentId): array
    {
        $db = $this->getConnection();

        return $db->fetchAll($db->select()->from('catalog_product')->where('parent_id = ?', $parentId)->order('product_id'));
    }
}
