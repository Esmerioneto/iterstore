<?php

declare(strict_types=1);

namespace Iter\Admin\Model;

use Iter\Admin\Model\ResourceModel\AdminUser as AdminUserResource;
use Iter\Core\Config;
use Iter\Core\Model\AbstractModel;

/**
 * @method AdminUserResource getResource()
 */
class AdminUser extends AbstractModel
{
    protected string $_idFieldName = 'user_id';

    protected function _construct(): void
    {
        $this->_resource = new AdminUserResource();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getList(): array
    {
        return $this->getResource()->getList();
    }

    public function setPassword(string $plainPassword): static
    {
        return $this->setData('password', password_hash($plainPassword, PASSWORD_DEFAULT));
    }

    /**
     * Strength rule shared by every place that sets a password (user form,
     * "Alterar senha", bin/create-admin.php). Returns the error or null.
     */
    public static function validatePasswordStrength(string $plainPassword): ?string
    {
        $minLength = (int) Config::get('admin/security/password_min_length');
        if (mb_strlen($plainPassword) < $minLength) {
            return sprintf('A senha deve ter pelo menos %d caracteres.', $minLength);
        }

        if (Config::get('admin/security/password_require_alnum')
            && !(preg_match('/\p{L}/u', $plainPassword) && preg_match('/\p{N}/u', $plainPassword))) {
            return 'A senha deve conter letras e números.';
        }

        return null;
    }

    /**
     * True when $plainPassword matches one of the last N passwords
     * (admin/security/password_history; 0 disables the check).
     */
    public function isPasswordReused(string $plainPassword): bool
    {
        $limit = (int) Config::get('admin/security/password_history');
        if ($limit === 0 || !$this->getId()) {
            return false;
        }

        foreach ($this->getResource()->getPasswordHistory((int) $this->getId(), $limit) as $hash) {
            if (password_verify($plainPassword, $hash)) {
                return true;
            }
        }

        return false;
    }

    /** admin/security/password_lifetime days since the last change; 0 = never expires. */
    public function isPasswordExpired(): bool
    {
        $days = (int) Config::get('admin/security/password_lifetime');
        $changedAt = $this->getResource()->getPasswordChangedAt((int) $this->getId());

        return $days > 0 && $changedAt !== null && $changedAt + $days * 86400 < time();
    }
}
