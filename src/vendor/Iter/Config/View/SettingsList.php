<?php

declare(strict_types=1);

namespace Iter\Config\View;

use Iter\Core\View\ViewComponentInterface;

class SettingsList implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'config/settings-list';
    }

    public function getData(): array
    {
        $modules = [];
        foreach (\getModules() as $group => $entries) {
            foreach ($entries as $moduleKey => $enabled) {
                $modules[] = [
                    'group' => $group,
                    'vendor' => ucfirst(\getModuleVendor($moduleKey)),
                    'name' => ucfirst(\getModuleName($moduleKey)),
                    'enabled' => $enabled == 1,
                ];
            }
        }

        return ['modules' => $modules];
    }
}
