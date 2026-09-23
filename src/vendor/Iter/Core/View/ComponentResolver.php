<?php

declare(strict_types=1);

namespace Iter\Core\View;

use RuntimeException;

class ComponentResolver
{
    public function resolve(string $identifier): ViewComponentInterface
    {
        $class = ComponentRegistry::get($identifier);
        if ($class === null) {
            throw new RuntimeException("Componente nao registrado: {$identifier}");
        }

        if (!class_exists($class) || !is_subclass_of($class, ViewComponentInterface::class)) {
            throw new RuntimeException("Classe de componente invalida para '{$identifier}': {$class}");
        }

        return new $class();
    }

    /**
     * Resolves a "modulo/template" identifier to the physical template file,
     * searching every enabled module whose route/name matches "modulo".
     */
    public function resolveTemplate(string $identifier): string
    {
        [$module, $template] = array_pad(explode('/', $identifier, 2), 2, null);
        if ($module === null || $template === null) {
            throw new RuntimeException("Identificador de template invalido: {$identifier}");
        }

        foreach (\getModules() as $group) {
            foreach ($group as $moduleKey => $enabled) {
                if ($enabled != 1 || strtolower(\getModuleName($moduleKey)) !== strtolower($module)) {
                    continue;
                }

                $vendor = \getModuleVendor($moduleKey);
                $name = \getModuleName($moduleKey);

                foreach ([\getVendorPath(), \getCodePath()] as $basePath) {
                    $file = $basePath . ucfirst($vendor) . DIRECTORY_SEPARATOR . ucfirst($name) .
                        DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'frontend' .
                        DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . $template . '.php';

                    if (is_file($file)) {
                        return $file;
                    }
                }
            }
        }

        throw new RuntimeException("Template nao encontrado: {$identifier}");
    }
}
