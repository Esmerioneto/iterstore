<?php

declare(strict_types=1);

namespace Iter\Admin\View;

use Iter\Admin\Model\AdminRole;
use Iter\Admin\Model\AdminUser;
use Iter\Core\View\ViewComponentInterface;

class UserForm implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'admin/user-form';
    }

    public function getData(): array
    {
        $id = !empty($_GET['id']) ? (int) $_GET['id'] : null;
        $user = null;
        if ($id) {
            $model = new AdminUser();
            $model->load($id);
            $user = $model->getId() ? $model->getData() : null;
        }

        // ponytail: old input from a failed submit takes priority over stored
        // data, so the user doesn't lose what they just typed.
        $old = $_SESSION['iter_old_input'] ?? null;
        unset($_SESSION['iter_old_input']);

        $values = array_merge(
            [
                'firstname' => '',
                'lastname' => '',
                'email' => '',
                'username' => '',
                'interface_locale' => 'pt_BR',
                'is_active' => 1,
                'role_id' => null,
            ],
            $user ?? [],
            $old ?? []
        );

        return [
            'is_edit' => $user !== null,
            'user_id' => $id,
            'values' => $values,
            'roles' => (new AdminRole())->getList(),
        ];
    }
}
