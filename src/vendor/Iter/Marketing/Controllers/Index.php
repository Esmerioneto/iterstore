<?php

declare(strict_types=1);

namespace Iter\Marketing\Controllers;

use Iter\Marketing\Model\Unsubscribe;

/**
 * GET /marketing/unsubscribe?t=…: the "Não quero mais receber" link of the marketing e-mails. It only asks;
 * the POST unsubscribes, so a mail scanner that opens every link doesn't unsubscribe anyone.
 */
class Index
{
    public function Unsubscribe(): void
    {
        $token = (string) ($_GET['t'] ?? '');
        if (Unsubscribe::parse($token) === null) {
            self::page(400, 'Link inválido', '<p>Este link não é válido. Use o link do e-mail que você recebeu.</p>');
            return;
        }
        self::page(200, 'Não quero mais receber e-mails', '<p>Você não vai mais receber e-mails de ofertas e lembretes desta loja. E-mails do seu pedido continuam chegando.</p>'
            . '<form method="post" action="/marketing/unsubscribepost"><input type="hidden" name="t" value="' . htmlspecialchars($token, ENT_QUOTES) . '">'
            . '<button type="submit" style="font-size:16px;padding:10px 20px;cursor:pointer">Confirmar</button></form>');
    }

    public function Unsubscribepost(): void
    {
        $parsed = Unsubscribe::parse((string) ($_POST['t'] ?? $_GET['t'] ?? '')); // ?t=: the one-click List-Unsubscribe-Post
        if ($parsed === null) {
            self::page(400, 'Link inválido', '<p>Este link não é válido. Use o link do e-mail que você recebeu.</p>');
            return;
        }
        Unsubscribe::apply($parsed['email'], $parsed['website_id']);
        self::page(200, 'Pronto', '<p>' . htmlspecialchars($parsed['email'], ENT_QUOTES) . ' não vai mais receber nossos e-mails de ofertas e lembretes.</p>');
    }

    /** $html is built here only, with the user's data escaped */
    private static function page(int $code, string $title, string $html): void
    {
        http_response_code($code);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer'); // the token is in the URL
        echo '<!doctype html><html lang="pt-br"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex"><title>' . htmlspecialchars($title, ENT_QUOTES) . '</title>'
            . '<body style="font-family:sans-serif;max-width:600px;margin:80px auto;padding:0 16px;text-align:center;color:#333">'
            . '<h1 style="font-size:22px">' . htmlspecialchars($title, ENT_QUOTES) . '</h1>' . $html . '</body></html>';
    }
}
