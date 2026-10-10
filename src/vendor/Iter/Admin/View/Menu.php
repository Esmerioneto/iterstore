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

        // A link is current when the page belongs to its controller (e.g. /admin/website/edit
        // for Websites). A link with a query (?entity_type=customer) also needs those values,
        // so Catálogo and Clientes don't both open on the shared attribute screens.
        // The accordion starts with the section of the current page open.
        $current = static function (string $url) use ($path): bool {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return str_starts_with($path, dirname((string) parse_url($url, PHP_URL_PATH)) . '/') && array_intersect_assoc($query, $_GET) === $query;
        };
        foreach ($items as &$item) {
            foreach ($item['children'] as &$child) {
                $child['current'] = $current($child['url']);
            }
            unset($child);
            // a link of its own (Painel, /admin/index) only on its own page: its controller folder is all of /admin
            $item['current'] = $item['children'] ? $current($item['url']) : $path === parse_url($item['url'], PHP_URL_PATH);
            $item['open'] = $item['current'] || in_array(true, array_column($item['children'], 'current'), true);
        }
        unset($item);

        return [
            'items' => $items,
        ];
    }
}
