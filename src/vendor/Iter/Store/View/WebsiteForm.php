<?php

declare(strict_types=1);

namespace Iter\Store\View;

use Iter\Core\View\ViewComponentInterface;
use Iter\Store\Model\Website;

class WebsiteForm implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'store/website-form';
    }

    public function getData(): array
    {
        $id = !empty($_GET['id']) ? (int) $_GET['id'] : null;
        $website = null;
        if ($id) {
            $model = (new Website())->load($id);
            $website = $model->getId() ? $model->getData() : null;
        }

        $old = $_SESSION['iter_old_input'] ?? null;
        unset($_SESSION['iter_old_input']);

        return [
            'is_edit' => $website !== null,
            'website_id' => $id,
            'values' => array_merge(
                ['code' => '', 'name' => '', 'sort_order' => 0, 'is_default' => 0],
                $website ?? [],
                $old ?? []
            ),
        ];
    }
}
