<?php

declare(strict_types=1);

namespace Iter\Store\Model;

use Iter\Core\Model\AbstractModel;
use Iter\Store\Model\ResourceModel\Group as GroupResource;

/**
 * @method GroupResource getResource()
 */
class Group extends AbstractModel
{
    protected string $_idFieldName = 'group_id';

    protected function _construct(): void
    {
        $this->_resource = new GroupResource();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getList(): array
    {
        return $this->getResource()->getList();
    }
}
