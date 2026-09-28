<?php

declare(strict_types=1);

namespace Iter\Eav\Model;

use Iter\Core\Model\AbstractModel;
use Iter\Eav\Model\ResourceModel\AttributeSet as AttributeSetResource;

/**
 * @method AttributeSetResource getResource()
 */
class AttributeSet extends AbstractModel
{
    protected string $_idFieldName = 'attribute_set_id';

    protected function _construct(): void
    {
        $this->_resource = new AttributeSetResource();
    }

    public function isDefault(): bool
    {
        return (int) $this->getData('is_default') === 1;
    }
}
