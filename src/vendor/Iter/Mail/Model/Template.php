<?php

declare(strict_types=1);

namespace Iter\Mail\Model;

use Iter\Core\Db\Connection;
use PDO;

/**
 * E-mail templates. Each module declares its own in etc/email_templates.json
 * ({code, label, subject, file, vars: {name: example}, private}) with the body
 * in view/email/<file>.html (and optionally .txt). The admin can override
 * subject and body (mail_template); "restore" deletes the override.
 *
 * Bodies use {{ var }} (HTML-escaped) and {{{ var }}} (as is, trusted values
 * only). No PHP, so a template edited in the admin can't run code.
 * `_layout` wraps every HTML body through {{{ content }}}.
 */
class Template
{
    public const LAYOUT = '_layout';

    private static ?array $registry = null;

    /** @return array<string, array> code => definition, with 'dir' (the module's view/email/) */
    public static function all(): array
    {
        if (self::$registry === null) {
            self::$registry = [];
            foreach (\getModules() as $group) {
                foreach ($group as $moduleKey => $enabled) {
                    $moduleDir = ucfirst(\getModuleVendor($moduleKey)) . DIRECTORY_SEPARATOR . ucfirst(\getModuleName($moduleKey)) . DIRECTORY_SEPARATOR;
                    foreach ($enabled == 1 ? [VENDOR, CODE] : [] as $base) {
                        $file = $base . $moduleDir . 'etc' . DIRECTORY_SEPARATOR . 'email_templates.json';
                        foreach (is_file($file) ? json_decode((string) file_get_contents($file), true) ?? [] : [] as $def) {
                            self::$registry[$def['code']] = $def + ['dir' => $base . $moduleDir . 'view' . DIRECTORY_SEPARATOR . 'email' . DIRECTORY_SEPARATOR, 'vars' => [], 'private' => false];
                        }
                    }
                }
            }
        }

        return self::$registry;
    }

    /**
     * The template as used for sending: the admin's override, or the module's file.
     *
     * @return array{code: string, label: string, subject: string, body_html: string, body_text: ?string, vars: array, private: bool, is_custom: bool}
     */
    public static function get(string $code): array
    {
        $def = self::all()[$code] ?? throw new MailException('TEMPLATE_UNKNOWN', "Modelo de e-mail desconhecido: {$code}.");
        $stmt = self::pdo()->prepare('SELECT subject, body_html, body_text FROM mail_template WHERE code = :code');
        $stmt->execute(['code' => $code]);
        $custom = $stmt->fetch(PDO::FETCH_ASSOC);

        return ['code' => $code, 'label' => $def['label'], 'vars' => $def['vars'], 'private' => (bool) $def['private'], 'is_custom' => $custom !== false]
            + ($custom ?: self::fileVersion($code));
    }

    /** @return array{subject: string, body_html: string, body_text: ?string} */
    public static function fileVersion(string $code): array
    {
        $def = self::all()[$code] ?? throw new MailException('TEMPLATE_UNKNOWN', "Modelo de e-mail desconhecido: {$code}.");
        $txt = $def['dir'] . $def['file'] . '.txt';

        return [
            'subject' => $def['subject'],
            'body_html' => (string) file_get_contents($def['dir'] . $def['file'] . '.html'),
            'body_text' => is_file($txt) ? (string) file_get_contents($txt) : null,
        ];
    }

    /**
     * @param array{subject: string, body_html: string, body_text: ?string} $template
     * @return array{subject: string, html: string, text: string} ready to send
     */
    public static function render(array $template, array $vars): array
    {
        $html = self::fill($template['body_html'], $vars, true);
        $layout = self::get(self::LAYOUT);

        return [
            'subject' => str_replace(["\r", "\n"], ' ', self::fill($template['subject'], $vars, false)),
            'html' => self::fill($layout['body_html'], ['content' => $html] + $vars, true),
            'text' => $template['body_text'] !== null && trim($template['body_text']) !== '' ? self::fill($template['body_text'], $vars, false) : self::toText($html),
        ];
    }

    public static function save(string $code, string $subject, string $html, ?string $text, ?int $userId): void
    {
        self::get($code); // unknown code -> MailException
        if (trim($subject) === '' || trim($html) === '') {
            throw new MailException('TEMPLATE_INVALID', 'Assunto e corpo HTML são obrigatórios.');
        }
        self::pdo()->prepare(
            'INSERT INTO mail_template (code, subject, body_html, body_text, updated_by) VALUES (:code, :subject, :html, :text, :user)
             ON CONFLICT (code) DO UPDATE SET subject = EXCLUDED.subject, body_html = EXCLUDED.body_html, body_text = EXCLUDED.body_text, updated_by = EXCLUDED.updated_by'
        )->execute(['code' => $code, 'subject' => $subject, 'html' => $html, 'text' => trim((string) $text) === '' ? null : $text, 'user' => $userId]);
    }

    public static function restore(string $code): void
    {
        self::pdo()->prepare('DELETE FROM mail_template WHERE code = :code')->execute(['code' => $code]);
    }

    /** {{{ raw }}} first, then {{ escaped }}; unknown variables become empty */
    private static function fill(string $body, array $vars, bool $html): string
    {
        $value = static fn (array $m) => is_scalar($vars[$m[1]] ?? null) ? (string) $vars[$m[1]] : '';
        $body = preg_replace_callback('/\{\{\{\s*([a-z0-9_]+)\s*\}\}\}/i', $value, $body);

        return preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', static fn (array $m) => $html ? htmlspecialchars($value($m)) : $value($m), $body);
    }

    /** Plain-text version of an HTML body: links as "text (url)", blocks as lines */
    private static function toText(string $html): string
    {
        $html = preg_replace_callback('#<a\s[^>]*href="([^"]*)"[^>]*>(.*?)</a>#is', static fn (array $m) => trim(strip_tags($m[2])) === html_entity_decode($m[1]) ? $m[1] : "{$m[2]} ({$m[1]})", $html);
        $html = preg_replace('#<(br|/p|/div|/h[1-6]|/li|/tr)\b[^>]*>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace(["/[ \t]+/", "/ *\n */", "/\n{3,}/"], [' ', "\n", "\n\n"], $text));
    }

    private static function pdo(): PDO
    {
        return Connection::get()->connect();
    }
}
