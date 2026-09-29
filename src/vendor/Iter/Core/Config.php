<?php

declare(strict_types=1);

namespace Iter\Core;

use Iter\Core\Db\Connection;
use Iter\Core\Db\Expr;

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

    /** the largest upload PHP accepts (upload_max_filesize, capped by post_max_size); admin.js shrinks bigger images */
    public static function uploadMaxBytes(): int
    {
        $limits = array_filter([ini_parse_quantity((string) ini_get('upload_max_filesize')), ini_parse_quantity((string) ini_get('post_max_size'))]); // 0 = no limit

        return $limits ? min($limits) : PHP_INT_MAX;
    }

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
        Connection::get()->adapter()->transaction(static function ($db) use ($values, $websiteId): void {
            foreach ($values as $path => $value) {
                $db->insertOnConflict('core_config_data', self::scope($websiteId) + ['path' => $path, 'value' => $value], ['scope', 'scope_id', 'path'], ['value', 'updated_at' => new Expr('NOW()')]);
            }
        });
        self::$values = null;
    }

    /** Back to the default value: removes the website's own values */
    public static function clear(array $paths, int $websiteId): void
    {
        $scope = self::scope($websiteId);
        Connection::get()->adapter()->delete('core_config_data', ['scope = ?' => $scope['scope'], 'scope_id = ?' => $scope['scope_id'], 'path IN (?)' => $paths]);
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
        $db = Connection::get()->adapter();

        return (bool) $db->fetchOne($db->select()->from('store_website', [new Expr('1')])->where('website_id = ?', $websiteId));
    }

    private static function scope(?int $websiteId): array
    {
        return $websiteId ? ['scope' => self::SCOPE_WEBSITES, 'scope_id' => $websiteId] : ['scope' => 'default', 'scope_id' => 0];
    }

    private static function values(): array
    {
        if (self::$values === null) {
            self::$values = [];
            $db = Connection::get()->adapter();
            foreach ($db->fetchAll($db->select()->from('core_config_data', ['scope', 'scope_id', 'path', 'value'])) as $row) {
                self::$values[$row['scope'] . ':' . $row['scope_id']][$row['path']] = $row['value'];
            }
        }

        return self::$values;
    }
}
