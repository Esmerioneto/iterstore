<?php

declare(strict_types=1);

namespace Iter\Core;

use Iter\Core\Db\Connection;

/**
 * System settings (Magento's core_config_data). Modules declare fields in
 * etc/config_fields.json; get() returns the saved value or the field default.
 */
class Config
{
    // ponytail: only the global scope ("default", 0); add website/store scopes when a setting needs them
    private static ?array $values = null;
    private static ?array $fields = null;

    /** @return array<string, array> field definitions keyed by path */
    public static function fields(): array
    {
        return self::$fields ??= array_column(\getModuleJson('etc' . DIRECTORY_SEPARATOR . 'config_fields.json'), null, 'path');
    }

    public static function get(string $path): mixed
    {
        self::$values ??= Connection::get()->connect()
            ->query("SELECT path, value FROM core_config_data WHERE scope = 'default' AND scope_id = 0")
            ->fetchAll(\PDO::FETCH_KEY_PAIR);

        return self::$values[$path] ?? self::fields()[$path]['default'] ?? null;
    }

    /** @param array<string, string> $values path => value */
    public static function save(array $values): void
    {
        $stmt = Connection::get()->connect()->prepare(
            "INSERT INTO core_config_data (scope, scope_id, path, value) VALUES ('default', 0, :path, :value)
             ON CONFLICT (scope, scope_id, path) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW()"
        );
        foreach ($values as $path => $value) {
            $stmt->execute(['path' => $path, 'value' => $value]);
        }
        self::$values = null;
    }
}
