<?php

declare(strict_types=1);

namespace Iter\Inventory\View;

use Iter\Core\View\ViewComponentInterface;
use Iter\Inventory\Model\Source;

class SourceForm implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'inventory/source-form';
    }

    public function getData(): array
    {
        $id = !empty($_GET['id']) ? (int) $_GET['id'] : null;
        $model = new Source();
        $source = $id && $model->load($id)->getId() ? $model->getData() : null;

        $old = $_SESSION['iter_old_input'] ?? null;
        unset($_SESSION['iter_old_input']);

        $values = array_merge(
            ['code' => '', 'name' => '', 'is_enabled' => 1, 'description' => '', 'postcode' => '', 'city' => '', 'region' => '', 'country_code' => 'BR', 'priority' => 0],
            $source ?? [],
            $old ?? []
        );
        if ($source) {
            $values['code'] = $source['code']; // immutable, whatever was posted
        }

        return [
            'is_edit' => $source !== null,
            'source_id' => $id,
            'is_default' => ($source['code'] ?? '') === Source::DEFAULT_CODE,
            'values' => $values,
        ];
    }
}
