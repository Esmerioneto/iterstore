<?php

declare(strict_types=1);

namespace Iter\Catalog\Model;

use Iter\Catalog\Model\ResourceModel\Product as ProductResource;
use Iter\Core\Model\AbstractModel;

/**
 * @method ProductResource getResource()
 */
class Product extends AbstractModel
{
    /** entity type code of products in eav_entity_type */
    public const ENTITY_TYPE = 'product';

    /** the attribute values finalPrice() reads */
    public const PRICE_CODES = ['price', 'price_promo', 'price_promo_from', 'price_promo_to'];

    protected string $_idFieldName = 'product_id';

    /**
     * What the product sells for, from its attribute values: the "Preço promocional" (price_promo) when it is
     * above zero, below the price and today is within "Promoção de"/"Promoção até" (both days count; an empty
     * one leaves that side open), otherwise the price; null without a price. Collection::finalPriceSql() is the
     * same rule in SQL.
     */
    public static function finalPrice(array $values, ?string $today = null): ?float
    {
        if (!isset($values['price']) || $values['price'] === '') {
            return null;
        }
        $price = (float) $values['price'];
        $promo = isset($values['price_promo']) && $values['price_promo'] !== '' ? (float) $values['price_promo'] : 0.0;
        $today ??= date('Y-m-d');
        $from = substr((string) ($values['price_promo_from'] ?? ''), 0, 10);
        $to = substr((string) ($values['price_promo_to'] ?? ''), 0, 10);
        if (($from !== '' && $from > $today) || ($to !== '' && $to < $today)) {
            return $price;
        }

        return $promo > 0 && $promo < $price ? $promo : $price;
    }

    protected function _construct(): void
    {
        $this->_resource = new ProductResource();
    }
}
