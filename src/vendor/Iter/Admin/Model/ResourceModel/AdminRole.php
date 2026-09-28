<?php

declare(strict_types=1);

namespace Iter\Admin\Model\ResourceModel;

use Iter\Core\Model\AbstractModel;
use Iter\Core\Model\ResourceModel\AbstractDb;

class AdminRole extends AbstractDb
{
    protected function _construct(): void
    {
        $this->init('admin_role', 'role_id');
    }

    /**
     * @return array<int, string>
     */
    public function getGrantedResources(int $roleId): array
    {
        $db = $this->getConnection();

        return $db->fetchCol($db->select()->from('admin_role_rule', ['resource_id'])->where('role_id = ?', $roleId));
    }

    /**
     * Persists the "resources" virtual field (not a real admin_role column,
     * so the generic save() ignores it) into the admin_role_rule child table.
     */
    protected function afterSave(AbstractModel $object): void
    {
        $resources = $object->getData('resources');
        if ($resources === null) {
            return;
        }

        // save() already runs this in a transaction
        $db = $this->getConnection();
        $db->delete('admin_role_rule', ['role_id = ?' => $object->getId()]);
        $db->insertMultiple('admin_role_rule', array_map(
            static fn ($resourceId) => ['role_id' => $object->getId(), 'resource_id' => $resourceId],
            array_values($resources)
        ));
    }
}
