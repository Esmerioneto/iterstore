<?php

declare(strict_types=1);

// Iter_Gemini with a fake HTTP client: the JSON sent, Google's answers as they really come (success, overloaded,
// invalid key, no billing, blocked content...) in the layer's codes, the provider chosen in Configurações, and the
// whole provador with it (inside a rolled-back transaction). Config only in memory.
//
// --live: one real try-on with the key in ~/.config/iterstore-gemini-key (the project needs billing on).
// Uso: php bin/gemini-selftest.php [--live [foto] [peça]]

require_once __DIR__ . '/../path.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once FUNCTIONS . 'bootstrap.php';

use Iter\Core\Config;
use Iter\Core\Crypt;
use Iter\Core\Db\Connection;
use Iter\Gemini\Client\GeminiClient;
use Iter\Gemini\Provider\GeminiProvider;
use Iter\Tryon\Api\TryonProviderException;
use Iter\Tryon\Model\TryonProviderRegistry;
use Iter\Tryon\Model\TryonService;

function check(bool $condition, string $message, mixed $got = null): void
{
    if (!$condition) {
        fwrite(STDERR, "FALHOU: {$message}\n" . ($got === null ? '' : 'obtido: ' . var_export($got, true) . "\n"));
        exit(1);
    }
    echo "OK: {$message}\n";
}

if (!\isModuleEnabled('iter_gemini')) {
    echo "PULADO: Gemini (módulo desligado)\n";
    exit(0);
}

/** answers what the test queues; records every call */
final class FakeGemini extends GeminiClient
{
    public array $calls = [];
    public array $answers = [];

    public function generate(string $apiKey, string $model, array $body): array
    {
        $this->calls[] = ['key' => $apiKey, 'model' => $model, 'body' => $body];

        return array_shift($this->answers) ?? [500, []];
    }
}

$tmp = sys_get_temp_dir() . '/iter-gemini-selftest-' . bin2hex(random_bytes(4)) . '/';
mkdir($tmp);
putenv('ITER_LOG_DIR=' . $tmp . 'log/');
$values = new ReflectionProperty(Config::class, 'values');
Config::get('x');
$real = $values->getValue();
$config = static function (array $set) use ($values, $real): void {
    $v = $real;
    $v['default:0'] = $set + ($v['default:0'] ?? []);
    $values->setValue(null, $v);
};
$jpeg = static function (int $w, int $h, array $rgb, bool $png = false): string {
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, ...$rgb));
    ob_start();
    $png ? imagepng($img) : imagejpeg($img, null, 90);

    return (string) ob_get_clean();
};
$secret = 'AQ.selftest_' . bin2hex(random_bytes(8));
$configured = ['tryon/gemini/api_key' => Crypt::encrypt($secret), 'tryon/gemini/model' => 'gemini-3.1-flash-image', 'catalog/tryon/provider' => 'gemini'];
$ok = static fn (string $image): array => [200, ['candidates' => [['content' => ['role' => 'model', 'parts' => [
    ['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode($image)], 'thoughtSignature' => 'c2lnbmF0dXJl']]], 'finishReason' => 'STOP']],
    'usageMetadata' => ['promptTokenCount' => 1100, 'candidatesTokenCount' => 1120]]];
// as Google really answered on 2026-10-29
$overloaded = [503, ['error' => ['code' => 503, 'message' => 'The model is overloaded. Please try again later.', 'status' => 'UNAVAILABLE']]];
$badKey = [400, ['error' => ['code' => 400, 'message' => 'API key not valid. Please pass a valid API key.', 'status' => 'INVALID_ARGUMENT',
    'details' => [['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => 'API_KEY_INVALID', 'domain' => 'googleapis.com']]]]];
$noBilling = [429, ['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => "You exceeded your current quota, please check your plan and billing details.\n* Quota exceeded for metric: generativelanguage.googleapis.com/generate_content_free_tier_requests, limit: 0, model: gemini-3.1-flash-image"]]];
$job = ['person' => $jpeg(1013, 1536, [180, 160, 140]), 'garments' => [$jpeg(1086, 1600, [90, 160, 220])], 'resultSeconds' => 86400];

$guzzle = static function (array $responses, array &$history): GuzzleHttp\Client {
    $stack = GuzzleHttp\HandlerStack::create(new GuzzleHttp\Handler\MockHandler($responses));
    $stack->push(GuzzleHttp\Middleware::history($history));

    return new GuzzleHttp\Client(['handler' => $stack]);
};
// --- the HTTP client (Guzzle) ------------------------------------------------------------------------------
$history = [];
[$st, $body] = (new GeminiClient($guzzle([new GuzzleHttp\Psr7\Response(429, [], '{"error":{"code":429,"status":"RESOURCE_EXHAUSTED"}}')], $history)))
    ->generate('chave', 'gemini-3.1-flash-image', ['contents' => [['parts' => [['text' => 'oi']]]]]);
$sentReq = $history[0]['request'];
check($st === 429 && $body['error']['status'] === 'RESOURCE_EXHAUSTED' && (string) $sentReq->getUri() === GeminiClient::API . 'gemini-3.1-flash-image:generateContent'
    && $sentReq->getHeaderLine('X-goog-api-key') === 'chave' && $sentReq->getHeaderLine('Content-Type') === 'application/json'
    && json_decode((string) $sentReq->getBody(), true)['contents'][0]['parts'][0]['text'] === 'oi', 'cliente HTTP (Guzzle): POST JSON com a chave no cabeçalho; erro HTTP vem como status, sem exceção');
$history = [];
check((new GeminiClient($guzzle([new GuzzleHttp\Exception\ConnectException('timeout', new GuzzleHttp\Psr7\Request('POST', 'x'))], $history)))->generate('k', 'm', [])[0] === 0,
    'cliente HTTP: sem rede vira status 0');

// --- configuration and choice --------------------------------------------------------------------------------
$config(['tryon/gemini/api_key' => '']);
check(!(new GeminiProvider())->isConfigured(), 'sem chave: não configurado');
$config($configured);
$provider = new GeminiProvider($fake = new FakeGemini());
check($provider->isConfigured() && $provider->model() === 'gemini-3.1-flash-image' && $provider->estimatedCost(1) === 0.067, 'configurado: Nano Banana 2, estimativa US$ 0,067');
$config(['tryon/gemini/model' => 'inventado'] + $configured);
check($provider->model() === 'gemini-3.1-flash-image', 'modelo desconhecido gravado: volta ao padrão');
$config($configured);
$options = Config::fields()['catalog/tryon/provider']['options'] ?? [];
check(isset($options[''], $options['gemini']) && (!\isModuleEnabled('iter_cloudflare') || isset($options['cloudflare'])) && Config::fields()['catalog/tryon/provider']['type'] === 'select',
    'Configurações → Provedor: cada módulo de provedor junta a sua opção ao campo do Provador', $options);
check((new TryonProviderRegistry())->active()?->code() === 'gemini', 'Gemini escolhido e configurado: é o provedor ativo');
$config(['tryon/gemini/api_key' => ''] + $configured);
check((new TryonProviderRegistry())->active() === null, 'Gemini escolhido sem chave: provador desligado (não manda a foto a outro parceiro)');
$config(['catalog/tryon/provider' => ''] + $configured);
check((new TryonProviderRegistry())->active() !== null, 'nenhum escolhido: o primeiro configurado');
$config($configured);

// --- success -----------------------------------------------------------------------------------------------
$fake->answers = [$ok($result = $jpeg(832, 1248, [10, 20, 30], true))];
$sent = $provider->submit($job);
$call = $fake->calls[0];
$parts = $call['body']['contents'][0]['parts'];
check([$call['key'], $call['model']] === [$secret, 'gemini-3.1-flash-image'], 'chama o modelo configurado, com a chave decifrada');
check(str_contains($parts[0]['text'], 'Keep exactly the same face') && base64_decode($parts[1]['inline_data']['data']) === $job['person'] && base64_decode($parts[2]['inline_data']['data']) === $job['garments'][0],
    'a instrução, depois a foto do cliente e a peça, nessa ordem');
check($call['body']['generationConfig'] === ['responseModalities' => ['IMAGE'], 'imageConfig' => ['aspectRatio' => '2:3']], 'pede só imagem, na proporção mais próxima da foto (1013×1536 → 2:3)', $call['body']['generationConfig']);
check($sent['result']['status'] === 'success' && $sent['result']['image'] === $result && str_starts_with($sent['task'], 'gm-') && $sent['request']['person'] === '1013x1536',
    'a resposta já traz a imagem (PNG): o resultado vem no próprio envio');
check(!str_contains(json_encode($sent['request'] + ['r' => $sent['result']['response']]), base64_encode(substr($result, 0, 60))) && !str_contains(json_encode($sent['result']['response']), 'c2lnbmF0dXJl'),
    'o pedido e a resposta guardados não têm imagens');

// --- errors ------------------------------------------------------------------------------------------------
$fake->calls = [];
$fake->answers = [$overloaded, $ok($result)];
check($provider->submit($job)['result']['status'] === 'success' && count($fake->calls) === 2, 'modelo sobrecarregado (503) e a mesma chamada passa: uma nova tentativa');
$layer = static function (array ...$answers) use ($provider, $fake, $job): array {
    $fake->answers = $answers;
    try {
        $provider->submit($job);
    } catch (TryonProviderException $e) {
        return [$e->errorCode, $e->providerCode];
    }

    return ['none', null];
};
check($layer($overloaded, $overloaded) === [TryonProviderException::BUSY, 'UNAVAILABLE'], 'sobrecarregado duas vezes: BUSY');
check($layer($badKey) === [TryonProviderException::UNAVAILABLE, 'API_KEY_INVALID'], 'chave inválida (400, API_KEY_INVALID): UNAVAILABLE');
check($layer($noBilling)[0] === TryonProviderException::BUSY, 'sem faturamento (429, limit: 0): BUSY para o cliente (a loja vê o erro no log)');
check(str_contains((string) implode('', array_map('file_get_contents', glob($tmp . 'log/*') ?: [])), 'faturamento'), 'sem faturamento: o log diz para conferir chave e faturamento');
check($layer([200, ['promptFeedback' => ['blockReason' => 'PROHIBITED_CONTENT']]]) === [TryonProviderException::REFUSED, 'PROHIBITED_CONTENT'], 'pedido bloqueado (blockReason): REFUSED');
check($layer([200, ['candidates' => [['finishReason' => 'IMAGE_SAFETY']]]])[0] === TryonProviderException::REFUSED, 'imagem bloqueada (IMAGE_SAFETY): REFUSED');
check($layer([200, ['candidates' => [['content' => ['parts' => [['text' => 'I cannot do that.']]], 'finishReason' => 'STOP']]]]) === [TryonProviderException::FAILED, 'STOP'], 'resposta só com texto: FAILED');
check($layer([0, ['error' => ['message' => 'Could not resolve host']]])[0] === TryonProviderException::FAILED, 'sem resposta (rede): FAILED');
check(!str_contains((string) implode('', array_map('file_get_contents', glob($tmp . 'log/*') ?: [])), $secret), 'a chave não aparece no log');

// --- the provador with it ----------------------------------------------------------------------------------
$db = Connection::get()->adapter();
$db->beginTransaction();
$kept = [];
register_shutdown_function(static function () use ($db, $tmp, &$kept): void {
    $db->inTransaction() && $db->rollBack();
    array_map('unlink', array_filter($kept, 'is_file'));
    exec('rm -rf ' . escapeshellarg($tmp));
});
$config($configured + ['catalog/tryon/active' => '1', 'catalog/tryon/daily_per_customer' => '0', 'catalog/tryon/monthly_budget_usd' => '0', 'catalog/tryon/result_hours' => '24']);
$websiteId = (int) $db->fetchOne('SELECT website_id FROM store_website ORDER BY website_id LIMIT 1');
$customer = (int) $db->insert('customer_entity', ['website_id' => $websiteId,
    'attribute_set_id' => (int) $db->fetchOne("SELECT s.attribute_set_id FROM eav_attribute_set s JOIN eav_entity_type t ON t.entity_type_id = s.entity_type_id AND t.code = 'customer' ORDER BY s.is_default DESC LIMIT 1"),
    'group_id' => (int) $db->fetchOne('SELECT customer_group_id FROM customer_group ORDER BY 1 LIMIT 1')])['customer_id'];
$attr = static fn (string $entity, string $code): int => (int) $db->fetchOne("SELECT a.attribute_id FROM eav_attribute a JOIN eav_entity_type t ON t.entity_type_id = a.entity_type_id AND t.code = :e WHERE a.code = :c", ['e' => $entity, 'c' => $code]);
$db->insert('eav_value_boolean', ['attribute_id' => $attr('customer', TryonService::ATTRIBUTE), 'entity_id' => $customer, 'value' => true]);
$garmentDir = Config::mediaDir() . 'eav/selftest-gm-' . bin2hex(random_bytes(4)) . '/';
mkdir($garmentDir, 0775, true);
$kept[] = $garmentDir . 'peca.jpg';
file_put_contents($garmentDir . 'peca.jpg', $job['garments'][0]);
register_shutdown_function(static fn () => @rmdir($garmentDir));
$product = (int) $db->insert('catalog_product', ['attribute_set_id' => (int) $db->fetchOne("SELECT s.attribute_set_id FROM eav_attribute_set s JOIN eav_entity_type t ON t.entity_type_id = s.entity_type_id AND t.code = 'product' ORDER BY s.is_default DESC LIMIT 1"),
    'sku' => 'GM-ST-' . bin2hex(random_bytes(4)), 'name' => 'Peça do teste do Gemini'])['product_id'];
$db->insert('eav_value_boolean', ['attribute_id' => $attr('product', TryonService::ATTRIBUTE), 'entity_id' => $product, 'value' => true]);
$db->insert('eav_value_varchar', ['attribute_id' => $attr('product', (string) $db->fetchOne("SELECT a.code FROM eav_attribute a JOIN eav_entity_type t ON t.entity_type_id = a.entity_type_id AND t.code = 'product' WHERE a.input_type = 'image' ORDER BY a.sort_order, a.attribute_id LIMIT 1")),
    'entity_id' => $product, 'value' => basename($garmentDir) . '/peca.jpg']);
$photo = $tmp . 'foto.jpg';
file_put_contents($photo, $job['person']);

$service = new TryonService($provider);
$fake->answers = [$ok($result)];
$done = $service->start($customer, $websiteId, $photo, $product);
$file = TryonService::imageDir() . basename((string) $done['image_url']);
$kept[] = $file;
check($done['state'] === 'SUCCESS' && str_ends_with((string) $done['image_url'], '.png') && file_get_contents($file) === $result && (float) $done['cost'] === 0.067 && $done['provider'] === 'gemini',
    'startTryOn com o Gemini: SUCCESS na hora, o PNG guardado em /media/tryon, custo estimado', $done);
$fake->answers = [$noBilling];
$other = $tmp . 'outra.jpg';
file_put_contents($other, $jpeg(900, 1400, [1, 2, 3]));
try {
    $service->start($customer, $websiteId, $other, $product);
    $busy = 'none';
} catch (\Iter\Eav\Model\AttributeException $e) {
    $busy = $e->errorCode;
}
check($busy === 'TRYON_BUSY', 'Gemini sem faturamento: o cliente vê TRYON_BUSY');

// --- live --------------------------------------------------------------------------------------------------
if (in_array('--live', $argv, true)) {
    $key = trim((string) @file_get_contents(getenv('HOME') . '/.config/iterstore-gemini-key'));
    if ($key === '') {
        fwrite(STDERR, "--live precisa de ~/.config/iterstore-gemini-key\n");
        exit(2);
    }
    $config(['tryon/gemini/api_key' => Crypt::encrypt($key)] + $configured);
    $at = array_search('--live', $argv, true);
    $live = (new GeminiProvider())->submit(['person' => TryonService::normalize((string) file_get_contents($argv[$at + 1] ?? '/home/esmerio/Imagens/foto_Lu2.jpg')),
        'garments' => [TryonService::normalize((string) file_get_contents($argv[$at + 2] ?? dirname(__DIR__) . '/public/media/eav/product/25/6db697c71fb97c91f97b8b3a43904b78.jpg'))], 'resultSeconds' => 86400]);
    $out = getcwd() . '/gemini-live.' . (getimagesizefromstring($live['result']['image'])[2] === IMAGETYPE_PNG ? 'png' : 'jpg');
    file_put_contents($out, $live['result']['image']);
    check($live['result']['status'] === 'success', "ao vivo: o Gemini gerou a prova ({$out})");
}

echo "Gemini: tudo certo.\n";
