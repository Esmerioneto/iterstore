<?php

declare(strict_types=1);

namespace Iter\Admin\Controllers;

use Iter\Admin\Auth as AdminAuth;
use Iter\Admin\MenuRegistry;
use Iter\Admin\Model\AdminUser as AdminUserModel;
use Iter\Core\Messages;
use Iter\Core\View\Layout;

class Auth
{
    public const ADMIN_RESOURCE = null;

    public function Login(): void
    {
        if (AdminAuth::user() !== null) {
            header('Location: ' . self::homeUrl());
            exit;
        }

        echo Layout::render('admin_auth_login');
    }

    public function Loginpost(): void
    {
        if (!AdminAuth::login((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''))) {
            // ponytail: one generic message for wrong user/password/inactive/locked, no username enumeration
            Messages::addError('Usuário ou senha inválidos.');
            header('Location: /admin/auth/login');
            exit;
        }

        header('Location: ' . self::homeUrl());
        exit;
    }

    /**
     * "Alterar senha": reached from the header link or forced by
     * Auth::authorize() when the password expired. Public resource so an
     * expired user isn't redirected in a loop; the login check is here.
     */
    public function Password(): void
    {
        if (AdminAuth::user() === null) {
            header('Location: /admin/auth/login');
            exit;
        }

        echo Layout::render('admin_auth_password');
    }

    public function Passwordpost(): void
    {
        $user = AdminAuth::user();
        if ($user === null) {
            header('Location: /admin/auth/login');
            exit;
        }

        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['password'] ?? '');

        $error = match (true) {
            !password_verify($current, (string) $user->getData('password')) => 'Senha atual incorreta.',
            $new === '' => 'Informe a nova senha.',
            $new !== ($_POST['password_confirm'] ?? '') => 'As senhas não coincidem.',
            ($strengthError = AdminUserModel::validatePasswordStrength($new)) !== null => $strengthError,
            $user->isPasswordReused($new) => 'A nova senha não pode repetir uma das senhas usadas recentemente.',
            default => null,
        };

        if ($error !== null) {
            Messages::addError($error);
            header('Location: /admin/auth/password');
            exit;
        }

        $user->setPassword($new)->save();
        Messages::addSuccess('Senha alterada com sucesso.');
        header('Location: ' . self::homeUrl());
        exit;
    }

    public function Logout(): void
    {
        AdminAuth::logout();
        header('Location: /admin/auth/login');
        exit;
    }

    /**
     * First screen the role can open (menu is already ACL-filtered), so a
     * role without Dashboard doesn't land on a 403 right after login.
     */
    private static function homeUrl(): string
    {
        return MenuRegistry::all()[0]['url'] ?? '/admin/index';
    }
}
