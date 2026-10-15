<?php

declare(strict_types=1);

namespace Iter\Config\View;

use Iter\Admin\Auth;
use Iter\Admin\View\Sort;
use Iter\Config\ModuleManager;
use Iter\Core\View\ViewComponentInterface;

class SettingsList implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'config/settings-list';
    }

    public function getData(): array
    {
        [$modules, $pager, $sort, $dir, $q] = Sort::slice(ModuleManager::all(), ['group' => 'group', 'vendor' => 'vendor', 'name' => 'name', 'enabled' => 'enabled'], 'group', 'asc', ['group', 'vendor', 'name']);

        return ['modules' => $modules, 'pager' => $pager, 'sort' => $sort, 'dir' => $dir, 'q' => $q, 'can_toggle' => Auth::isAllowed('Iter_Config::modules')];
    }
}
