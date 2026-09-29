<?php

declare(strict_types=1);

namespace Iter\Catalog\Model\ResourceModel\Product;

use Iter\Catalog\Model\Product;
use Iter\Catalog\Model\ResourceModel\Product as ProductResource;
use Iter\Core\Db\Expr;
use Iter\Core\Model\ResourceModel\AbstractCollection;

/** @extends AbstractCollection<Product> rows + set_label, price, and a configurable product's price_min/max and variant_count */
class Collection extends AbstractCollection
{
    // price is the system EAV attribute created along with the table; a configurable product's comes from its variants
    public const PRICE_SQL = "(SELECT %s FROM eav_value_decimal v JOIN eav_attribute pa ON pa.attribute_id = v.attribute_id
        WHERE pa.entity_type_id = s.entity_type_id AND pa.code = 'price' AND v.entity_id %s)";
    public const VARIANTS_SQL = 'IN (SELECT product_id FROM catalog_product c WHERE c.parent_id = main_table.product_id)';

    protected function _construct(): void
    {
        $this->init(Product::class, ProductResource::class);
    }

    protected function _initSelect(): void
    {
        $this->select
            ->join(['s' => 'eav_attribute_set'], 's.attribute_set_id = main_table.attribute_set_id', [
                'set_label' => 'label',
                'price' => new Expr(sprintf(self::PRICE_SQL, 'v.value', '= main_table.product_id')),
                'price_min' => new Expr(sprintf(self::PRICE_SQL, 'MIN(v.value)', self::VARIANTS_SQL)),
                'price_max' => new Expr(sprintf(self::PRICE_SQL, 'MAX(v.value)', self::VARIANTS_SQL)),
                'variant_count' => new Expr('(SELECT COUNT(*) FROM catalog_product c WHERE c.parent_id = main_table.product_id)'),
            ]);
    }

    public function addWebsiteFilter(int $websiteId): static
    {
        $this->select->where('main_table.product_id IN (SELECT product_id FROM catalog_product_website WHERE website_id = ?)', $websiteId);

        return $this;
    }

    /** products of the category and of its subcategories */
    public function addCategoryFilter(int $categoryId): static
    {
        // ponytail: raw SQL, as Select has no WITH RECURSIVE (DB-PLAN.md §3.2)
        $this->select->where('main_table.product_id IN (
            WITH RECURSIVE sub AS (
                SELECT category_id FROM catalog_category WHERE category_id = ?
                UNION ALL
                SELECT c.category_id FROM catalog_category c JOIN sub ON c.parent_id = sub.category_id
            ) SELECT cp.product_id FROM catalog_category_product cp JOIN sub ON sub.category_id = cp.category_id)', $categoryId);

        return $this;
    }
}
