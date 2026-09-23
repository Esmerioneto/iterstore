<?php

declare(strict_types=1);

namespace Iter\Core\View;

class LayoutLoader
{
    /**
     * Loads the raw (still un-merged) layout arrays for a handle, one per
     * enabled module that defines it, in module load order.
     *
     * @return array[]
     */
    public function load(string $handle): array
    {
        $layouts = [];

        foreach (\getModules() as $group) {
            foreach ($group as $moduleKey => $enabled) {
                if ($enabled != 1) {
                    continue;
                }

                $vendor = \getModuleVendor($moduleKey);
                $name = \getModuleName($moduleKey);

                foreach ([\getVendorPath(), \getCodePath()] as $basePath) {
                    $file = $basePath . ucfirst($vendor) . DIRECTORY_SEPARATOR . ucfirst($name) .
                        DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'frontend' .
                        DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . $handle . '.json';

                    if (!is_file($file)) {
                        continue;
                    }

                    $decoded = json_decode((string) file_get_contents($file), true);
                    if (is_array($decoded)) {
                        $layouts[] = $decoded;
                    }
                }
            }
        }

        return $layouts;
    }
}
