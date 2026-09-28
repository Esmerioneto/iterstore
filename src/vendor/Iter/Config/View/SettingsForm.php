<?php

declare(strict_types=1);

namespace Iter\Config\View;

use Iter\Core\Config;
use Iter\Core\Db\Connection;
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

        $websites = Connection::get()->connect()->query('SELECT website_id, name FROM store_website ORDER BY sort_order, website_id')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $websiteId = (int) ($_GET['website'] ?? 0);
        $websiteId = isset($websites[$websiteId]) ? $websiteId : 0;

        // a website only shows the fields that can change per website
        $groups = [];
        foreach (Config::fields() as $path => $field) {
            if ($websiteId && ($field['scope'] ?? '') !== 'website') {
                continue;
            }
            $own = Config::websiteValue($path, $websiteId);
            $field['uses_default'] = $websiteId && $own === null;
            $field['value'] = ($field['type'] ?? 'text') === 'password' ? '' : ($old[$path] ?? Config::get($path, $websiteId ?: null));
            $field['has_secret'] = ($field['type'] ?? 'text') === 'password' && (string) ($websiteId ? $own : Config::get($path)) !== '';
            $groups[$field['group'] ?? 'Geral'][] = $field;
        }

        return ['groups' => $groups, 'websites' => $websites, 'website_id' => $websiteId];
    }
}
