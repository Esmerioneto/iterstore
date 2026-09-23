<?php

declare(strict_types=1);

namespace Iter\Admin\Model;

use Iter\Admin\Model\ResourceModel\AdminRole as AdminRoleResource;
use Iter\Core\Model\AbstractModel;

/**
 * @method AdminRoleResource getResource()
 */
class AdminRole extends AbstractModel
{
    protected string $_idFieldName = 'role_id';

    protected function _construct(): void
    {
        $this->_resource = new AdminRoleResource();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getList(): array
    {
        return $this->getResource()->getList();
    }

    /**
     * @return array<int, string>
     */
    public function getGrantedResources(): array
    {
        return $this->getId() ? $this->getResource()->getGrantedResources((int) $this->getId()) : [];
    }

    /**
     * @param array<int, string> $resources
     */
    public function setResources(array $resources): static
    {
        return $this->setData('resources', $resources);
    }
}
