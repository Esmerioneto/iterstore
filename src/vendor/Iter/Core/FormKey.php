<?php

declare(strict_types=1);

namespace Iter\Core;

/**
 * Per-session CSRF token (Magento's "form_key"). Forms print field(); the
 * router rejects admin POSTs whose form_key doesn't match.
 */
class FormKey
{
    private const SESSION_KEY = 'iter_form_key';

    public static function get(): string
    {
        return $_SESSION[self::SESSION_KEY] ??= bin2hex(random_bytes(32));
    }

    public static function field(): string
    {
        return '<input type="hidden" name="form_key" value="' . self::get() . '">';
    }

    public static function isValid(mixed $key): bool
    {
        return is_string($key) && !empty($_SESSION[self::SESSION_KEY])
            && hash_equals($_SESSION[self::SESSION_KEY], $key);
    }
}
