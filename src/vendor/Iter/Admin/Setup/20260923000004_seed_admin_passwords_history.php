<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * Users created before password history existed get their current password
 * as the first entry, counting its lifetime from now instead of expiring
 * everyone at once.
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'INSERT INTO admin_passwords (user_id, password_hash, last_updated)
             SELECT u.user_id, u.password, EXTRACT(EPOCH FROM NOW())::INTEGER
             FROM admin_user u
             WHERE NOT EXISTS (SELECT 1 FROM admin_passwords p WHERE p.user_id = u.user_id)'
        );
    }

    public function down(PDO $pdo): void
    {
        // history rows are indistinguishable from real ones once passwords change; nothing to undo
    }
};
