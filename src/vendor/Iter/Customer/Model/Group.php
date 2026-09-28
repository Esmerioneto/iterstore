<?php

declare(strict_types=1);

namespace Iter\Customer\Model;

use Iter\Core\Model\AbstractModel;
use Iter\Customer\Model\ResourceModel\Group as GroupResource;

/**
 * @method GroupResource getResource()
 */
class Group extends AbstractModel
{
    protected string $_idFieldName = 'customer_group_id';

    protected function _construct(): void
    {
        $this->_resource = new GroupResource();
    }
}
