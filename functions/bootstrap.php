<?php
declare(strict_types=1);

require_once dirname(__DIR__, 1) . '/path.php';

use Iter\Router\Controllers\Index;

// ponytail: one fixed zone for PHP and (via Connection) the DB session; timestamps are
// stored "without time zone", so changing this later needs a data conversion like
// Iter_Core's 20260923000003 migration
date_default_timezone_set('America/Sao_Paulo');

function autoload(array $moduleNames): void
{
    $modulesCore = [];
    $modulesCode = [];

    foreach ($moduleNames as $module) {
        $module = str_replace('\\', DIRECTORY_SEPARATOR, $module);
        $moduleDir = ucfirst(getModuleVendor($module)) . DIRECTORY_SEPARATOR . ucfirst(getModuleName($module));

        $vendorPath = getVendorPath() . $moduleDir;
        $codePath = getCodePath() . $moduleDir;

        if (is_dir($vendorPath)) {
            $modulesCore[] = $vendorPath;
        }
        if (is_dir($codePath)) {
            $modulesCode[] = $codePath;
        }
    }

    $data = [];
    foreach (array_merge($modulesCore, $modulesCode) as $mdl) {
        $data = array_merge($data, setRoute(getRouteModule($mdl)));
    }

    $router = new Index();
    foreach ($data as $rt) {
        $router->addRoute($rt['route'], $rt['class_controller'], $rt['action'], $rt['method']);
    }
}

function getModules(): array
{
    return json_decode(file_get_contents(ETC . 'config.json'), true) ?? [];
}

function getModuleName(string $module): string
{
    return explode('_', $module)[1];
}

function getModuleVendor(string $module): string
{
    return explode('_', $module)[0];
}

function getRouteModule(string $moduleRouter): array
{
    $routerFile = $moduleRouter . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'router.json';
    if (!is_file($routerFile)) {
        return [];
    }

    return json_decode(file_get_contents($routerFile), true) ?? [];
}

function setRoute(array $router): array
{
    if (empty($router)) {
        return [];
    }

    // router.json may hold a single route config (legacy) or a list of them
    if (isset($router['route'])) {
        $router = [$router];
    }

    $routerMod = [];
    foreach ($router as $cfg) {
        $controllerName = strtolower(trim($cfg['controller_name']));
        $actions = explode(',', $cfg['controller_action']);

        foreach ($actions as $act) {
            $act = strtolower(trim($act));

            // default controller keeps the existing 2-segment URL untouched;
            // any other controller gets an extra path segment to avoid collisions
            $path = $controllerName === 'index'
                ? '/' . strtolower(trim($cfg['route'])) . '/' . $act
                : '/' . strtolower(trim($cfg['route'])) . '/' . $controllerName . '/' . $act;

            $routerMod[] = [
                'route' => $path,
                'class_controller' => ucfirst(strtolower(trim($cfg['vendor']))) .
                    '\\' . ucfirst(strtolower(trim($cfg['module_name']))) .
                    '\\Controllers\\' .
                    ucfirst($controllerName),
                'action' => ucfirst($act),
                'method' => strtoupper(trim($cfg['method'] ?? 'GET')),
            ];
        }
    }

    return $routerMod;
}

/**
 * Merges a JSON list file (e.g. "etc/config_fields.json") from every enabled
 * module, vendor first then app/code — same discovery order as routes.
 */
function getModuleJson(string $relativePath): array
{
    $items = [];

    foreach (getModules() as $group) {
        foreach ($group as $moduleKey => $enabled) {
            if ($enabled != 1) {
                continue;
            }

            $moduleDir = ucfirst(getModuleVendor($moduleKey)) . DIRECTORY_SEPARATOR . ucfirst(getModuleName($moduleKey));

            foreach ([getVendorPath(), getCodePath()] as $basePath) {
                $file = $basePath . $moduleDir . DIRECTORY_SEPARATOR . $relativePath;
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

    return $items;
}
