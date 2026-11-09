<?php

declare(strict_types=1);

namespace Iter\Admin\View;

/** The values of a column another module adds to an admin grid (etc/grid_columns.json, GridColumns) */
interface GridColumnSource
{
    /**
     * @param array<int, array<string, mixed>> $rows the rows of the grid's page
     * @return array<int|string, string|int|float|null> row id => value (plain text, escaped by the grid); a missing id shows empty
     */
    public function values(array $rows): array;
}
