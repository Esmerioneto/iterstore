<?php

declare(strict_types=1);

namespace Iter\Config\View;

use Iter\Core\Config;
use Iter\Core\View\ViewComponentInterface;

class SettingsForm implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'config/settings-form';
    }

    public function getData(): array
    {
        $old = $_SESSION['iter_old_input'] ?? [];
        unset($_SESSION['iter_old_input']);

        $groups = [];
        foreach (Config::fields() as $path => $field) {
            $field['value'] = $old[$path] ?? Config::get($path);
            $groups[$field['group'] ?? 'Geral'][] = $field;
        }

        return ['groups' => $groups];
    }
}
