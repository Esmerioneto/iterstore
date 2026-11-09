<?php

declare(strict_types=1);

namespace Iter\Admin\View;

/** An action on the rows checked in an admin grid, declared by any module (etc/grid_mass_actions.json, GridMassActions) */
interface GridMassAction
{
    /**
     * @param int[] $ids the checked rows
     * @param string $value the declaration's "value" (one class can serve several actions)
     * @return array{changed: int, same: int, message: string} message: what was done, for the flash ("Provador virtual ligado")
     */
    public function apply(array $ids, string $value): array;
}
