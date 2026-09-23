<?php

declare(strict_types=1);

namespace Iter\Admin;

use Iter\Admin\Model\AdminUser;
use Iter\Core\Config;
use Iter\Core\ErrorPage;
use Iter\Core\FormKey;
use Iter\Core\Messages;

/**
 * Admin session + the single access check every request goes through.
 * Controllers declare what they need via `const ADMIN_RESOURCE` (Magento
 * style); the router calls authorize() before dispatching.
 */
class Auth
{
    private const SESSION_KEY = 'iter_admin_user_id';

    private static ?AdminUser $user = null;

    public static function login(string $username, string $password): bool
    {
        $user = new AdminUser();
        $user->getResource()->load($user, $username, 'username');

        if (!$user->getId() || !$user->getData('is_active')) {
            return false;
        }

        $lockExpires = $user->getData('lock_expires');
        if ($lockExpires && strtotime($lockExpires) > time()) {
            return false;
        }

        if (!password_verify($password, (string) $user->getData('password'))) {
            $failures = (int) $user->getData('failures_num') + 1;
            $locked = $failures >= (int) Config::get('admin/security/max_failures');
            $user->addData([
                'failures_num' => $locked ? 0 : $failures,
                'lock_expires' => $locked ? date('Y-m-d H:i:s', time() + (int) Config::get('admin/security/lock_minutes') * 60) : null,
            ])->save();

            return false;
        }

        $user->addData([
            'failures_num' => 0,
            'lock_expires' => null,
            'logdate' => date('Y-m-d H:i:s'),
            'lognum' => (int) $user->getData('lognum') + 1,
        ])->save();

        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = (int) $user->getId();
        self::$user = $user;

        return true;
    }

    public static function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
        session_regenerate_id(true);
        self::$user = null;
    }

    /**
     * Reloaded from the DB on every request, so deactivating a user or
     * changing their role takes effect immediately.
     */
    public static function user(): ?AdminUser
    {
        if (self::$user === null && !empty($_SESSION[self::SESSION_KEY])) {
            $user = (new AdminUser())->load((int) $_SESSION[self::SESSION_KEY]);
            if ($user->getId() && $user->getData('is_active')) {
                self::$user = $user;
            } else {
                unset($_SESSION[self::SESSION_KEY]);
            }
        }

        return self::$user;
    }

    public static function isAllowed(string $resourceId): bool
    {
        $roleId = self::user()?->getData('role_id');

        return Acl::isAllowed($roleId === null ? null : (int) $roleId, $resourceId);
    }

    /**
     * Resource comes from the controller's ADMIN_RESOURCE; `null` there means
     * public (login page). Anything under /admin/ that forgot to declare one
     * falls back to the root node — default-deny, like Magento's backend.
     * Returns false after having already sent the redirect / 403.
     */
    public static function authorize(string $controller, string $uri): bool
    {
        $isAdmin = defined($controller . '::ADMIN_RESOURCE') || str_starts_with($uri, '/admin/');
        $resource = defined($controller . '::ADMIN_RESOURCE')
            ? constant($controller . '::ADMIN_RESOURCE')
            : ($isAdmin ? 'Iter_Admin::root' : null);

        // every admin form POST (login included) must carry the session's form_key
        if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && !FormKey::isValid($_POST['form_key'] ?? null)) {
            Messages::addError('Sua sessão expirou ou o formulário é inválido. Tente novamente.');
            // back to the form, but only a local path: "//host" or "/\host" would
            // leave the site, so those fall back to the login page
            $back = (string) parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_PATH);
            header('Location: ' . (preg_match('#^/(?![/\\\\])#', $back) ? $back : '/admin/auth/login'));
            return false;
        }

        if ($resource === null) {
            return true;
        }

        if (self::user() === null) {
            header('Location: /admin/auth/login');
            return false;
        }

        // expired password: nothing but the change-password screen (public resource, so it never gets here)
        if (self::user()->isPasswordExpired()) {
            Messages::addError('Sua senha expirou. Defina uma nova senha para continuar.');
            header('Location: /admin/auth/password');
            return false;
        }

        if (!self::isAllowed($resource)) {
            ErrorPage::render(403, 'Acesso negado', 'Seu perfil não tem permissão para acessar esta página.', [
                'Dashboard' => '/admin/index',
                'Sair' => '/admin/auth/logout',
            ]);
            return false;
        }

        return true;
    }
}
