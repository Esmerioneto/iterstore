<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS admin_user (
                user_id SERIAL PRIMARY KEY,
                firstname VARCHAR(32),
                lastname VARCHAR(32),
                email VARCHAR(128),
                username VARCHAR(40),
                password VARCHAR(255) NOT NULL,
                created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                modified TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                logdate TIMESTAMP,
                lognum SMALLINT NOT NULL DEFAULT 0,
                reload_acl_flag SMALLINT NOT NULL DEFAULT 0,
                is_active SMALLINT NOT NULL DEFAULT 1,
                extra TEXT,
                rp_token TEXT,
                rp_token_created_at TIMESTAMP,
                interface_locale VARCHAR(16) NOT NULL DEFAULT \'en_US\',
                failures_num SMALLINT DEFAULT 0,
                first_failure TIMESTAMP,
                lock_expires TIMESTAMP,
                CONSTRAINT admin_user_username UNIQUE (username)
            )'
        );

        // ponytail: "modified" (not "updated_at") so it needs its own trigger
        // function instead of the shared set_updated_at() from the Store migration.
        $pdo->exec(
            'CREATE OR REPLACE FUNCTION set_admin_user_modified() RETURNS TRIGGER AS $$
            BEGIN
                NEW.modified = NOW();
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql'
        );

        $pdo->exec(
            'CREATE OR REPLACE TRIGGER admin_user_set_modified
                BEFORE UPDATE ON admin_user
                FOR EACH ROW
                EXECUTE FUNCTION set_admin_user_modified()'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS admin_user');
        $pdo->exec('DROP FUNCTION IF EXISTS set_admin_user_modified()');
    }
};
