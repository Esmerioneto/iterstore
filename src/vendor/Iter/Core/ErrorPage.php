<?php

declare(strict_types=1);

namespace Iter\Core;

/**
 * Minimal standalone error page (403/404/5xx). Deliberately dependency-free
 * (no Layout render) since the failure itself may be a broken DB connection.
 */
class ErrorPage
{
    /**
     * @param array<string, string> $links label => url
     */
    public static function render(int $code, string $title, string $message, array $links = []): void
    {
        http_response_code($code);

        $linksHtml = implode(' · ', array_map(
            static fn (string $label, string $url) => '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '">'
                . htmlspecialchars($label, ENT_QUOTES) . '</a>',
            array_keys($links),
            $links
        ));

        echo '<!doctype html><html lang="pt-br"><meta charset="utf-8">'
            . '<title>' . htmlspecialchars($title, ENT_QUOTES) . '</title>'
            . '<body style="font-family:sans-serif;max-width:600px;margin:80px auto;text-align:center;color:#333">'
            . '<h1 style="font-size:22px">' . htmlspecialchars($title, ENT_QUOTES) . '</h1>'
            . '<p>' . htmlspecialchars($message, ENT_QUOTES) . '</p>'
            . ($linksHtml !== '' ? '<p>' . $linksHtml . '</p>' : '')
            . '</body></html>';
    }
}
