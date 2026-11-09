<?php

declare(strict_types=1);

/**
 * Config::requestBaseUrl: the absolute URLs of webHome follow the address the API was called on.
 * The settings are set in memory only (Config's cache), so nothing in the database changes.
 */

require_once __DIR__ . '/../path.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once FUNCTIONS . 'bootstrap.php';

use Iter\Core\Config;

function check(string $expected, string $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FALHOU: {$label}\nesperado: {$expected}\nobtido:   {$actual}\n");
        exit(1);
    }
    echo "OK: {$label}\n";
}

$values = new ReflectionProperty(Config::class, 'values');
$values->setValue(null, ['default:0' => ['web/base_url' => 'https://configurado.example/', 'web/secure/trusted_proxies' => '10.0.0.9']]);
$request = static function (array $server): void {
    $_SERVER = $server + ['REMOTE_ADDR' => '203.0.113.5'];
};

$request(['HTTP_HOST' => '192.168.1.22:8080']);
check('http://192.168.1.22:8080', Config::requestBaseUrl(), 'o host chamado, com a porta');

$request(['HTTP_HOST' => 'Loja.Example.com', 'HTTPS' => 'on']);
check('https://loja.example.com', Config::requestBaseUrl(), 'https direto');

$request(['HTTP_HOST' => 'interno:8080', 'HTTP_X_FORWARDED_HOST' => 'loja.example.com', 'HTTP_X_FORWARDED_PROTO' => 'https']);
check('http://interno:8080', Config::requestBaseUrl(), 'X-Forwarded-* de quem não é proxy confiável é ignorado');

$request(['REMOTE_ADDR' => '10.0.0.9', 'HTTP_HOST' => 'interno:8080', 'HTTP_X_FORWARDED_HOST' => 'loja.example.com, outro', 'HTTP_X_FORWARDED_PROTO' => 'https']);
check('https://loja.example.com', Config::requestBaseUrl(), 'atrás de proxy confiável vale o X-Forwarded-Host');

foreach (['evil.example/x', 'evil.example"><script>', 'a b', 'evil.example@x', ''] as $bad) {
    $request(['HTTP_HOST' => $bad]);
    check('https://configurado.example', Config::requestBaseUrl(), 'Host inválido (' . var_export($bad, true) . ') cai no Endereço do site');
}

$_SERVER = ['REMOTE_ADDR' => 'cli'];
check('https://configurado.example', Config::requestBaseUrl(), 'fora de uma requisição (cron) vale o Endereço do site');
check('https://configurado.example', Config::baseUrl(), 'baseUrl (e-mails) continua o configurado');
