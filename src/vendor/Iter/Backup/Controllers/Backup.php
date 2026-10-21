<?php

declare(strict_types=1);

namespace Iter\Backup\Controllers;

use Iter\Admin\Auth;
use Iter\Admin\Breadcrumb;
use Iter\Backup\Model\Backup as BackupModel;
use Iter\Backup\View\Kit;
use Iter\Core\Messages;
use Iter\Core\RateLimit;
use Iter\Core\View\Layout;
use Iter\Log\Model\OperationLog;

/** Sistema → Backup: the situation, "Fazer backup agora" and the recovery kit (root only) */
class Backup
{
    public const ADMIN_RESOURCE = 'Iter_Backup::backup';

    public function Index(): void
    {
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Backup'],
        ]);

        echo Layout::renderAdmin('admin_backup_index');
    }

    public function Run(): void
    {
        BackupModel::requestRun();
        OperationLog::record('backup_run', 'backup', null, 'Backup');
        Messages::addSuccess('Backup pedido. Ele começa em até 1 minuto; atualize esta tela para ver o resultado.');
        header('Location: /admin/backup/index');
        exit;
    }

    /** the kit asks for the password again; only the root profile */
    public function Kit(): void
    {
        self::rootOnly();
        self::kitBreadcrumb();
        echo Layout::renderAdmin('admin_backup_kit');
    }

    public function Kitpost(): void
    {
        self::rootOnly();
        $user = Auth::user();
        if (!RateLimit::hit('backup_kit:' . $user->getId(), 5)) {
            Messages::addError('Muitas tentativas. Aguarde um minuto e tente de novo.');
            header('Location: /admin/backup/kit');
            exit;
        }
        if (!password_verify((string) ($_POST['password'] ?? ''), (string) $user->getData('password'))) {
            Messages::addError('Senha incorreta.');
            header('Location: /admin/backup/kit');
            exit;
        }
        try {
            Kit::$unlocked = BackupModel::kit();
        } catch (\RuntimeException $e) {
            Messages::addError($e->getMessage());
            header('Location: /admin/backup/kit');
            exit;
        }
        OperationLog::record('backup_kit', 'backup', null, 'Kit de recuperação do backup');
        // shown in this response only: not in the session, not in the browser's cache
        header('Cache-Control: no-store');
        self::kitBreadcrumb();
        echo Layout::renderAdmin('admin_backup_kit');
    }

    public function Kitsaved(): void
    {
        self::rootOnly();
        BackupModel::markKitSaved();
        OperationLog::record('backup_kit_saved', 'backup', null, 'Kit de recuperação do backup');
        Messages::addSuccess('Kit marcado como guardado. Se a senha do backup ou a chave do env.ini mudarem, o aviso volta.');
        header('Location: /admin/backup/index');
        exit;
    }

    private static function rootOnly(): void
    {
        if (!Auth::isAllowed('Iter_Admin::root')) {
            Messages::addError('Só o perfil Administrador abre o kit de recuperação.');
            header('Location: /admin/backup/index');
            exit;
        }
    }

    private static function kitBreadcrumb(): void
    {
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Backup', 'url' => '/admin/backup/index'],
            ['label' => 'Kit de recuperação'],
        ]);
    }
}
