<?php

declare(strict_types=1);

namespace Iter\Core;

use Iter\Core\Db\Connection;

/**
 * System settings (Magento's core_config_data). Modules declare fields in
 * etc/config_fields.json; get() returns the saved value or the field default.
 * Fields with "scope": "website" can also have a value per website, which
 * wins over the default one.
 */
class Config
{
    public const SCOPE_WEBSITES = 'websites';
    /** "type": "image" fields: accepted uploads (mime => extension), saved in public/media/config */
    public const IMAGE_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    public const IMAGE_MAX_BYTES = 2 * 1024 * 1024;

    /** @var array<string, array<string, string>>|null "scope:id" => path => value */
    private static ?array $values = null;
    private static ?array $fields = null;

    /** @return array<string, array> field definitions keyed by path */
    public static function fields(): array
    {
        return self::$fields ??= array_column(\getModuleJson('etc' . DIRECTORY_SEPARATOR . 'config_fields.json'), null, 'path');
    }

    public static function get(string $path, ?int $websiteId = null): mixed
    {
        return self::websiteValue($path, $websiteId)
            ?? self::values()['default:0'][$path]
            ?? self::fields()[$path]['default']
            ?? null;
    }

    /** The website's own value, null when it uses the default one */
    public static function websiteValue(string $path, ?int $websiteId): ?string
    {
        return $websiteId ? self::values()[self::SCOPE_WEBSITES . ':' . $websiteId][$path] ?? null : null;
    }

    /** @param array<string, string> $values path => value */
    public static function save(array $values, ?int $websiteId = null): void
    {
        $stmt = Connection::get()->connect()->prepare(
            "INSERT INTO core_config_data (scope, scope_id, path, value) VALUES (:scope, :scope_id, :path, :value)
             ON CONFLICT (scope, scope_id, path) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW()"
        );
        foreach ($values as $path => $value) {
            $stmt->execute(['path' => $path, 'value' => $value] + self::scope($websiteId));
        }
        self::$values = null;
    }

    /** Back to the default value: removes the website's own values */
    public static function clear(array $paths, int $websiteId): void
    {
        $stmt = Connection::get()->connect()->prepare('DELETE FROM core_config_data WHERE scope = :scope AND scope_id = :scope_id AND path = :path');
        foreach ($paths as $path) {
            $stmt->execute(['path' => $path] + self::scope($websiteId));
        }
        self::$values = null;
    }

    /** Public URL of an image setting's value ('' when there's none) */
    public static function mediaUrl(?string $value): string
    {
        return (string) $value === '' ? '' : '/media/' . $value;
    }

    public static function mediaDir(): string
    {
        return \getPath('public') . 'media' . DIRECTORY_SEPARATOR;
    }

    public static function websiteExists(int $websiteId): bool
    {
        if ($websiteId < 1 || $websiteId > 32767) { // store_website.website_id is a smallint
            return false;
        }
        $stmt = Connection::get()->connect()->prepare('SELECT 1 FROM store_website WHERE website_id = :id');
        $stmt->execute(['id' => $websiteId]);

        return (bool) $stmt->fetchColumn();
    }

    private static function scope(?int $websiteId): array
    {
        return $websiteId ? ['scope' => self::SCOPE_WEBSITES, 'scope_id' => $websiteId] : ['scope' => 'default', 'scope_id' => 0];
    }

    private static function values(): array
    {
        if (self::$values === null) {
            self::$values = [];
            foreach (Connection::get()->connect()->query('SELECT scope, scope_id, path, value FROM core_config_data') as $row) {
                self::$values[$row['scope'] . ':' . $row['scope_id']][$row['path']] = $row['value'];
            }
        }

        return self::$values;
    }
}
