<?php

declare(strict_types=1);

namespace Iter\Catalog\Model\ResourceModel\Product;

use Iter\Catalog\Model\Product;
use Iter\Catalog\Model\ResourceModel\Product as ProductResource;
use Iter\Core\Db\Expr;
use Iter\Eav\Model\ResourceModel\AbstractEntityCollection;

/**
 * @extends AbstractEntityCollection<Product> rows + set_label, price, a configurable product's price_min/max and
 * variant_count, and price_sort (the price, or a configurable's lowest variant price)
 */
class Collection extends AbstractEntityCollection
{
    private const VARIANTS_SQL = 'IN (SELECT product_id FROM catalog_product c WHERE c.parent_id = main_table.product_id)';

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

    protected function entityType(): string
    {
        return Product::ENTITY_TYPE;
    }

    protected function _initSelect(): void
    {
        $price = '(' . $this->valueSql('price') . ')';
        $minPrice = '(' . $this->valueSql('price', self::VARIANTS_SQL, 'MIN(v.value)') . ')';
        $this->select
            ->join(['s' => 'eav_attribute_set'], 's.attribute_set_id = main_table.attribute_set_id', [
                'set_label' => 'label',
                'price' => new Expr($price),
                'price_min' => new Expr($minPrice),
                'price_max' => new Expr('(' . $this->valueSql('price', self::VARIANTS_SQL, 'MAX(v.value)') . ')'),
                'variant_count' => new Expr('(SELECT COUNT(*) FROM catalog_product c WHERE c.parent_id = main_table.product_id)'),
                // the admin list's "Preço" column sorts by it (ORDER BY its name)
                'price_sort' => new Expr("COALESCE({$price}, {$minPrice})"),
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
        // ponytail: not addAttributeToFilter: the match is on the product or an active variant (MATCHING_SQL)
        $in = implode(', ', array_map(fn ($v) => $this->select->bindValue((string) $v), $values));
        $match = match ($attribute['storage_type']) {
            'text' => "jsonb_exists_any(v.value::jsonb, ARRAY[{$in}]::text[])", // Múltipla seleção: a JSON list
            'boolean' => "v.value::text IN ({$in})", // Sim/Não is stored as 0/1
            default => "v.value IN ({$in})",
        };
        $this->select->where(sprintf(self::MATCHING_SQL, 'eav_value_' . $attribute['storage_type'], (int) $attribute['attribute_id'], $match));

        return $this;
    }

    /** Product::finalPrice() in SQL, of the product whose id is $entity (a column) */
    public function finalPriceSql(string $entity): string
    {
        $value = fn (string $code): string => '(' . $this->valueSql($code, '= ' . $entity) . ')';
        $today = "'" . date('Y-m-d') . "'::date"; // ponytail: PHP's day (the store's timezone), not the database's

        return "CASE WHEN {$value('price_promo')} > 0 AND {$value('price_promo')} < {$value('price')}
            AND COALESCE({$value('price_promo_from')}::date <= {$today}, TRUE) AND COALESCE({$value('price_promo_to')}::date >= {$today}, TRUE)
            THEN {$value('price_promo')} ELSE {$value('price')} END";
    }

    /** the final price (a configurable's: one of its variants') within the range; null = open end */
    public function addPriceFilter(?float $from, ?float $to): static
    {
        $range = array_filter(['gteq' => $from, 'lteq' => $to], static fn ($v) => $v !== null);
        if ($range) {
            $this->select->where('main_table.product_id IN (SELECT COALESCE(p.parent_id, p.product_id) FROM catalog_product p
                WHERE (p.parent_id IS NULL OR p.is_active = 1) AND ' . $this->condition(new Expr('(' . $this->finalPriceSql('p.product_id') . ')'), $range) . ')');
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
