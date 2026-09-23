<?php

declare(strict_types=1);

namespace Iter\Core\Setup;

use PDO;
use Iter\Core\Db\Connection;
use RuntimeException;
use Throwable;

class MigrationRunner
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::get()->connect();
        $this->ensureMigrationsTable();
    }

    public function up(): void
    {
        foreach ($this->discoverMigrations() as $module => $files) {
            foreach ($files as $name => $path) {
                if ($this->isApplied($module, $name)) {
                    continue;
                }
                $this->run($module, $name, $path, 'up');
            }
        }
    }

    public function down(): void
    {
        $last = $this->lastApplied();
        if ($last === null) {
            echo "Nada para reverter.\n";
            return;
        }

        [$module, $name] = $last;
        $path = $this->discoverMigrations()[$module][$name] ?? null;
        if ($path === null) {
            echo "Arquivo de migration nao encontrado para {$module}/{$name}\n";
            return;
        }

        $this->run($module, $name, $path, 'down');
    }

    private function run(string $module, string $name, string $path, string $direction): void
    {
        $migration = require $path;
        if (!$migration instanceof MigrationInterface) {
            throw new RuntimeException("Migration invalida: {$module}/{$name}");
        }

        $this->pdo->beginTransaction();
        try {
            $migration->{$direction}($this->pdo);
            $direction === 'up'
                ? $this->markApplied($module, $name)
                : $this->unmarkApplied($module, $name);
            $this->pdo->commit();
            echo strtoupper($direction) . " {$module}/{$name}\n";
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @return array<string, array<string, string>> module => [migrationFile => fullPath]
     */
    private function discoverMigrations(): array
    {
        $result = [];
        foreach (getModules() as $group) {
            foreach ($group as $moduleKey => $enabled) {
                if ($enabled != 1) {
                    continue;
                }

                $vendor = getModuleVendor($moduleKey);
                $name = getModuleName($moduleKey);
                $moduleLabel = ucfirst($vendor) . '_' . ucfirst($name);

                foreach ([getVendorPath(), getCodePath()] as $basePath) {
                    $setupDir = $basePath . ucfirst($vendor) . DIRECTORY_SEPARATOR .
                        ucfirst($name) . DIRECTORY_SEPARATOR . 'Setup';

                    if (!is_dir($setupDir)) {
                        continue;
                    }

                    // only files following the "<timestamp>_description.php" migration
                    // naming convention — Iter/Core/Setup also holds the runner's own
                    // infrastructure classes (MigrationInterface.php, MigrationRunner.php)
                    $files = glob($setupDir . DIRECTORY_SEPARATOR . '[0-9]*_*.php');
                    sort($files);

                    foreach ($files as $file) {
                        $result[$moduleLabel][basename($file)] = $file;
                    }
                }
            }
        }

        return $result;
    }

    private function ensureMigrationsTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id SERIAL PRIMARY KEY,
                module VARCHAR(255) NOT NULL,
                migration VARCHAR(255) NOT NULL,
                applied_at TIMESTAMP NOT NULL DEFAULT NOW(),
                UNIQUE(module, migration)
            )'
        );
    }

    private function isApplied(string $module, string $migration): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM migrations WHERE module = :module AND migration = :migration'
        );
        $stmt->execute(['module' => $module, 'migration' => $migration]);

        return (bool) $stmt->fetchColumn();
    }

    private function markApplied(string $module, string $migration): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO migrations (module, migration) VALUES (:module, :migration)'
        );
        $stmt->execute(['module' => $module, 'migration' => $migration]);
    }

    private function unmarkApplied(string $module, string $migration): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM migrations WHERE module = :module AND migration = :migration'
        );
        $stmt->execute(['module' => $module, 'migration' => $migration]);
    }

    /**
     * @return array{0: string, 1: string}|null [module, migration]
     */
    private function lastApplied(): ?array
    {
        $stmt = $this->pdo->query('SELECT module, migration FROM migrations ORDER BY id DESC LIMIT 1');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? [$row['module'], $row['migration']] : null;
    }
}
