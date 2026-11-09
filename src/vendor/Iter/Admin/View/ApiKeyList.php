<?php

declare(strict_types=1);

namespace Iter\Admin\View;

use Iter\Admin\Controllers\Apikey as ApikeyController;
use Iter\Admin\Model\ApiKey;
use Iter\Core\Config;
use Iter\Core\View\ViewComponentInterface;

class ApiKeyList implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'admin/apikey-list';
    }

    public function getData(): array
    {
        $new = $_SESSION[ApikeyController::NEW_KEY] ?? null;
        unset($_SESSION[ApikeyController::NEW_KEY]);
        [$keys, $pager] = Pager::slice(ApiKey::all());

        return ['keys' => $keys, 'pager' => $pager, 'new' => $new, 'required' => (bool) Config::get('system/api/require_key'), 'gridColumns' => new GridColumns('admin/apikey-list', $keys, ['name', 'key', 'status', 'created', 'last_used'])];
    }
}
