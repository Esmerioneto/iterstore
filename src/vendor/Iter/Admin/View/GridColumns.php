<?php

declare(strict_types=1);

namespace Iter\Admin\View;

/**
 * Columns other modules add to an admin grid, without copying its template. Each module lists
 * them in etc/grid_columns.json (merged across enabled modules, like menu.json):
 *
 *     [{"grid": "admin/user-list", "code": "language", "label": "Idioma", "after": "role",
 *       "class": "Vendor\\Module\\Grid\\UserLanguage"}]
 *
 * "class" is a GridColumnSource, asked once for the whole page. The grid's template calls th()/td()
 * after each of its own columns; a column whose "after" isn't one of them goes after the last.
 */
final class GridColumns
{
    /** @var array<string, array<int, array{code: string, label: string, values: array}>> anchor => its columns */
    private array $byAnchor = [];

    private int $count = 0;

    /**
     * @param array<int, array<string, mixed>> $rows the page's rows
     * @param string[] $anchors the grid's own column keys, in order
     * @param ?array $declared the declarations; null = every enabled module's etc/grid_columns.json
     */
    public function __construct(string $grid, array $rows, array $anchors, ?array $declared = null)
    {
        foreach ($declared ?? \getModuleJson('etc' . DIRECTORY_SEPARATOR . 'grid_columns.json') as $column) {
            if (($column['grid'] ?? null) !== $grid) {
                continue;
            }
            $class = (string) ($column['class'] ?? '');
            if (!is_subclass_of($class, GridColumnSource::class)) {
                throw new \RuntimeException("Coluna '{$column['code']}' de {$grid}: '{$class}' não implementa GridColumnSource.");
            }
            $anchor = in_array($column['after'] ?? null, $anchors, true) ? $column['after'] : end($anchors);
            $this->byAnchor[$anchor][] = ['code' => (string) $column['code'], 'label' => (string) $column['label'], 'values' => (new $class())->values($rows)];
            $this->count++;
        }
    }

    /** the titles of the columns placed after $anchor */
    public function th(string $anchor): string
    {
        return implode('', array_map(
            static fn (array $c) => '<th class="grid-column-' . htmlspecialchars($c['code']) . '">' . htmlspecialchars($c['label']) . '</th>',
            $this->byAnchor[$anchor] ?? []
        ));
    }

    /** the cells of row $id for the columns placed after $anchor */
    public function td(string $anchor, int|string $id): string
    {
        return implode('', array_map(
            static fn (array $c) => '<td class="grid-column-' . htmlspecialchars($c['code']) . '">' . htmlspecialchars((string) ($c['values'][$id] ?? '')) . '</td>',
            $this->byAnchor[$anchor] ?? []
        ));
    }

    /** how many columns were added (for an empty row's colspan) */
    public function count(): int
    {
        return $this->count;
    }
}
