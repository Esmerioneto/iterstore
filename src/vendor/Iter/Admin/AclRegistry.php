<?php

declare(strict_types=1);

namespace Iter\Admin;

/**
 * Discovers etc/acl.json across all enabled modules (same scan pattern as
 * MenuRegistry), building the resource tree used by role permissions. The
 * tree itself lives in code — only which nodes a role has been granted
 * lives in the database (admin_role_rule).
 */
class AclRegistry
{
    /**
     * @return array<int, array{id: string, parent?: string, title: string}>
     */
    public static function all(): array
    {
        $nodes = [];

        foreach (\getModules() as $group) {
            foreach ($group as $moduleKey => $enabled) {
                if ($enabled != 1) {
                    continue;
                }

                $vendor = \getModuleVendor($moduleKey);
                $name = \getModuleName($moduleKey);

                foreach ([\getVendorPath(), \getCodePath()] as $basePath) {
                    $file = $basePath . ucfirst($vendor) . DIRECTORY_SEPARATOR . ucfirst($name) .
                        DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'acl.json';

                    if (!is_file($file)) {
                        continue;
                    }

                    $decoded = json_decode((string) file_get_contents($file), true);
                    if (is_array($decoded)) {
                        $nodes = array_merge($nodes, $decoded);
                    }
                }
            }
        }

        return $nodes;
    }

    /**
     * @return array<string, array{id: string, parent?: string, title: string}> indexed by node id
     */
    public static function byId(): array
    {
        $byId = [];
        foreach (self::all() as $node) {
            $byId[$node['id']] = $node;
        }

        return $byId;
    }

    /**
     * Flat, parent-before-child ordering with a "depth" key added, so a
     * template can render the tree as an indented checkbox list without
     * needing recursion of its own.
     *
     * @return array<int, array{id: string, parent?: string, title: string, depth: int}>
     */
    public static function sortedByDepth(): array
    {
        $byId = self::byId();

        $childrenOf = [];
        foreach ($byId as $id => $node) {
            $childrenOf[$node['parent'] ?? ''][] = $id;
        }

        $ordered = [];
        $visit = function (string $parentId, int $depth) use (&$visit, &$ordered, $childrenOf, $byId): void {
            foreach ($childrenOf[$parentId] ?? [] as $id) {
                $ordered[] = array_merge($byId[$id], ['depth' => $depth]);
                $visit($id, $depth + 1);
            }
        };
        $visit('', 0);

        return $ordered;
    }
}
