<?php

declare(strict_types=1);

namespace Iter\Log\Controllers;

use Iter\Admin\Breadcrumb;
use Iter\Core\Messages;
use Iter\Core\View\Layout;
use Iter\Log\Model\OperationLog;

/** Sistema → Log de operações: list and one operation; read only */
class Log
{
    public const ADMIN_RESOURCE = 'Iter_Log::view';

    public function Index(): void
    {
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Log de operações'],
        ]);

        echo Layout::renderAdmin('admin_log_index');
    }

    public function View(): void
    {
        if (!OperationLog::find((int) ($_GET['id'] ?? 0))) {
            Messages::addError('Operação não encontrada.');
            header('Location: /admin/log/index');
            exit;
        }
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Log de operações', 'url' => '/admin/log/index'],
            ['label' => 'Operação'],
        ]);

        echo Layout::renderAdmin('admin_log_view');
    }
}
