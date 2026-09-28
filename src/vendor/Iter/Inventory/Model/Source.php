<?php

declare(strict_types=1);

namespace Iter\Inventory\Model;

use Iter\Core\Model\AbstractModel;
use Iter\Inventory\Model\ResourceModel\Source as SourceResource;

/**
 * @method SourceResource getResource()
 */
class Source extends AbstractModel
{
    /** seeded by the migration; can't be removed or disabled */
    public const DEFAULT_CODE = 'default';

    protected string $_idFieldName = 'source_id';

    protected function _construct(): void
    {
        $this->_resource = new SourceResource();
    }
}
