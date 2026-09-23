<?php

declare(strict_types=1);

namespace Iter\Admin\Model\ResourceModel;

use Iter\Core\Model\AbstractModel;
use Iter\Core\Model\ResourceModel\AbstractDb;
use PDO;

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
        $stmt = $this->getConnection()->prepare(
            'SELECT resource_id FROM admin_role_rule WHERE role_id = :id'
        );
        $stmt->execute(['id' => $roleId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
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

        $pdo = $this->getConnection();
        $pdo->prepare('DELETE FROM admin_role_rule WHERE role_id = :id')->execute(['id' => $object->getId()]);

        $stmt = $pdo->prepare(
            'INSERT INTO admin_role_rule (role_id, resource_id) VALUES (:role_id, :resource_id)'
        );
        foreach ($resources as $resourceId) {
            $stmt->execute(['role_id' => $object->getId(), 'resource_id' => $resourceId]);
        }
    }
}
