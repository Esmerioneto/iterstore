<?php

declare(strict_types=1);

namespace Iter\Config\View;

use Iter\Admin\Auth;
use Iter\Admin\View\Pager;
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
        [$modules, $pager] = Pager::slice(ModuleManager::all());

        return ['modules' => $modules, 'pager' => $pager, 'can_toggle' => Auth::isAllowed('Iter_Config::modules')];
    }
}
