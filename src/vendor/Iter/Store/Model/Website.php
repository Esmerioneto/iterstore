<?php

declare(strict_types=1);

namespace Iter\Store\Model;

use Iter\Core\Model\AbstractModel;
use Iter\Store\Model\ResourceModel\Website as WebsiteResource;

/**
 * @method WebsiteResource getResource()
 */
class Website extends AbstractModel
{
    protected string $_idFieldName = 'website_id';

    protected function _construct(): void
    {
        $this->_resource = new WebsiteResource();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getList(): array
    {
        return $this->getResource()->getList();
    }
}
