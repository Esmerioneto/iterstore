<?php

declare(strict_types=1);

namespace Iter\Core;

/**
 * System log: one line per message in var/log/iter-YYYY-MM-DD.log,
 * written only when Configurações → "Gerar log do sistema" is on.
 * Never log passwords, tokens or e-mail contents.
 */
class Log
{
    public const PATTERN = '/^iter-(\d{4}-\d{2}-\d{2})\.log$/';

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    public static function notify(string $message, array $context = []): void
    {
        self::write('NOTIFY', $message, $context);
    }

    public static function alert(string $message, array $context = []): void
    {
        self::write('ALERT', $message, $context);
    }

    public static function dir(): string
    {
        // ITER_LOG_DIR: another folder, used by the selftest so it doesn't write in the real log
        return getenv('ITER_LOG_DIR') ?: dirname(SRC) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR;
    }

    public static function file(?int $time = null): string
    {
        return self::dir() . 'iter-' . date('Y-m-d', $time ?? time()) . '.log';
    }

    /**
     * Deletes the log files older than $days days (by the date in the name).
     * 0 keeps them all. @return string[] the deleted file names
     */
    public static function clean(int $days, ?int $now = null): array
    {
        if ($days < 1 || !is_dir(self::dir())) {
            return [];
        }
        $limit = date('Y-m-d', strtotime("-{$days} days", $now ?? time()));
        $deleted = [];
        foreach (scandir(self::dir()) as $name) {
            if (preg_match(self::PATTERN, $name, $m) && $m[1] < $limit && unlink(self::dir() . $name)) {
                $deleted[] = $name;
            }
        }

        return $deleted;
    }

    private static function write(string $type, string $message, array $context): void
    {
        // the log must never break the request: a DB/disk failure here falls back to PHP's error_log
        try {
            if (!Config::get('system/log/enabled')) {
                return;
            }
            $context += array_filter([
                'user' => \Iter\Admin\Auth::user()?->getData('username'),
                'url' => $_SERVER['REQUEST_URI'] ?? null,
            ]);
            $line = '[' . date('Y-m-d H:i:s') . "] {$type}: " . addcslashes($message, "\r\n\\")
                . ($context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) : '') . "\n";
            if (!is_dir(self::dir()) && !@mkdir(self::dir(), 0775, true) && !is_dir(self::dir())) {
                throw new \RuntimeException('não foi possível criar ' . self::dir());
            }
            if (@file_put_contents(self::file(), $line, FILE_APPEND | LOCK_EX) === false) {
                throw new \RuntimeException('não foi possível gravar ' . self::file());
            }
        } catch (\Throwable $e) {
            error_log("Log ({$e->getMessage()}): {$type}: {$message}");
        }
    }
}
