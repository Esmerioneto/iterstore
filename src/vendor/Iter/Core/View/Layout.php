<?php

declare(strict_types=1);

namespace Iter\Core\View;

/**
 * Entry point used by controllers: Layout::render('user_index').
 * Pipeline: load -> merge -> parse -> resolve positions -> render.
 */
class Layout
{
    public static function render(string $handle, bool $strict = false): string
    {
        $layouts = (new LayoutLoader())->load($handle);
        $merged = (new LayoutMerger())->merge($layouts);
        $tree = (new LayoutParser())->parse($merged);

        (new PositionResolver($strict))->resolve($tree);

        return (new LayoutRenderer())->render($tree);
    }

    /**
     * Renders a page inside the admin base theme: merges the fixed
     * "admin_base" skeleton (header/breadcrumb/messages/menu/footer) with
     * the page-specific handle, which only needs to declare content inside
     * the "dashboard" container.
     */
    public static function renderAdmin(string $handle, bool $strict = false): string
    {
        $layouts = array_merge(
            (new LayoutLoader())->load('admin_base'),
            (new LayoutLoader())->load($handle)
        );
        $merged = (new LayoutMerger())->merge($layouts);
        $tree = (new LayoutParser())->parse($merged);

        (new PositionResolver($strict))->resolve($tree);

        return (new LayoutRenderer())->render($tree);
    }
}
