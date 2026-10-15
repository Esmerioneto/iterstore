<?php

declare(strict_types=1);

namespace Iter\Config;

/**
 * Turns modules on/off in src/etc/config.json. A module's etc/config.json can
 * declare "required": true (never turned off: the admin itself needs it) and
 * "depends": ["vendor_module", ...] (off only after its dependents, on only
 * after its dependencies). Turning off keeps the module's tables and data.
 */
class ModuleManager
{
    /** @return array<int, array{key: string, group: string, vendor: string, name: string, enabled: bool, required: bool, depends: string[], dependents: string[]}> */
    public static function all(): array
    {
        $modules = [];
        foreach (\getModules() as $group => $entries) {
            foreach ($entries as $key => $enabled) {
                $meta = self::meta($key);
                $modules[$key] = [
                    'key' => $key,
                    'group' => $group,
                    'vendor' => ucfirst(\getModuleVendor($key)),
                    'name' => ucfirst(\getModuleName($key)),
                    'enabled' => $enabled == 1,
                    'required' => !empty($meta['required']),
                    'depends' => array_values((array) ($meta['depends'] ?? [])),
                    'dependents' => [],
                ];
            }
        }
        foreach ($modules as $key => $module) {
            foreach ($module['depends'] as $dependency) {
                if ($module['enabled'] && isset($modules[$dependency])) {
                    $modules[$dependency]['dependents'][] = $module['name'];
                }
            }
        }

        return array_values($modules);
    }

    /** @throws \DomainException with the message for the admin */
    public static function setEnabled(string $key, bool $enabled): void
    {
        $byKey = array_column(self::all(), null, 'key');
        $module = $byKey[$key] ?? throw new \DomainException('Módulo não encontrado.');
        if (!$enabled && $module['required']) {
            throw new \DomainException("O módulo {$module['name']} é essencial e não pode ser desativado.");
        }
        if (!$enabled && $module['dependents']) {
            throw new \DomainException("Desative antes os módulos que dependem de {$module['name']}: " . implode(', ', $module['dependents']) . '.');
        }
        $missing = array_map(
            static fn ($d) => $byKey[$d]['name'] ?? $d,
            array_filter($module['depends'], static fn ($d) => !($byKey[$d]['enabled'] ?? false))
        );
        if ($enabled && $missing) {
            throw new \DomainException("Ative antes os módulos de que {$module['name']} depende: " . implode(', ', $missing) . '.');
        }

        $config = \getModules();
        $config[$module['group']][$key] = $enabled ? '1' : '0';
        // temp file + rename: a request reading config.json never sees it half written
        $file = ETC . 'config.json';
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL) === false || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new \DomainException('Não foi possível gravar ' . $file . ' (permissão de escrita?).');
        }
        \getModules(true);
    }

    private static function meta(string $key): array
    {
        $dir = ucfirst(\getModuleVendor($key)) . DIRECTORY_SEPARATOR . ucfirst(\getModuleName($key)) . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'config.json';
        foreach ([\getVendorPath(), \getCodePath()] as $base) {
            if (is_file($base . $dir)) {
                return json_decode((string) file_get_contents($base . $dir), true) ?? [];
            }
        }

        return [];
    }
}
