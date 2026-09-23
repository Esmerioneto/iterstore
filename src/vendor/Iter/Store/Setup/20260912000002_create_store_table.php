<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        // ponytail: shared por qualquer tabela que precise de updated_at automatico;
        // reexecutar CREATE OR REPLACE em novas migrations e seguro (idempotente).
        $pdo->exec(
            'CREATE OR REPLACE FUNCTION set_updated_at() RETURNS TRIGGER AS $$
            BEGIN
                NEW.updated_at = NOW();
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS store (
                id SERIAL PRIMARY KEY,
                store_name VARCHAR(255) NOT NULL,
                webview VARCHAR(255) NOT NULL,
                theme VARCHAR(255) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW()
            )'
        );

        $pdo->exec(
            'CREATE OR REPLACE TRIGGER store_set_updated_at
                BEFORE UPDATE ON store
                FOR EACH ROW
                EXECUTE FUNCTION set_updated_at()'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS store');
        $pdo->exec('DROP FUNCTION IF EXISTS set_updated_at()');
    }
};
