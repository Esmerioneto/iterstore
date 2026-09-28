<?php

declare(strict_types=1);

namespace Iter\Admin\Model\ResourceModel;

use Iter\Core\Model\AbstractModel;
use Iter\Core\Model\ResourceModel\AbstractDb;

class AdminUser extends AbstractDb
{
    protected function _construct(): void
    {
        $this->init('admin_user', 'user_id');
    }

    /**
     * Every new password hash goes to admin_passwords (Magento's history
     * table), whatever path set it: admin form, "change password" screen or
     * bin/create-admin.php.
     */
    protected function afterSave(AbstractModel $object): void
    {
        $hash = (string) $object->getData('password');
        if ($hash === '' || ($this->getPasswordHistory((int) $object->getId(), 1)[0] ?? null) === $hash) {
            return;
        }

        // ponytail: "expires" stays 0; expiry is last_updated + the current config, so changing it applies to everyone
        $this->getConnection()->insert('admin_passwords', ['user_id' => $object->getId(), 'password_hash' => $hash, 'last_updated' => time()]);
    }

    /**
     * @return array<int, string> latest hashes first
     */
    public function getPasswordHistory(int $userId, int $limit): array
    {
        $db = $this->getConnection();

        return $db->fetchCol($db->select()->from('admin_passwords', ['password_hash'])
            ->where('user_id = ?', $userId)->order('password_id DESC')->limit($limit));
    }

    /** @return array<int, int> active users with this e-mail (it isn't unique) */
    public function getActiveIdsByEmail(string $email): array
    {
        $db = $this->getConnection();

        return array_map('intval', $db->fetchCol($db->select()->from('admin_user', ['user_id'])
            ->where('LOWER(email) = LOWER(?)', $email)->where('is_active = 1')));
    }

    /** Unix time of the latest password change, null when there is no history. */
    public function getPasswordChangedAt(int $userId): ?int
    {
        $db = $this->getConnection();
        $changedAt = $db->fetchOne($db->select()->from('admin_passwords', ['MAX(last_updated)'])->where('user_id = ?', $userId));

        return $changedAt === null ? null : (int) $changedAt;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getList(?string $orderBy = null): array
    {
        $db = $this->getConnection();

        return $db->fetchAll($db->select()
            ->from(['u' => 'admin_user'], ['user_id', 'firstname', 'lastname', 'email', 'username', 'is_active', 'created'])
            ->joinLeft(['r' => 'admin_role'], 'r.role_id = u.role_id', ['role_name'])
            ->order('u.user_id'));
    }

    /**
     * Dashboard numbers: user totals, who is locked right now and the latest logins.
     */
    public function getSummary(int $recentLimit = 5): array
    {
        $db = $this->getConnection();

        $counts = $db->fetchRow($db->select()->from('admin_user', [
            'active' => 'COUNT(*) FILTER (WHERE is_active = 1)',
            'inactive' => 'COUNT(*) FILTER (WHERE is_active <> 1)',
        ]));
        $locked = $db->fetchAll($db->select()->from('admin_user', ['username', 'lock_expires'])
            ->where('lock_expires > NOW()')->order('lock_expires'));
        $recent = $db->fetchAll($db->select()->from('admin_user', ['username', 'logdate', 'lognum'])
            ->where('logdate IS NOT NULL')->order('logdate DESC')->limit($recentLimit));

        return [
            'active' => (int) $counts['active'],
            'inactive' => (int) $counts['inactive'],
            'locked' => $locked,
            'recent' => $recent,
        ];
    }
}
