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
        // to one of its children (same controller, e.g. /admin/website/edit).
        // A link with a query (?entity_type=customer) also needs those values,
        // so Catálogo and Clientes don't both open on the shared attribute screens
        foreach ($items as &$item) {
            $urls = array_merge([$item['url']], array_column($item['children'], 'url'));
            $item['open'] = false;
            foreach ($urls as $url) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                if (str_starts_with($path, dirname((string) parse_url($url, PHP_URL_PATH)) . '/')
                    && array_intersect_assoc($query, $_GET) === $query) {
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
