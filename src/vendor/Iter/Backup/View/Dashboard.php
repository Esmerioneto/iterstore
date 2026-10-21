<?php

declare(strict_types=1);

namespace Iter\Backup\View;

use Iter\Admin\Auth;
use Iter\Backup\Model\Backup;
use Iter\Core\Config;
use Iter\Core\View\ViewComponentInterface;

class Dashboard implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'backup/dashboard';
    }

    public function getData(): array
    {
        // the Dashboard is open to every admin; the backup card only to the Administrador profile
        // and not at all when Configurações → Backup → "Avisos do backup no Dashboard" is off
        if (!Auth::isAllowed('Iter_Admin::root') || !Config::get('system/backup/dashboard_warnings')) {
            return ['status' => null];
        }
        $status = Backup::status();

        // "só neste servidor" is a choice; the Backup page keeps saying it, the Dashboard doesn't
        return ['status' => $status, 'warnings' => array_values(array_filter(Backup::warnings($status), static fn ($w) => $w['id'] !== 'local'))];
    }
}
