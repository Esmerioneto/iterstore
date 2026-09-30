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

    /**
     * Parent products whose own row or an active variant's has a value matching %3$s in table %1$s, attribute %2$s.
     * ponytail: each filter matches on its own, so "GG" + "from R$ 50" may come from two variants; per-variant when a store asks
     */
    private const MATCHING_SQL = 'main_table.product_id IN (SELECT COALESCE(p.parent_id, p.product_id) FROM catalog_product p
        JOIN %s v ON v.entity_id = p.product_id AND v.attribute_id = %s WHERE (p.parent_id IS NULL OR p.is_active = 1) AND %s)';

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

    /**
     * The product or one of its variants (a configurable's colors and sizes live on them) has one
     * of $values. $attribute: an eav_attribute row of an option list or Sim/Não ("1"/"0").
     */
    public function addAttributeValueFilter(array $attribute, array $values): static
    {
        $in = implode(', ', array_map(fn ($v) => $this->select->bindValue((string) $v), $values));
        $match = match ($attribute['storage_type']) {
            'text' => "jsonb_exists_any(v.value::jsonb, ARRAY[{$in}]::text[])", // Múltipla seleção: a JSON list
            'boolean' => "v.value::text IN ({$in})", // Sim/Não is stored as 0/1
            default => "v.value IN ({$in})",
        };
        $this->select->where(sprintf(self::MATCHING_SQL, 'eav_value_' . $attribute['storage_type'], (int) $attribute['attribute_id'], $match));

        return $this;
    }

    /** the price (a configurable's: one of its variants') within the range; null = open end */
    public function addPriceFilter(?float $from, ?float $to): static
    {
        $range = implode(' AND ', array_filter([
            $from === null ? null : 'v.value >= ' . $this->select->bindValue($from),
            $to === null ? null : 'v.value <= ' . $this->select->bindValue($to),
        ]));
        if ($range !== '') {
            $this->select->where(sprintf(self::MATCHING_SQL, 'eav_value_decimal', "(SELECT attribute_id FROM eav_attribute JOIN eav_entity_type et USING (entity_type_id) WHERE et.code = 'product' AND eav_attribute.code = 'price')", $range));
        }

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
