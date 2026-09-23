<?php

declare(strict_types=1);

namespace Iter\Store\Model;

use Iter\Core\Model\AbstractModel;
use Iter\Store\Model\ResourceModel\Store as StoreResource;

/**
 * @method StoreResource getResource()
 */
class Store extends AbstractModel
{
    protected string $_idFieldName = 'id';

    protected function _construct(): void
    {
        $this->_resource = new StoreResource();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getList(): array
    {
        return $this->getResource()->getList();
    }
}
