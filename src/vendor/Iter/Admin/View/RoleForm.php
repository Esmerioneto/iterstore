<?php

declare(strict_types=1);

namespace Iter\Admin\View;

use Iter\Admin\AclRegistry;
use Iter\Admin\Model\AdminRole;
use Iter\Core\View\ViewComponentInterface;

class RoleForm implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'admin/role-form';
    }

    public function getData(): array
    {
        $id = !empty($_GET['id']) ? (int) $_GET['id'] : null;
        $role = null;
        $grantedFromDb = [];
        if ($id) {
            $model = new AdminRole();
            $model->load($id);
            if ($model->getId()) {
                $role = $model->getData();
                $grantedFromDb = $model->getGrantedResources();
            }
        }

        $old = $_SESSION['iter_old_input'] ?? null;
        unset($_SESSION['iter_old_input']);

        $granted = $old['resources'] ?? $grantedFromDb;

        return [
            'is_edit' => $role !== null,
            'role_id' => $id,
            'role_name' => $old['role_name'] ?? ($role['role_name'] ?? ''),
            'nodes' => AclRegistry::sortedByDepth(),
            'granted' => $granted,
        ];
    }
}
