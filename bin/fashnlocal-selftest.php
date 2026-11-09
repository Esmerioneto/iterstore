<?php

declare(strict_types=1);

// Iter_Fashnlocal with a fake HTTP layer (Guzzle MockHandler): the multipart POST /tryon, the key, the image back,
// and the server's errors in the layer's codes. Config only in memory.
// Uso: php bin/fashnlocal-selftest.php

require_once __DIR__ . '/../path.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once FUNCTIONS . 'bootstrap.php';

use GuzzleHttp\Psr7\Response;
use Iter\Core\Config;
use Iter\Core\Crypt;
use Iter\Fashnlocal\Provider\FashnLocalProvider;
use Iter\Tryon\Api\TryonProviderException;
use Iter\Tryon\Model\TryonProviderRegistry;

function check(bool $condition, string $message, mixed $got = null): void
{
    if (!$condition) {
        fwrite(STDERR, "FALHOU: {$message}\n" . ($got === null ? '' : 'obtido: ' . var_export($got, true) . "\n"));
        exit(1);
    }
    echo "OK: {$message}\n";
}

if (!\isModuleEnabled('iter_fashnlocal')) {
    echo "PULADO: FASHN local (módulo desligado)\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/iter-fashnlocal-selftest-' . bin2hex(random_bytes(4)) . '/';
mkdir($tmp);
putenv('ITER_LOG_DIR=' . $tmp . 'log/');
register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($tmp)));
$values = new ReflectionProperty(Config::class, 'values');
Config::get('x');
$real = $values->getValue();
$config = static function (array $set) use ($values, $real): void {
    $v = $real;
    $v['default:0'] = $set + ($v['default:0'] ?? []);
    $values->setValue(null, $v);
};
$secret = 'fl_selftest' . bin2hex(random_bytes(8));
$configured = ['catalog/tryon/provider' => 'fashn_local', 'tryon/fashn_local/url' => 'http://10.0.0.9:2424/', 'tryon/fashn_local/api_key' => Crypt::encrypt($secret),
    'tryon/fashn_local/garment_photo_type' => 'flat-lay', 'tryon/fashn_local/steps' => '30'];
$job = ['person' => 'P-BYTES', 'garments' => ['G-BYTES'], 'category' => 'bottoms', 'resultSeconds' => 86400];
$provider = static function (array $responses, array &$history): FashnLocalProvider {
    $stack = GuzzleHttp\HandlerStack::create(new GuzzleHttp\Handler\MockHandler($responses));
    $stack->push(GuzzleHttp\Middleware::history($history));

    return new FashnLocalProvider(new GuzzleHttp\Client(['handler' => $stack]));
};

$config(['catalog/tryon/provider' => ''] + $configured);
check(!(new FashnLocalProvider())->isConfigured(), 'sem ser escolhido pelo nome, não é usado');
$config($configured);
check((new TryonProviderRegistry())->active()?->code() === 'fashn_local', 'Provedor = FASHN local: é o provedor ativo');
check(isset(Config::fields()['catalog/tryon/provider']['options']['fashn_local']), 'a opção aparece no campo Provedor');

$history = [];
$sent = $provider([new Response(200, ['Content-Type' => 'image/png'], 'PNG-BYTES')], $history)->submit($job);
$req = $history[0]['request'];
$body = (string) $req->getBody();
check((string) $req->getUri() === 'http://10.0.0.9:2424/tryon' && $req->getHeaderLine('x-api-key') === $secret
    && strpos($body, 'P-BYTES') < strpos($body, 'G-BYTES') && str_contains($body, 'name="person_image"') && str_contains($body, 'name="garment_image"')
    && preg_match('/name="category"\r\n(?:[^\r\n]+\r\n)*\r\nbottoms\r\n/', $body) && preg_match('/name="num_timesteps"\r\n(?:[^\r\n]+\r\n)*\r\n30\r\n/', $body),
    'POST /tryon multipart: pessoa, peça, tipo da peça, passos, com a chave em x-api-key', $body);
check($sent['result']['image'] === 'PNG-BYTES' && $sent['result']['cost'] === 0.0 && $sent['request']['category'] === 'bottoms', 'a imagem volta no próprio envio');

$history = [];
$config(['tryon/fashn_local/api_key' => '', 'tryon/fashn_local/steps' => '500'] + $configured);
$provider([new Response(200, ['Content-Type' => 'image/png'], 'X')], $history)->submit(['category' => 'inventado'] + $job);
check(!$history[0]['request']->hasHeader('x-api-key') && preg_match('/\r\n\r\ntops\r\n/', (string) $history[0]['request']->getBody())
    && preg_match('/name="num_timesteps"\r\n(?:[^\r\n]+\r\n)*\r\n50\r\n/', (string) $history[0]['request']->getBody()), 'sem chave, sem x-api-key; tipo desconhecido vira tops; passos no máximo 50');
$config($configured);

$layer = static function ($response) use ($provider, $job): array {
    $history = [];
    try {
        $provider([$response], $history)->submit($job);
    } catch (TryonProviderException $e) {
        return [$e->errorCode, $e->providerCode];
    }

    return ['none', null];
};
check($layer(new Response(401, ['Content-Type' => 'application/json'], '{"detail":"API key inválida"}')) === [TryonProviderException::UNAVAILABLE, 'http_401'], 'chave recusada (401): UNAVAILABLE');
check($layer(new Response(503, [], '')) === [TryonProviderException::BUSY, 'http_503'], 'servidor ocupado (503): BUSY');
check($layer(new GuzzleHttp\Exception\ConnectException('down', new GuzzleHttp\Psr7\Request('POST', 'x'))) === [TryonProviderException::BUSY, 'no_answer'], 'sem resposta: BUSY');
check($layer(new Response(422, ['Content-Type' => 'application/json'], '{"detail":[]}')) === [TryonProviderException::FAILED, 'http_422'], 'pedido recusado (422): FAILED');
check($layer(new Response(200, ['Content-Type' => 'application/json'], '{}')) === [TryonProviderException::FAILED, 'no_image'], '200 sem imagem: FAILED');
check(!str_contains((string) implode('', array_map('file_get_contents', glob($tmp . 'log/*') ?: [])), $secret), 'a chave não aparece no log');

echo "FASHN local: tudo certo.\n";
