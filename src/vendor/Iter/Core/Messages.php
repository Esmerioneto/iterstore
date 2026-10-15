<?php

declare(strict_types=1);

namespace Iter\Core;

/**
 * Flash messages persisted in the PHP session, so they survive a redirect
 * (Post/Redirect/Get). pull() reads and clears them in the same call.
 */
class Messages
{
    private const SESSION_KEY = 'iter_messages';

    public static function addSuccess(string $text): void
    {
        self::add('success', $text);
    }

    public static function addError(string $text): void
    {
        self::add('error', $text);
    }

    private static function add(string $type, string $text): void
    {
        $_SESSION[self::SESSION_KEY][] = ['type' => $type, 'text' => $text];
    }

    /**
     * @return array<int, array{type: string, text: string}>
     */
    public static function pull(): array
    {
        $messages = $_SESSION[self::SESSION_KEY] ?? [];
        $_SESSION[self::SESSION_KEY] = [];

        return $messages;
    }
}
