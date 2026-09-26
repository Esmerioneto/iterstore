<?php

declare(strict_types=1);

namespace Iter\Admin\View;

use Iter\Admin\MenuRegistry;
use Iter\Core\View\ViewComponentInterface;

class Menu implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'admin/menu';
    }

    public function getData(): array
    {
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $items = MenuRegistry::all();

        // A submenu starts expanded when the current page belongs to it or
        // to one of its children (same controller, e.g. /admin/website/edit)
        foreach ($items as &$item) {
            $urls = array_merge([$item['url']], array_column($item['children'], 'url'));
            $item['open'] = false;
            foreach ($urls as $url) {
                if (str_starts_with($path, dirname($url) . '/')) {
                    $item['open'] = true;
                }
            }
        }
        unset($item);

        return [
            'items' => $items,
        ];
    }
}
