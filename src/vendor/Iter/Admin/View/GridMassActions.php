<?php

declare(strict_types=1);

namespace Iter\Admin\View;

use Iter\Admin\Auth;

/**
 * Actions on the checked rows of an admin grid, from every enabled module's etc/grid_mass_actions.json:
 *
 *     [{"grid": "catalog/product-list", "code": "tryon_on", "label": "Provador virtual: ligar",
 *       "resource": "Iter_Tryon::manage", "class": "Vendor\\Module\\Grid\\Action", "value": "1"}]
 *
 * Only the ones the user's role allows are listed or run.
 */
final class GridMassActions
{
    /** @return array<string, array{code: string, label: string, resource: string, class: string, value: string}> code => declaration */
    public static function forGrid(string $grid, ?array $declared = null): array
    {
        $actions = [];
        foreach ($declared ?? \getModuleJson('etc' . DIRECTORY_SEPARATOR . 'grid_mass_actions.json') as $action) {
            if (($action['grid'] ?? null) !== $grid || !is_subclass_of((string) ($action['class'] ?? ''), GridMassAction::class)
                || (($action['resource'] ?? '') !== '' && !Auth::isAllowed($action['resource']))) {
                continue;
            }
            $actions[(string) $action['code']] = ['code' => (string) $action['code'], 'label' => (string) $action['label'], 'resource' => (string) ($action['resource'] ?? ''),
                'class' => (string) $action['class'], 'value' => (string) ($action['value'] ?? '')];
        }

        return $actions;
    }
}
