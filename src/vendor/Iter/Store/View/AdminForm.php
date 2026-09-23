<?php

declare(strict_types=1);

namespace Iter\Store\View;

use Iter\Core\View\ViewComponentInterface;
use Iter\Store\Model\Group;
use Iter\Store\Model\Store;

class AdminForm implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'store/admin-form';
    }

    public function getData(): array
    {
        $id = !empty($_GET['id']) ? (int) $_GET['id'] : null;
        $store = null;
        if ($id) {
            $model = (new Store())->load($id);
            $store = $model->getId() ? $model->getData() : null;
        }

        $old = $_SESSION['iter_old_input'] ?? null;
        unset($_SESSION['iter_old_input']);

        return [
            'is_edit' => $store !== null,
            'id' => $id,
            'values' => array_merge(
                ['store_name' => '', 'webview' => '', 'theme' => '', 'group_id' => null],
                $store ?? [],
                $old ?? []
            ),
            'groups' => (new Group())->getList(),
        ];
    }
}
