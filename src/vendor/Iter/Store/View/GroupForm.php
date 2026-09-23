<?php

declare(strict_types=1);

namespace Iter\Store\View;

use Iter\Core\View\ViewComponentInterface;
use Iter\Store\Model\Group;
use Iter\Store\Model\Website;

class GroupForm implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'store/group-form';
    }

    public function getData(): array
    {
        $id = !empty($_GET['id']) ? (int) $_GET['id'] : null;
        $group = null;
        if ($id) {
            $model = (new Group())->load($id);
            $group = $model->getId() ? $model->getData() : null;
        }

        $old = $_SESSION['iter_old_input'] ?? null;
        unset($_SESSION['iter_old_input']);

        return [
            'is_edit' => $group !== null,
            'group_id' => $id,
            'values' => array_merge(
                ['website_id' => null, 'code' => '', 'name' => ''],
                $group ?? [],
                $old ?? []
            ),
            'websites' => (new Website())->getList(),
        ];
    }
}
