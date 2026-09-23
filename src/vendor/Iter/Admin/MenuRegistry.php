<?php

declare(strict_types=1);

namespace Iter\Admin;

/**
 * Discovers etc/menu.json across all enabled modules (same scan pattern as
 * ComponentRegistry/LayoutLoader), so any module can contribute a sidebar
 * entry without touching Iter/Admin.
 */
class MenuRegistry
{
    /**
     * @return array<int, array{label: string, url: string, sort_order?: int, id?: string, parent?: string}>
     */
    public static function flat(): array
    {
        $items = [];

        foreach (\getModules() as $group) {
            foreach ($group as $moduleKey => $enabled) {
                if ($enabled != 1) {
                    continue;
                }

                $vendor = \getModuleVendor($moduleKey);
                $name = \getModuleName($moduleKey);

                foreach ([\getVendorPath(), \getCodePath()] as $basePath) {
                    $file = $basePath . ucfirst($vendor) . DIRECTORY_SEPARATOR . ucfirst($name) .
                        DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'menu.json';

                    if (!is_file($file)) {
                        continue;
                    }

                    $decoded = json_decode((string) file_get_contents($file), true);
                    if (is_array($decoded)) {
                        $items = array_merge($items, $decoded);
                    }
                }
            }
        }

        usort($items, static fn (array $a, array $b) => ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0));

        return $items;
    }

    /**
     * Nests items under their "parent" id (e.g. Loja/Perfis under
     * "configuracoes") so the template can render submenus.
     *
     * @return array<int, array{label: string, url: string, children: array}>
     */
    public static function all(): array
    {
        $items = array_filter(
            self::flat(),
            static fn (array $item) => empty($item['resource']) || Auth::isAllowed($item['resource'])
        );

        // children of a parent the role can't see are promoted to top level,
        // so e.g. a "Loja"-only role still gets its link
        $visibleIds = array_filter(array_column($items, 'id'));
        foreach ($items as &$item) {
            if (!empty($item['parent']) && !in_array($item['parent'], $visibleIds, true)) {
                unset($item['parent']);
            }
        }
        unset($item);

        $topLevel = array_values(array_filter($items, static fn (array $item) => empty($item['parent'])));

        foreach ($topLevel as &$item) {
            $item['children'] = empty($item['id']) ? [] : array_values(array_filter(
                $items,
                static fn (array $child) => ($child['parent'] ?? null) === $item['id']
            ));
        }

        return $topLevel;
    }
}
