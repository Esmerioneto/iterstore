<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

try {
    require_once dirname(__DIR__ ,1) . '/boot.php';
} catch (\Iter\Core\Db\ConnectionException $e) {
    // the real cause goes to the server's log (Iter\Core\Log needs the database to know if it's on)
    error_log($e->getMessage());
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(503);
    header('Retry-After: 60');
    $message = 'Ocorreu um erro no acesso ao banco de dados. Tente novamente em alguns instantes.';
    if (str_starts_with(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/core/graphql')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['errors' => [['message' => $message, 'extensions' => ['code' => 'DATABASE_UNAVAILABLE']]]], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Serviço indisponível</title></head>'
        . '<body style="font-family: system-ui, sans-serif; max-width: 32rem; margin: 15vh auto; padding: 0 1rem; color: #333; text-align: center">'
        . '<h1 style="font-size: 1.5rem">Serviço temporariamente indisponível</h1>'
        . '<p>' . $message . '</p></body></html>';
}
