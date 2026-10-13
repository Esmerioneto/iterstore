<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/** One conversation per customer with the store (MESSAGE-PLAN §2). */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS customer_message (
            message_id SERIAL PRIMARY KEY,
            website_id SMALLINT NOT NULL REFERENCES store_website (website_id) ON DELETE CASCADE,
            customer_id INT NOT NULL REFERENCES customer_entity (customer_id) ON DELETE CASCADE,
            sender VARCHAR(8) NOT NULL CHECK (sender IN ('customer','admin')),
            admin_user_id INT NULL REFERENCES admin_user (user_id) ON DELETE SET NULL,
            body TEXT NOT NULL CHECK (char_length(body) BETWEEN 1 AND 2000),
            read_at TIMESTAMP NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW()
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS customer_message_thread ON customer_message (customer_id, created_at)');
        // the admin's unread counter
        $pdo->exec("CREATE INDEX IF NOT EXISTS customer_message_unread_admin ON customer_message (website_id) WHERE read_at IS NULL AND sender = 'customer'");
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS customer_message');
    }
};
