<?php

declare(strict_types=1);

namespace Iter\Admin;

/**
 * Per-request breadcrumb trail. Controllers call set() before rendering
 * an admin page; the admin/breadcrumb component reads it at render time.
 */
class Breadcrumb
{
    /** @var array<int, array{label: string, url?: string}> */
    private static array $items = [];

    /**
     * @param array<int, array{label: string, url?: string}> $items
     */
    public static function set(array $items): void
    {
        self::$items = $items;
    }

    public static function get(): array
    {
        return self::$items;
    }
}
