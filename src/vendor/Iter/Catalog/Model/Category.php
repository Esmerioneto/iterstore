<?php

declare(strict_types=1);

namespace Iter\Catalog\Model;

use Iter\Catalog\Model\ResourceModel\Category as CategoryResource;
use Iter\Core\Model\AbstractModel;

/**
 * @method CategoryResource getResource()
 */
class Category extends AbstractModel
{
    /** entity type code of categories in eav_entity_type */
    public const ENTITY_TYPE = 'category';

    protected string $_idFieldName = 'category_id';

    protected function _construct(): void
    {
        $this->_resource = new CategoryResource();
    }
}
