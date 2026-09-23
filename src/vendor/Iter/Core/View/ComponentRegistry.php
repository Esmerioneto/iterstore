<?php

declare(strict_types=1);

namespace Iter\Core\View;

class ComponentRegistry
{
    /** @var array<string, string>|null */
    private static ?array $map = null;

    public static function get(string $identifier): ?string
    {
        return self::all()[$identifier] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        if (self::$map === null) {
            self::$map = self::discover();
        }

        return self::$map;
    }

    private static function discover(): array
    {
        $map = [];

        foreach (\getModules() as $group) {
            foreach ($group as $moduleKey => $enabled) {
                if ($enabled != 1) {
                    continue;
                }

                $vendor = \getModuleVendor($moduleKey);
                $name = \getModuleName($moduleKey);

                foreach ([\getVendorPath(), \getCodePath()] as $basePath) {
                    $file = $basePath . ucfirst($vendor) . DIRECTORY_SEPARATOR .
                        ucfirst($name) . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'components.php';

                    if (is_file($file)) {
                        $map = array_merge($map, require $file);
                    }
                }
            }
        }

        return $map;
    }
}
