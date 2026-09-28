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

    protected string $_idFieldName = 'product_id';

    protected function _construct(): void
    {
        $this->_resource = new ProductResource();
    }
}
