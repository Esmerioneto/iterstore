<?php

declare(strict_types=1);

namespace Iter\Admin\Controllers;

use Iter\Admin\Auth as AdminAuth;
use Iter\Admin\MenuRegistry;
use Iter\Admin\Model\AdminUser as AdminUserModel;
use Iter\Core\Config;
use Iter\Core\Log;
use Iter\Core\FormKey;
use Iter\Core\Messages;
use Iter\Core\RateLimit;
use Iter\Core\View\Layout;
use Iter\Mail\Model\MailException;
use Iter\Mail\Model\MailService;

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
        // the same per-IP count as generateAdminToken: the per-user lock alone lets one IP try every username
        if (!RateLimit::hit('admin_login:' . RateLimit::bucket(), (int) Config::get('admin/security/api_login_per_minute'))) {
            Messages::addError('Muitas tentativas de login. Aguarde um minuto e tente de novo.');
            header('Location: /admin/auth/login');
            exit;
        }
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
        AdminAuth::passwordChanged();
        Messages::addSuccess('Senha alterada com sucesso.');
        header('Location: ' . self::homeUrl());
        exit;
    }

    public function Forgot(): void
    {
        echo Layout::render('admin_auth_forgot');
    }

    public function Forgotpost(): void
    {
        $email = trim((string) ($_POST['email'] ?? ''));
        if (!RateLimit::hit('admin_forgot:' . RateLimit::bucket(), (int) Config::get('admin/security/api_login_per_minute'))) {
            Messages::addError('Muitas solicitações seguidas. Aguarde um minuto e tente de novo.');
            header('Location: /admin/auth/forgot');
            exit;
        }
        $hours = (int) Config::get('admin/security/reset_link_hours');

        foreach (filter_var($email, FILTER_VALIDATE_EMAIL) ? (new AdminUserModel())->getResource()->getActiveIdsByEmail($email) : [] as $userId) {
            $user = (new AdminUserModel())->load($userId);
            $token = $user->createResetToken();
            if ($token === null) {
                continue;
            }

            $link = Config::baseUrl() . '/admin/auth/reset?' . http_build_query(['id' => $userId, 'token' => $token]);
            try {
                (new MailService())->send('admin_password_reset', (string) $user->getData('email'), [
                    'name' => $user->getData('firstname') ?: $user->getData('username'),
                    'username' => $user->getData('username'),
                    'link' => $link,
                    'hours' => $hours,
                ], now: true);
            } catch (MailException $e) {
                // same answer below either way; a mail setup problem doesn't break the page
                Log::error('Mail: redefinição de senha não enfileirada: ' . $e->getMessage());
            }
        }

        // same answer whether or not the e-mail exists, no account enumeration
        Messages::addSuccess('Se o e-mail estiver cadastrado, você receberá um link para redefinir a senha.');
        header('Location: /admin/auth/login');
        exit;
    }

    public function Reset(): void
    {
        if (self::resetUser() === null) {
            Messages::addError('Link de redefinição inválido ou expirado. Solicite um novo.');
            header('Location: /admin/auth/forgot');
            exit;
        }

        echo Layout::render('admin_auth_reset');
    }

    public function Resetpost(): void
    {
        $user = self::resetUser();
        if ($user === null) {
            Messages::addError('Link de redefinição inválido ou expirado. Solicite um novo.');
            header('Location: /admin/auth/forgot');
            exit;
        }

        $new = (string) ($_POST['password'] ?? '');
        $error = match (true) {
            $new !== ($_POST['password_confirm'] ?? '') => 'As senhas não coincidem.',
            ($strengthError = AdminUserModel::validatePasswordStrength($new)) !== null => $strengthError,
            $user->isPasswordReused($new) => 'A nova senha não pode repetir uma das senhas usadas recentemente.',
            default => null,
        };

        if ($error !== null) {
            Messages::addError($error);
            header('Location: /admin/auth/reset?' . http_build_query(['id' => $_POST['id'] ?? '', 'token' => $_POST['token'] ?? '']));
            exit;
        }

        // proving access to the mailbox also lifts a brute-force lock
        $user->setPassword($new)->addData([
            'failures_num' => 0,
            'lock_expires' => null,
        ])->save();

        Messages::addSuccess('Senha redefinida. Entre com a nova senha.');
        header('Location: /admin/auth/login');
        exit;
    }

    /** User from the reset link (GET) or form (POST), only when its token is still valid. */
    private static function resetUser(): ?AdminUserModel
    {
        $input = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
        $id = (int) ($input['id'] ?? 0);
        $token = (string) ($input['token'] ?? '');
        if ($id <= 0 || $token === '') {
            return null;
        }

        $user = (new AdminUserModel())->load($id);

        return $user->getId() && $user->isResetTokenValid($token) ? $user : null;
    }

    /** a confirmation: a link or <img> on another site can't log anyone out, only the form's POST (with the form_key) */
    public function Logout(): void
    {
        echo '<!doctype html><html lang="pt-br"><meta charset="utf-8"><title>Sair</title>'
            . '<body style="font-family:sans-serif;max-width:600px;margin:80px auto;text-align:center;color:#333">'
            . '<form method="post" action="/admin/auth/logoutpost">' . FormKey::field()
            . '<h1 style="font-size:22px">Sair do painel?</h1><p><button type="submit">Sair</button> · <a href="/admin/index">Voltar</a></p></form></body></html>';
    }

    public function Logoutpost(): void
    {
        AdminAuth::logout();
        header('Location: /admin/auth/login');
        exit;
    }

    /**
     * The menu's red counters (e.g. unread messages), menu URL => count, for admin.js to refresh them on any
     * screen without reloading. Public route (no resource of its own): it answers only a logged-in user, and
     * only the items that user's menu shows. Sent with X-Admin-Poll, so it doesn't keep the session alive.
     */
    public function Badges(): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        if (AdminAuth::user() === null) {
            http_response_code(401);
            echo json_encode(['error' => 'UNAUTHENTICATED']);
            return;
        }
        $badges = [];
        foreach ((new \Iter\Admin\View\Menu())->getData()['items'] as $item) {
            foreach ($item['children'] ?: [$item] as $link) {
                $link['badge'] > 0 && $badges[$link['url']] = $link['badge'];
            }
        }
        echo json_encode((object) $badges);
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
