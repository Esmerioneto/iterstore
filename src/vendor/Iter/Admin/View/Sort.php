<?php

declare(strict_types=1);

namespace Iter\Admin\View;

/**
 * Sortable list columns, the same in every admin grid (the product list's pattern): ?sort= picks
 * one of the list's own keys (anything else falls back to the default), ?dir= is asc/desc; a title
 * links to its column, and clicked again flips the direction, back to page 1, keeping the filters.
 * Lists already loaded (Pager::slice) sort and search their rows here, in PHP.
 */
final class Sort
{
    /**
     * @param string[] $keys the list's sortable keys
     * @return array{0: string, 1: string} [sort, dir]
     */
    public static function read(array $keys, string $default, string $defaultDir = 'asc'): array
    {
        $sort = in_array($_GET['sort'] ?? '', $keys, true) ? (string) $_GET['sort'] : $default;
        $dir = in_array($_GET['dir'] ?? '', ['asc', 'desc'], true) ? (string) $_GET['dir'] : $defaultDir;

        return [$sort, $dir];
    }

    /**
     * The column title. $query: what the links carry (default: the request's filled parameters
     * except page, sort and dir); $path: the list's URL (default: the request's).
     */
    public static function th(string $key, string $label, string $sort, string $dir, ?array $query = null, ?string $path = null): string
    {
        $path ??= (string) strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?');
        $query ??= array_filter($_GET, static fn ($v) => is_string($v) && $v !== '');
        $active = $key === $sort;
        $next = $active && $dir === 'asc' ? 'desc' : 'asc';
        $href = $path . '?' . http_build_query(array_diff_key($query, ['sort' => 1, 'dir' => 1, 'page' => 1]) + ['sort' => $key, 'dir' => $next]);

        return '<th class="admin-sortable' . ($active ? ' is-sorted' : '') . '"' . ($active ? ' aria-sort="' . ($dir === 'asc' ? 'ascending' : 'descending') . '"' : '') . '>'
            . '<a href="' . htmlspecialchars($href) . '">' . htmlspecialchars($label) . ($active ? ($dir === 'asc' ? ' ▲' : ' ▼') : '') . '</a></th>';
    }

    /**
     * A list already loaded, the whole pattern at once: ?q= searched in $searchFields, sorted by a
     * whitelisted ?sort= ($columns: sort key => row field), then the page (Pager::slice).
     *
     * @param array<string, string> $columns
     * @param string[] $searchFields
     * @param int[] $sizes
     * @return array{0: array, 1: array, 2: string, 3: string, 4: string} [rows, pager, sort, dir, q]
     */
    public static function slice(array $rows, array $columns, string $default, string $defaultDir, array $searchFields, array $sizes = Pager::PAGE_SIZES): array
    {
        [$sort, $dir] = self::read(array_keys($columns), $default, $defaultDir);
        $q = trim((string) ($_GET['q'] ?? ''));
        $rows = self::search($rows, $searchFields, $q);
        // ponytail: without ?sort= the rows keep the order they were loaded in (each list's natural one, led by $default)
        [$rows, $pager] = Pager::slice(isset($_GET['sort']) ? self::rows($rows, $columns[$sort], $dir) : $rows, $sizes);

        return [$rows, $pager, $sort, $dir, $q];
    }

    /** Hidden inputs that keep the sort and the page size when a list's filter form is sent */
    public static function hidden(): string
    {
        $html = '';
        foreach (['sort', 'dir', 'limit'] as $name) {
            if (is_string($_GET[$name] ?? null) && $_GET[$name] !== '') {
                $html .= '<input type="hidden" name="' . $name . '" value="' . htmlspecialchars($_GET[$name]) . '">';
            }
        }

        return $html;
    }

    /** Loaded rows by one field: numbers as numbers, text case- and accent-insensitive; keys kept */
    public static function rows(array $rows, string $field, string $dir): array
    {
        $collator = class_exists(\Collator::class) ? new \Collator('pt_BR') : null;
        uasort($rows, static function ($a, $b) use ($field, $collator) {
            [$x, $y] = [$a[$field] ?? '', $b[$field] ?? ''];
            if (is_numeric($x) && is_numeric($y)) {
                return $x <=> $y;
            }

            return $collator ? $collator->compare((string) $x, (string) $y) : strnatcasecmp((string) $x, (string) $y);
        });

        return $dir === 'desc' ? array_reverse($rows, true) : $rows;
    }

    /** Loaded rows where any of $fields contains $q (case-insensitive); keys kept */
    public static function search(array $rows, array $fields, string $q): array
    {
        $q = mb_strtolower(trim($q));

        return $q === '' ? $rows : array_filter($rows, static function ($row) use ($fields, $q) {
            foreach ($fields as $field) {
                if (str_contains(mb_strtolower((string) ($row[$field] ?? '')), $q)) {
                    return true;
                }
            }

            return false;
        });
    }
}
