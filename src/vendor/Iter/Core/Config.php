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

    /**
     * @return array<string, array> field definitions keyed by path; a path repeated in another module adds to the
     *         field (a provider module adds its "options" to the select of the module it plugs into)
     */
    public static function fields(): array
    {
        if (self::$fields === null) {
            self::$fields = [];
            foreach (\getModuleJson('etc' . DIRECTORY_SEPARATOR . 'config_fields.json') as $field) {
                self::$fields[$field['path']] = array_replace_recursive(self::$fields[$field['path']] ?? [], $field);
            }
        }

        return self::$fields;
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

    /** Configurações → Geral → Endereço do site (web/base_url), without the trailing "/": the start of every link sent out */
    public static function baseUrl(?int $websiteId = null): string
    {
        return rtrim(trim((string) self::get('web/base_url', $websiteId)), '/');
    }

    /**
     * Configurações → Geral → Endereços da loja web (web/storefront_urls): the one the calling store names in X-Store-Url
     * when it is listed, else the first listed, else Endereço do site. Only listed addresses: a caller can't point the
     * e-mailed links (with their tokens) at a site of its own.
     */
    public static function storefrontUrl(?int $websiteId = null): string
    {
        $listed = self::storefrontUrls($websiteId);
        $asked = rtrim(trim((string) ($_SERVER['HTTP_X_STORE_URL'] ?? '')), '/');
        foreach ($listed as $url) {
            if (strcasecmp($url, $asked) === 0) {
                return $url;
            }
        }

        return $listed[0] ?? self::baseUrl($websiteId);
    }

    /**
     * A link to the store for an e-mail: a setting starting with "/" goes after storefrontUrl(), a full one stays;
     * {token} becomes $token. Empty setting: $fallback ("/…" too), or just the token when there's none.
     */
    public static function storefrontLink(string $setting, string $token, ?int $websiteId = null, string $fallback = ''): string
    {
        $link = trim($setting) !== '' && str_contains($setting, '{token}') ? trim($setting) : $fallback;
        if ($link === '') {
            return $token;
        }

        return str_replace('{token}', $token, str_starts_with($link, '/') && !str_starts_with($link, '//') ? self::storefrontUrl($websiteId) . $link : $link);
    }

    /** @return string[] web/storefront_urls, valid lines only, without the trailing "/" */
    public static function storefrontUrls(?int $websiteId = null): array
    {
        $lines = preg_split('/\s+/', trim((string) self::get('web/storefront_urls', $websiteId)), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter(array_map(static fn ($u) => rtrim($u, '/'), $lines), [self::class, 'isOrigin']));
    }

    /** http(s)://host[:port], nothing after it */
    public static function isOrigin(string $url): bool
    {
        return (bool) preg_match('#^https?://[a-z0-9.-]+(:\d{1,5})?$|^https?://\[[0-9a-f:.]+\](:\d{1,5})?$#i', $url);
    }

    /** Configurações → Geral: every line of "Endereços da loja web" is http(s)://host[:port] */
    public static function validateStorefrontUrls(callable $get): array
    {
        foreach (preg_split('/\s+/', trim((string) $get('web/storefront_urls')), -1, PREG_SPLIT_NO_EMPTY) as $url) {
            if (!self::isOrigin(rtrim($url, '/'))) {
                return ["Endereços da loja web: \"{$url}\" não é um endereço. Use só o início, ex.: https://loja.com.br ou http://192.168.1.22:8081 (sem caminho)."];
            }
        }

        return [];
    }

    /**
     * The address this request came in on (scheme://host[:port]), for absolute URLs in API answers: it follows the
     * domain the client called, so moving to another domain (dev → production) needs no setting. Behind a proxy in
     * "Proxies confiáveis", its X-Forwarded-Host and X-Forwarded-Proto count. Outside a request (cron, CLI) or with
     * a malformed Host, Endereço do site (baseUrl). E-mails and other links sent out keep using baseUrl.
     */
    public static function requestBaseUrl(?int $websiteId = null): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $forwarded = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''))[0]);
        if ($forwarded !== '' && RateLimit::fromTrustedProxy()) {
            $host = $forwarded;
        }
        $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
        if (!preg_match('/^(?:' . $label . '(?:\.' . $label . ')*|\[[0-9a-f:.]+\])(?::\d{1,5})?$/i', $host)) {
            return self::baseUrl($websiteId);
        }

        return (RateLimit::https() ? 'https' : 'http') . '://' . strtolower($host);
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
