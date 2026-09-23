<?php

declare(strict_types=1);

namespace Iter\Admin\Model\ResourceModel;

use Iter\Core\Model\AbstractModel;
use Iter\Core\Model\ResourceModel\AbstractDb;
use PDO;

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
        $this->getConnection()
            ->prepare('INSERT INTO admin_passwords (user_id, password_hash, last_updated) VALUES (:user_id, :hash, :now)')
            ->execute(['user_id' => $object->getId(), 'hash' => $hash, 'now' => time()]);
    }

    /**
     * @return array<int, string> latest hashes first
     */
    public function getPasswordHistory(int $userId, int $limit): array
    {
        $stmt = $this->getConnection()->prepare(
            'SELECT password_hash FROM admin_passwords WHERE user_id = :user_id ORDER BY password_id DESC LIMIT :limit'
        );
        $stmt->bindValue('user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return array<int, int> active users with this e-mail (it isn't unique) */
    public function getActiveIdsByEmail(string $email): array
    {
        $stmt = $this->getConnection()->prepare('SELECT user_id FROM admin_user WHERE LOWER(email) = LOWER(:email) AND is_active = 1');
        $stmt->execute(['email' => $email]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Unix time of the latest password change, null when there is no history. */
    public function getPasswordChangedAt(int $userId): ?int
    {
        $stmt = $this->getConnection()->prepare('SELECT MAX(last_updated) FROM admin_passwords WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
        $changedAt = $stmt->fetchColumn();

        return $changedAt === null ? null : (int) $changedAt;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getList(?string $orderBy = null): array
    {
        $stmt = $this->getConnection()->query(
            'SELECT u.user_id, u.firstname, u.lastname, u.email, u.username, u.is_active, u.created, r.role_name
             FROM admin_user u
             LEFT JOIN admin_role r ON r.role_id = u.role_id
             ORDER BY u.user_id'
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Dashboard numbers: user totals, who is locked right now and the latest logins.
     */
    public function getSummary(int $recentLimit = 5): array
    {
        $pdo = $this->getConnection();

        $counts = $pdo->query(
            'SELECT COUNT(*) FILTER (WHERE is_active = 1) AS active,
                    COUNT(*) FILTER (WHERE is_active <> 1) AS inactive
             FROM admin_user'
        )->fetch(PDO::FETCH_ASSOC);

        $locked = $pdo->query(
            'SELECT username, lock_expires FROM admin_user WHERE lock_expires > NOW() ORDER BY lock_expires'
        )->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare(
            'SELECT username, logdate, lognum FROM admin_user
             WHERE logdate IS NOT NULL ORDER BY logdate DESC LIMIT :limit'
        );
        $stmt->bindValue('limit', $recentLimit, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'active' => (int) $counts['active'],
            'inactive' => (int) $counts['inactive'],
            'locked' => $locked,
            'recent' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        ];
    }
}
