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
    /** the API token of this request (useToken), null on a session */
    private static ?string $token = null;

    public static function login(string $username, string $password): bool
    {
        $user = self::checkCredentials($username, $password);
        if ($user === null) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = (int) $user->getId();
        self::$user = $user;

        return true;
    }

    /**
     * The password check shared by the admin form and the API: counts failures,
     * locks the account after admin/security/max_failures, records the login.
     */
    public static function checkCredentials(string $username, string $password): ?AdminUser
    {
        $user = new AdminUser();
        $user->getResource()->load($user, $username, 'username');

        if (!$user->getId() || !$user->getData('is_active')) {
            return null;
        }

        $lockExpires = $user->getData('lock_expires');
        if ($lockExpires && strtotime($lockExpires) > time()) {
            return null;
        }

        if (!password_verify($password, (string) $user->getData('password'))) {
            $failures = (int) $user->getData('failures_num') + 1;
            $locked = $failures >= (int) Config::get('admin/security/max_failures');
            $user->addData([
                'failures_num' => $locked ? 0 : $failures,
                'lock_expires' => $locked ? date('Y-m-d H:i:s', time() + (int) Config::get('admin/security/lock_minutes') * 60) : null,
            ])->save();

            return null;
        }

        $user->addData([
            'failures_num' => 0,
            'lock_expires' => null,
            'logdate' => date('Y-m-d H:i:s'),
            'lognum' => (int) $user->getData('lognum') + 1,
        ])->save();

        return $user;
    }

    /**
     * API login: a random token valid for admin/security/api_token_hours. Only its
     * sha256 is stored; expired ones are cleaned up here.
     *
     * @return array{token: string, expires_at: string}
     */
    public static function issueToken(AdminUser $user): array
    {
        $db = $user->getResource()->getConnection();
        $db->delete('admin_api_token', ['expires_at < ?' => date('Y-m-d H:i:s')]);
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + (int) Config::get('admin/security/api_token_hours') * 3600);
        $db->insert('admin_api_token', ['user_id' => (int) $user->getId(), 'token_hash' => hash('sha256', $token), 'created_at' => date('Y-m-d H:i:s'), 'expires_at' => $expiresAt]);

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    /**
     * An API request with "Authorization: Bearer <token>" acts as the token's user
     * (no session). False when the token is unknown, expired, revoked, older than
     * the user's last password change, or the user is inactive.
     */
    public static function useToken(string $token): bool
    {
        $user = new AdminUser();
        $db = $user->getResource()->getConnection();
        $row = $db->fetchRow($db->select()->from('admin_api_token', ['user_id', 'created_at'])
            ->where('token_hash = ?', hash('sha256', $token))
            ->where('expires_at > ?', date('Y-m-d H:i:s')));
        if (!$row || !$user->load((int) $row['user_id'])->getId() || !$user->getData('is_active')) {
            return false;
        }
        $changedAt = $user->getResource()->getPasswordChangedAt((int) $user->getId());
        if ($changedAt !== null && strtotime((string) $row['created_at']) < $changedAt) { // a new password ends older tokens
            return false;
        }
        self::$user = $user;
        self::$token = $token;

        return true;
    }

    /** API logout: the token in use stops working */
    public static function revokeToken(): bool
    {
        if (self::$token === null) {
            return false;
        }
        (new AdminUser())->getResource()->getConnection()->delete('admin_api_token', ['token_hash = ?' => hash('sha256', self::$token)]);
        self::$token = null;
        self::$user = null;

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
