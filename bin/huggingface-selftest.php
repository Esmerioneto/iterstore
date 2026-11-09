<?php

declare(strict_types=1);

// Iter_Huggingface with a fake HTTP layer: the Gradio calls (upload, config, queue, event stream, download) as the FASHN
// Space really answered on 2026-10-29, its errors in the layer's codes, "Tipo de peça no provador" reaching the
// Space, and the whole provador with it (inside a rolled-back transaction). Config only in memory.
// Uso: php bin/huggingface-selftest.php

require_once __DIR__ . '/../path.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once FUNCTIONS . 'bootstrap.php';

use Iter\Core\Config;
use Iter\Core\Crypt;
use Iter\Core\Db\Connection;
use Iter\Huggingface\Client\GradioClient;
use Iter\Huggingface\Provider\HuggingfaceProvider;
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

if (!\isModuleEnabled('iter_huggingface')) {
    echo "PULADO: Hugging Face (módulo desligado)\n";
    exit(0);
}

/** answers each URL with what the test queued for its step (upload, config, call, stream, file); records every call */
final class FakeGradio extends GradioClient
{
    public array $calls = [];
    public array $answers = [];

    protected function request(string $method, string $url, array $headers, array $options, int $timeout): array
    {
        $post = isset($options['multipart']) ? array_column($options['multipart'], 'contents') : (isset($options['json']) ? json_encode($options['json']) : null);
        $step = match (true) {
            str_ends_with($url, '/upload') => 'upload',
            str_ends_with($url, '/config') => 'config',
            str_ends_with($url, '/queue/join') => 'call',
            str_contains($url, '/queue/data') => 'stream',
            default => 'file',
        };
        $this->calls[] = ['step' => $step, 'url' => $url, 'headers' => $headers,
            'post' => $post];

        return $this->answers[$step] ?? [500, ''];
    }
}

$tmp = sys_get_temp_dir() . '/iter-huggingface-selftest-' . bin2hex(random_bytes(4)) . '/';
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
$jpeg = static function (int $w, int $h, array $rgb): string {
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, ...$rgb));
    ob_start();
    imagejpeg($img, null, 90);

    return (string) ob_get_clean();
};
$webp = (static function (): string {
    $img = imagecreatetruecolor(540, 864);
    ob_start();
    imagewebp($img);

    return (string) ob_get_clean();
})();
$secret = 'hf_selftest' . bin2hex(random_bytes(8));
$configured = ['catalog/tryon/provider' => 'huggingface', 'tryon/huggingface/space' => 'fashn-ai/fashn-vton-1.5', 'tryon/huggingface/token' => Crypt::encrypt($secret),
    'tryon/huggingface/garment_photo_type' => 'flat-lay', 'tryon/huggingface/steps' => '30'];
$host = 'https://fashn-ai-fashn-vton-1-5.hf.space';
$file = $host . '/gradio_api/file=/tmp/gradio/5f6496c5a1a6/image.webp';
// as the Space really answered on 2026-10-29
$stream = static fn (array $completed): array => [200, 'data: {"msg":"estimation","event_id":"4e1c0d2bd9b54b02","rank":0,"queue_size":1,"rank_eta":23.8}' . "\n\n"
    . 'data: {"msg":"process_starts","event_id":"4e1c0d2bd9b54b02","eta":23.8}' . "\n\n"
    . 'data: ' . json_encode(['msg' => 'process_completed', 'event_id' => '4e1c0d2bd9b54b02'] + $completed) . "\n\n" . 'data: {"msg":"close_stream","event_id":null}' . "\n\n"];
$fail = static fn (?string $error): array => $stream(['output' => ['error' => $error, 'duration' => 10, 'visible' => true, 'title' => 'ZeroGPU quota exceeded'], 'success' => false]);
$ok = ['upload' => [200, '["/tmp/gradio/aaa/person.jpg", "/tmp/gradio/bbb/garment.jpg"]'],
    'config' => [200, json_encode(['dependencies' => [['id' => 0, 'api_name' => 'update_category'], ['id' => 1, 'api_name' => false], ['id' => 2, 'api_name' => 'try_on']]])],
    'call' => [200, '{"event_id": "4e1c0d2bd9b54b02"}'],
    'stream' => $stream(['output' => ['data' => [['path' => '/tmp/gradio/5f6496c5a1a6/image.webp', 'url' => $file, 'size' => null, 'orig_name' => 'image.webp', 'mime_type' => null,
        'is_stream' => false, 'meta' => ['_type' => 'gradio.FileData']]], 'is_generating' => false, 'duration' => 21.4], 'success' => true]), 'file' => [200, $webp]];
$job = ['person' => $jpeg(634, 1013, [180, 160, 140]), 'garments' => [$jpeg(448, 640, [10, 10, 10])], 'category' => 'tops', 'resultSeconds' => 86400];

$guzzle = static function (array $responses, array &$history): GuzzleHttp\Client {
    $stack = GuzzleHttp\HandlerStack::create(new GuzzleHttp\Handler\MockHandler($responses));
    $stack->push(GuzzleHttp\Middleware::history($history));

    return new GuzzleHttp\Client(['handler' => $stack]);
};
// --- the HTTP client (Guzzle) ------------------------------------------------------------------------------
$history = [];
$gradio = new GradioClient($guzzle([new GuzzleHttp\Psr7\Response(200, [], $ok['upload'][1]), new GuzzleHttp\Psr7\Response(200, [], $ok['config'][1]), new GuzzleHttp\Psr7\Response(200, [], $ok['call'][1]),
    new GuzzleHttp\Psr7\Response(200, [], $ok['stream'][1]), new GuzzleHttp\Psr7\Response(200, [], $webp)], $history));
[$state, $out] = $gradio->predict('fashn-ai/fashn-vton-1.5', 'tk', 'try_on', ['person' => 'P-BYTES', 'garment' => 'G-BYTES'], ['tops']);
$image = $gradio->download($out[0]['url'], 'tk');
[$up, $cf, $cl, $ev] = array_map(static fn ($h) => $h['request'], $history);
$joined = json_decode((string) $cl->getBody(), true);
check($state === 'complete' && $image === $webp && (string) $up->getUri() === $host . '/gradio_api/upload' && substr_count((string) $up->getBody(), 'name="files"') === 2
    && strpos((string) $up->getBody(), 'P-BYTES') < strpos((string) $up->getBody(), 'G-BYTES') && $cl->getHeaderLine('Content-Type') === 'application/json'
    && (string) $cf->getUri() === $host . '/config' && (string) $cl->getUri() === $host . '/gradio_api/queue/join' && $joined['data'][2] === 'tops' && $joined['fn_index'] === 2
    && $ev->getMethod() === 'GET' && (string) $ev->getUri() === $host . '/gradio_api/queue/data?session_hash=' . $joined['session_hash']
    && !array_filter($history, static fn ($h) => $h['request']->getHeaderLine('Authorization') !== 'Bearer tk'),
    'cliente HTTP (Guzzle): upload multipart (pessoa, peça), índice do /try_on no config, entrada na fila (JSON), leitura do fluxo e download, com o token em todas');
$history = [];
check((new GradioClient($guzzle([new GuzzleHttp\Exception\ConnectException('timeout', new GuzzleHttp\Psr7\Request('POST', 'x'))], $history)))
    ->predict('a/b', '', 'try_on', ['p' => 'x'], [])[0] === 'http', 'cliente HTTP: sem rede vira estado http');

// --- configuration and choice --------------------------------------------------------------------------------
check(GradioClient::host('fashn-ai/fashn-vton-1.5') === $host, 'o endereço do Space vem do nome (fashn-ai/fashn-vton-1.5 → fashn-ai-fashn-vton-1-5.hf.space)');
$config(['catalog/tryon/provider' => ''] + $configured);
check(!(new HuggingfaceProvider())->isConfigured() && (new TryonProviderRegistry())->active()?->code() !== 'huggingface',
    'sem ser escolhido pelo nome, não é usado nem como "o primeiro configurado" (demonstração pública)');
$config($configured);
check((new TryonProviderRegistry())->active()?->code() === 'huggingface', 'Provedor = Hugging Face: é o provedor ativo');
check(isset(Config::fields()['catalog/tryon/provider']['options']['huggingface']), 'a opção aparece no campo Provedor');
$provider = new HuggingfaceProvider($fake = new FakeGradio());
check($provider->model() === 'fashn-ai/fashn-vton-1.5' && $provider->estimatedCost(1) === 0.0, 'modelo = o Space; custo zero');

// --- success -----------------------------------------------------------------------------------------------
$fake->answers = $ok;
$sent = $provider->submit($job);
[$upload, , $call, $read, $download] = $fake->calls;
check(array_column($fake->calls, 'step') === ['upload', 'config', 'call', 'stream', 'file'] && $upload['url'] === $host . '/gradio_api/upload'
    && $call['url'] === $host . '/gradio_api/queue/join' && str_starts_with($read['url'], $host . '/gradio_api/queue/data?session_hash=') && $download['url'] === $file,
    'envia as imagens, entra na fila do /try_on, lê o fluxo de eventos e baixa o resultado do próprio Space', array_column($fake->calls, 'url'));
check(array_values($upload['post']) === [$job['person'], $job['garments'][0]], 'a foto do cliente e a peça vão nessa ordem');
$data = json_decode($call['post'], true)['data'];
check($data[0]['path'] === '/tmp/gradio/aaa/person.jpg' && $data[1]['path'] === '/tmp/gradio/bbb/garment.jpg' && array_slice($data, 2) === ['tops', 'flat-lay', 30, 1.5, 42, true],
    'entradas do /try_on: pessoa, peça, tipo da peça, tipo de foto, passos, guidance, semente, sem segmentação', $data);
check(!array_filter($fake->calls, static fn ($c) => ($c['headers']['Authorization'] ?? null) !== 'Bearer ' . $secret), 'o token decifrado vai em todas as chamadas');
check($sent['result']['status'] === 'success' && $sent['result']['image'] === $webp && $sent['result']['cost'] === 0.0 && str_starts_with($sent['task'], 'hf-')
    && $sent['request']['category'] === 'tops', 'a imagem volta no próprio envio (síncrono)');
$fake->calls = [];
$provider->submit(['category' => 'bottoms'] + $job);
check(array_slice(json_decode($fake->calls[2]['post'], true)['data'], 2, 1) === ['bottoms'], 'calça: o Space recebe bottoms');
$fake->calls = [];
$config(['tryon/huggingface/token' => '', 'tryon/huggingface/garment_photo_type' => 'model', 'tryon/huggingface/steps' => '500'] + $configured);
$provider->submit(['category' => 'inventado'] + $job);
check(array_slice(json_decode($fake->calls[2]['post'], true)['data'], 2, 3) === ['tops', 'model', 50] && $fake->calls[0]['headers'] === [],
    'tipo desconhecido vira tops; foto vestida; passos no máximo 50; sem token, sem Authorization');
$config($configured);

// --- errors ------------------------------------------------------------------------------------------------
$layer = static function (array $answers) use ($provider, $fake, $job, $ok): array {
    $fake->answers = $answers + $ok;
    $fake->calls = [];
    try {
        $provider->submit($job);
    } catch (TryonProviderException $e) {
        return [$e->errorCode, $e->providerCode];
    }

    return ['none', null];
};
check($layer(['stream' => $fail('You have exceeded your ZeroGPU runs limit. Authenticate with a Hugging Face token for more quota - https://huggingface.co/settings/tokens')]) === [TryonProviderException::BUSY, 'gpu_quota'],
    'cota grátis de GPU esgotada: BUSY (a loja vê o erro no log)');
check(str_contains((string) implode('', array_map('file_get_contents', glob($tmp . 'log/*') ?: [])), 'Confira o Space e o token'), 'cota esgotada: o log diz para conferir o Space e o token');
check($layer(['stream' => $fail(null)]) === [TryonProviderException::FAILED, 'space_error'], 'erro do app no Space: FAILED');
check($layer(['config' => [200, '{"dependencies": []}']]) === [TryonProviderException::UNAVAILABLE, 'space_not_found'], 'o Space não tem /try_on: UNAVAILABLE');
check($layer(['upload' => [503, '']]) === [TryonProviderException::BUSY, 'http_503'], 'Space dormindo ou acordando (503): BUSY');
check($layer(['stream' => [200, 'data: {"msg":"heartbeat"}' . "\n\n"]]) === [TryonProviderException::BUSY, 'no_answer'], 'o fluxo acabou sem resultado (demorou demais): BUSY');
check($layer(['upload' => [401, '{"error":"Invalid credentials"}']])[0] === TryonProviderException::UNAVAILABLE, 'token recusado (401): UNAVAILABLE');
check($layer(['upload' => [404, 'Not Found']]) === [TryonProviderException::UNAVAILABLE, 'space_not_found'], 'Space inexistente (404): UNAVAILABLE');
check($layer(['stream' => $stream(['output' => ['data' => [['url' => 'https://outro-site.example/image.webp']]], 'success' => true])]) === [TryonProviderException::FAILED, 'no_image']
    && !in_array('file', array_column($fake->calls, 'step'), true), 'resultado fora do Space: não baixa (o token não sai do Space), FAILED');
check($layer(['file' => [404, '']])[0] === TryonProviderException::FAILED, 'imagem do resultado não baixou: FAILED');
check(!str_contains((string) implode('', array_map('file_get_contents', glob($tmp . 'log/*') ?: [])), $secret), 'o token não aparece no log');

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
$garmentDir = Config::mediaDir() . 'eav/selftest-hf-' . bin2hex(random_bytes(4)) . '/';
mkdir($garmentDir, 0775, true);
$kept[] = $garmentDir . 'peca.jpg';
file_put_contents($garmentDir . 'peca.jpg', $job['garments'][0]);
register_shutdown_function(static fn () => @rmdir($garmentDir));
$product = (int) $db->insert('catalog_product', ['attribute_set_id' => (int) $db->fetchOne("SELECT s.attribute_set_id FROM eav_attribute_set s JOIN eav_entity_type t ON t.entity_type_id = s.entity_type_id AND t.code = 'product' ORDER BY s.is_default DESC LIMIT 1"),
    'sku' => 'HF-ST-' . bin2hex(random_bytes(4)), 'name' => 'Calça do teste do Hugging Face'])['product_id'];
$db->insert('eav_value_boolean', ['attribute_id' => $attr('product', TryonService::ATTRIBUTE), 'entity_id' => $product, 'value' => true]);
$db->insert('eav_value_varchar', ['attribute_id' => $attr('product', (string) $db->fetchOne("SELECT a.code FROM eav_attribute a JOIN eav_entity_type t ON t.entity_type_id = a.entity_type_id AND t.code = 'product' WHERE a.input_type = 'image' ORDER BY a.sort_order, a.attribute_id LIMIT 1")),
    'entity_id' => $product, 'value' => basename($garmentDir) . '/peca.jpg']);
$service = new TryonService($provider);
check($service->category($product) === 'tops', 'produto sem "Tipo de peça no provador": parte de cima');
$db->insert('eav_value_varchar', ['attribute_id' => $attr('product', TryonService::CATEGORY_ATTRIBUTE), 'entity_id' => $product, 'value' => 'bottoms']);
$photo = $tmp . 'foto.jpg';
file_put_contents($photo, $job['person']);

$fake->answers = $ok;
$fake->calls = [];
$done = $service->start($customer, $websiteId, $photo, $product);
$kept[] = TryonService::imageDir() . basename((string) $done['image_url']);
check($done['state'] === 'SUCCESS' && str_ends_with((string) $done['image_url'], '.webp') && (float) $done['cost'] === 0.0 && $done['provider'] === 'huggingface',
    'startTryOn com o Hugging Face: SUCCESS na hora, o WebP guardado em /media/tryon, custo zero', $done);
check(array_slice(json_decode($fake->calls[2]['post'], true)['data'], 2, 1) === ['bottoms'], 'o "Tipo de peça no provador" do produto (calça) chega ao Space');

echo "Hugging Face: tudo certo.\n";
