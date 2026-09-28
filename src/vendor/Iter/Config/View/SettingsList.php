<?php

declare(strict_types=1);

namespace Iter\Config\View;

use Iter\Admin\Auth;
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
        return ['modules' => ModuleManager::all(), 'can_toggle' => Auth::isAllowed('Iter_Config::modules')];
    }
}
