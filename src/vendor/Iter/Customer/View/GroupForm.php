<?php

declare(strict_types=1);

namespace Iter\Customer\View;

use Iter\Core\View\ViewComponentInterface;
use Iter\Customer\Model\Group;

class GroupForm implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'customer/group-form';
    }

    public function getData(): array
    {
        $id = !empty($_GET['id']) ? (int) $_GET['id'] : null;
        $model = new Group();
        $group = $id && $model->load($id)->getId() ? $model->getData() : null;

        $old = $_SESSION['iter_old_input'] ?? null;
        unset($_SESSION['iter_old_input']);

        return [
            'is_edit' => $group !== null,
            'customer_group_id' => $id,
            'values' => array_merge(['code' => '', 'name' => ''], $group ?? [], $old ?? []),
        ];
    }
}
