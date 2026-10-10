<?php

declare(strict_types=1);

namespace Iter\Admin\View;

use Iter\Core\View\ComponentResolver;

/**
 * The pager of every admin list (template admin/pager): "Ir para a página",
 * "Mostrar linhas", the range and the arrows. Page and size come from the request
 * (?page=, ?limit=); a page past the end (or after a bigger size) shows the last one.
 *
 * @phpstan-type State array{page: int, limit: int, total: int, total_pages: int, sizes: int[]}
 */
final class Pager
{
    /** "Mostrar linhas" choices; the first one is the default */
    public const PAGE_SIZES = [20, 50, 100];

    /**
     * Runs a list's search for the asked page and size.
     *
     * @param callable(int $page, int $limit): array{items: array, total: int} $search
     * @param int[] $sizes
     * @return array{0: array{items: array, total: int}, 1: State} the search's result and the pager
     */
    public static function search(callable $search, array $sizes = self::PAGE_SIZES): array
    {
        $limit = in_array((int) ($_GET['limit'] ?? 0), $sizes, true) ? (int) $_GET['limit'] : $sizes[0];
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $result = $search($page, $limit);
        $totalPages = max(1, (int) ceil($result['total'] / $limit));
        if ($page > $totalPages) {
            $page = $totalPages;
            $result = $search($page, $limit);
        }

        return [$result, ['page' => $page, 'limit' => $limit, 'total' => (int) $result['total'], 'total_pages' => $totalPages, 'sizes' => $sizes]];
    }

    /**
     * The same over rows already loaded (short lists: users, websites, groups...); keys are kept.
     *
     * @param int[] $sizes
     * @return array{0: array, 1: State} the page's rows and the pager
     */
    public static function slice(array $rows, array $sizes = self::PAGE_SIZES): array
    {
        [$result, $pager] = self::search(static fn (int $page, int $limit) => [
            'items' => array_slice($rows, ($page - 1) * $limit, $limit, true), 'total' => count($rows),
        ], $sizes);

        return [$result['items'], $pager];
    }

    /** @param State $pager */
    public static function render(array $pager): void
    {
        require (new ComponentResolver())->resolveTemplate('admin/pager');
    }
}
