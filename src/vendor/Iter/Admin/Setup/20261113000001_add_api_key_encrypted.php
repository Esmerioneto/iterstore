<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

// Sistema → Chaves da API → "Ver chave": the key is also kept encrypted (Crypt, env.ini key), so the
// Administrador can copy it again. The lookup still goes by key_hash; keys made before this have no copy.
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE api_key ADD COLUMN IF NOT EXISTS key_encrypted TEXT NULL');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE api_key DROP COLUMN IF EXISTS key_encrypted');
    }
};
