<?php

declare(strict_types=1);

namespace Iter\Eav\Model;

use Iter\Core\Model\AbstractModel;
use Iter\Eav\Model\ResourceModel\Attribute as AttributeResource;

/**
 * @method AttributeResource getResource()
 */
class Attribute extends AbstractModel
{
    protected string $_idFieldName = 'attribute_id';

    protected function _construct(): void
    {
        $this->_resource = new AttributeResource();
    }

    public function isSystem(): bool
    {
        return (int) $this->getData('is_system') === 1;
    }
}
