<?php

declare(strict_types=1);

namespace Iter\Backup\View;

use Iter\Admin\Auth;
use Iter\Backup\Model\Backup;
use Iter\Core\Config;
use Iter\Core\View\ViewComponentInterface;

class Status implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'backup/status';
    }

    public function getData(): array
    {
        $status = Backup::status();

        return [
            'status' => $status,
            'warnings' => Backup::warnings($status),
            'pending' => Backup::runRequested(),
            'destination' => Backup::destinationLabel(),
            'frequency' => Config::fields()['system/backup/frequency']['options'][(string) Config::get('system/backup/frequency')] ?? '',
            'active' => (bool) Config::get('system/backup/active'),
            'root' => Auth::isAllowed('Iter_Admin::root'),
            'kit_saved_at' => (string) Config::get(Backup::KIT_SAVED_AT),
        ];
    }
}
