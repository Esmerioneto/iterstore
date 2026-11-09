<?php

declare(strict_types=1);

// Iter_Cloudflare (TRYON-PLAN delivery 3) with a fake HTTP client: the multipart sent, Cloudflare's answers as they
// really come (success, the transient internal error, refused token, used-up free allocation...) in the layer's codes,
// and the whole provador with it (inside a rolled-back transaction). Config only in memory.
//
// --live: one real try-on with ~/.config/iterstore-cloudflare-token and CLOUDFLARE_ACCOUNT_ID (uses the free allocation).
// Uso: php bin/cloudflare-selftest.php [--live]

require_once __DIR__ . '/../path.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once FUNCTIONS . 'bootstrap.php';

use Iter\Cloudflare\Client\WorkersAiClient;
use Iter\Cloudflare\Provider\CloudflareProvider;
use Iter\Core\Config;
use Iter\Core\Crypt;
use Iter\Core\Db\Connection;
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

if (!\isModuleEnabled('iter_cloudflare')) {
    echo "PULADO: Cloudflare (módulo desligado)\n";
    exit(0);
}

/** answers what the test queues; records every call (without opening the files) */
final class FakeWorkersAi extends WorkersAiClient
{
    public array $calls = [];
    public array $answers = [];

    public function run(string $accountId, string $token, string $model, array $fields, array $files = []): array
    {
        $this->calls[] = ['account' => $accountId, 'token' => $token, 'model' => $model, 'fields' => $fields + array_map('getimagesizefromstring', $files)];

        return array_shift($this->answers) ?? [500, []];
    }
}

$tmp = sys_get_temp_dir() . '/iter-cloudflare-selftest-' . bin2hex(random_bytes(4)) . '/';
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
$secret = 'cfut_selftest_' . bin2hex(random_bytes(8));
$configured = ['tryon/cloudflare/account_id' => 'acc123', 'tryon/cloudflare/api_token' => Crypt::encrypt($secret), 'tryon/cloudflare/model' => 'flux-2-klein-9b'];
$ok = static fn (string $image): array => [200, ['result' => ['image' => base64_encode($image)], 'success' => true, 'errors' => [], 'messages' => []]];
// as Cloudflare really answered on 2026-10-27
$internal = [500, ['errors' => [['message' => 'AiError: AiError: Internal server error (1f859a23-a1d9-4205-bc38-b2250e8b8ce0)', 'code' => 3043]], 'success' => false, 'result' => [], 'messages' => []]];
$job = ['person' => $jpeg(1013, 1536, [180, 160, 140]), 'garments' => [$jpeg(1086, 1600, [90, 160, 220])], 'resultSeconds' => 86400];

$guzzle = static function (array $responses, array &$history): GuzzleHttp\Client {
    $stack = GuzzleHttp\HandlerStack::create(new GuzzleHttp\Handler\MockHandler($responses));
    $stack->push(GuzzleHttp\Middleware::history($history));

    return new GuzzleHttp\Client(['handler' => $stack]);
};
// --- the HTTP client (Guzzle) ------------------------------------------------------------------------------
$history = [];
[$st, $body] = (new WorkersAiClient($guzzle([new GuzzleHttp\Psr7\Response(200, [], '{"success":true,"result":{"image":"aGk="}}')], $history)))
    ->run('acc 1', 'tok', '@cf/black-forest-labs/flux-2-klein-9b', ['prompt' => 'p'], ['input_image_0' => 'JPEGBYTES']);
$sentReq = $history[0]['request'];
check($st === 200 && $body['result']['image'] === 'aGk=' && (string) $sentReq->getUri() === WorkersAiClient::API . 'acc%201/ai/run/@cf/black-forest-labs/flux-2-klein-9b'
    && $sentReq->getHeaderLine('Authorization') === 'Bearer tok' && str_contains((string) $sentReq->getBody(), 'name="input_image_0"; filename="image.jpg"')
    && str_contains((string) $sentReq->getBody(), 'JPEGBYTES'), 'cliente HTTP (Guzzle): POST multipart com o token, a instrução e a imagem');
$history = [];
check((new WorkersAiClient($guzzle([new GuzzleHttp\Exception\ConnectException('Could not resolve host', new GuzzleHttp\Psr7\Request('POST', 'x'))], $history)))->run('a', 't', 'm', [])[0] === 0,
    'cliente HTTP: sem rede vira status 0');

// --- configuration -----------------------------------------------------------------------------------------
$config(['tryon/cloudflare/account_id' => '', 'tryon/cloudflare/api_token' => '']);
check(!(new CloudflareProvider())->isConfigured(), 'sem Account ID e token: não configurado (o provador fica desligado)');
$config($configured);
$provider = new CloudflareProvider($fake = new FakeWorkersAi());
check($provider->isConfigured() && $provider->model() === '@cf/black-forest-labs/flux-2-klein-9b' && $provider->estimatedCost(1) === 0.017, 'configurado: modelo klein 9B, estimativa US$ 0,017');
check(isset((new TryonProviderRegistry())->all()['cloudflare']), 'o registro do provador acha o provedor (etc/tryon_provider.json)');
$config(['tryon/cloudflare/model' => 'flux-2-klein-4b'] + $configured);
check($provider->model() === '@cf/black-forest-labs/flux-2-klein-4b' && $provider->estimatedCost(1) === 0.001, 'modelo 4B nas Configurações');
$config(['tryon/cloudflare/model' => 'inventado'] + $configured);
check($provider->model() === '@cf/black-forest-labs/flux-2-klein-9b', 'modelo desconhecido gravado: volta ao 9B');
$config($configured);

// --- success -----------------------------------------------------------------------------------------------
$fake->answers = [$ok($result = $jpeg(816, 1296, [10, 20, 30]))];
$sent = $provider->submit($job);
$call = $fake->calls[0];
check([$call['account'], $call['token'], $call['model']] === ['acc123', $secret, '@cf/black-forest-labs/flux-2-klein-9b'], 'chama a conta e o modelo configurados, com o token decifrado');
check(max($call['fields']['input_image_0'][0], $call['fields']['input_image_0'][1]) === 511 && max($call['fields']['input_image_1'][0], $call['fields']['input_image_1'][1]) === 511,
    'a foto (input_image_0) e a peça (input_image_1) vão com o lado maior em 511 px (o modelo pede menos de 512)', $call['fields']);
check((int) $call['fields']['width'] % 16 === 0 && (int) $call['fields']['height'] % 16 === 0 && (int) $call['fields']['height'] > (int) $call['fields']['width']
    && str_contains($call['fields']['prompt'], 'Keep exactly the same face'), 'saída nas proporções da foto, em múltiplos de 16, e a instrução das Configurações', $call['fields']);
check($sent['result']['status'] === 'success' && $sent['result']['image'] === $result && str_starts_with($sent['task'], 'cf-'), 'a resposta já traz a imagem: o resultado vem no próprio envio');
check(!str_contains(json_encode($sent['request'] + ['r' => $sent['result']['response']]), base64_encode(substr($result, 0, 60))) && $sent['request']['input_image_0'] === '337x511',
    'o pedido e a resposta guardados não têm imagens', $sent['request']);

// --- errors ------------------------------------------------------------------------------------------------
$fake->calls = [];
$fake->answers = [$internal, $ok($result)];
check($provider->submit($job)['result']['status'] === 'success' && count($fake->calls) === 2, 'erro interno (HTTP 500, 3043) e a mesma chamada passa: uma nova tentativa');
$layer = static function (array ...$answers) use ($provider, $fake, $job): array {
    $fake->answers = $answers;
    try {
        $provider->submit($job);
    } catch (TryonProviderException $e) {
        return [$e->errorCode, $e->providerCode];
    }

    return ['none', null];
};
check($layer($internal, $internal) === [TryonProviderException::BUSY, '3043'], 'erro interno duas vezes: BUSY (o cliente tenta de novo)');
check($layer([401, ['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']]]])[0] === TryonProviderException::UNAVAILABLE, 'token recusado (401, 10000): UNAVAILABLE');
check($layer([429, ['success' => false, 'errors' => [['code' => 4006, 'message' => 'you have used up your daily free allocation of 10,000 neurons, please upgrade to Cloudflare\'s Workers Paid plan']]]])[0] === TryonProviderException::BUSY,
    'cota grátis do dia acabou (4006): BUSY para o cliente (a loja vê o erro no log)');
check($layer([429, ['success' => false, 'errors' => [['code' => 3040, 'message' => 'Capacity temporarily exceeded']]]])[0] === TryonProviderException::BUSY, 'limite momentâneo (429): BUSY');
check($layer([400, ['success' => false, 'errors' => [['code' => 5006, 'message' => 'Input flagged by safety filter']]]])[0] === TryonProviderException::REFUSED, 'conteúdo recusado: REFUSED');
check($layer([400, ['success' => false, 'errors' => [['code' => 5006, 'message' => 'Error: required properties at \'/\' are \'prompt\'']]]])[0] === TryonProviderException::FAILED, 'parâmetro recusado (400): FAILED');
check($layer([0, ['errors' => [['message' => 'Could not resolve host']]]])[0] === TryonProviderException::FAILED, 'sem resposta (rede): FAILED');
check($layer([200, ['success' => true, 'result' => ['image' => '%%%']]])[0] === TryonProviderException::FAILED, 'resposta 200 sem imagem válida: FAILED');
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
$garmentDir = Config::mediaDir() . 'eav/selftest-cf-' . bin2hex(random_bytes(4)) . '/';
mkdir($garmentDir, 0775, true);
$kept[] = $garmentDir . 'peca.jpg';
file_put_contents($garmentDir . 'peca.jpg', $job['garments'][0]);
register_shutdown_function(static fn () => @rmdir($garmentDir));
$product = (int) $db->insert('catalog_product', ['attribute_set_id' => (int) $db->fetchOne("SELECT s.attribute_set_id FROM eav_attribute_set s JOIN eav_entity_type t ON t.entity_type_id = s.entity_type_id AND t.code = 'product' ORDER BY s.is_default DESC LIMIT 1"),
    'sku' => 'CF-ST-' . bin2hex(random_bytes(4)), 'name' => 'Peça do teste da Cloudflare'])['product_id'];
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
check($done['state'] === 'SUCCESS' && str_starts_with((string) $done['image_url'], '/media/tryon/') && file_get_contents($file) === $result && (float) $done['cost'] === 0.017,
    'startTryOn com a Cloudflare: SUCCESS na hora, a imagem guardada em /media/tryon com nome aleatório, custo estimado', $done);
check(strlen(pathinfo($file, PATHINFO_FILENAME)) === 32 && !str_contains((string) $done['response_json'], base64_encode(substr($result, 0, 60))), 'nome de 32 caracteres aleatórios; a resposta gravada sem a imagem');
$calls = count($fake->calls);
check($service->start($customer, $websiteId, $photo, $product)['cached'] && count($fake->calls) === $calls, 'a mesma prova em seguida vem guardada, sem chamar a Cloudflare');
$fake->answers = [$internal, $internal];
$other = $tmp . 'outra.jpg';
file_put_contents($other, $jpeg(900, 1400, [1, 2, 3]));
try {
    $service->start($customer, $websiteId, $other, $product);
    $busy = 'none';
} catch (\Iter\Eav\Model\AttributeException $e) {
    $busy = $e->errorCode;
}
check($busy === 'TRYON_BUSY', 'Cloudflare com erro interno duas vezes: o cliente vê TRYON_BUSY');
touch($file, time() - 86400 - 60); // 24 h later: the file and the row both expired
$db->update('tryon_request', ['image_expires_at' => date('Y-m-d H:i:s', time() - 60)], ['tryon_id = ?' => $done['tryon_id']]);
check(TryonService::cleanImages() >= 1 && !is_file($file) && $service->get((int) $done['tryon_id'], $customer)['state'] === 'EXPIRED', 'a imagem guardada é apagada depois de 24 h, e a prova fica EXPIRED');

// --- live --------------------------------------------------------------------------------------------------
if (in_array('--live', $argv, true)) {
    $token = trim((string) @file_get_contents(getenv('HOME') . '/.config/iterstore-cloudflare-token'));
    $account = (string) getenv('CLOUDFLARE_ACCOUNT_ID');
    if ($token === '' || $account === '') {
        fwrite(STDERR, "--live precisa de ~/.config/iterstore-cloudflare-token e CLOUDFLARE_ACCOUNT_ID\n");
        exit(2);
    }
    $config(['tryon/cloudflare/account_id' => $account, 'tryon/cloudflare/api_token' => Crypt::encrypt($token), 'tryon/cloudflare/model' => 'flux-2-klein-9b']);
    $live = (new CloudflareProvider())->submit(['person' => TryonService::normalize((string) file_get_contents($argv[2] ?? '/home/esmerio/Imagens/foto_Lu2.jpg')),
        'garments' => [TryonService::normalize((string) file_get_contents($argv[3] ?? dirname(__DIR__) . '/public/media/eav/product/25/6db697c71fb97c91f97b8b3a43904b78.jpg'))], 'resultSeconds' => 86400]);
    $out = getcwd() . '/cloudflare-live.jpg'; // outside the temp folder, which is removed at the end
    file_put_contents($out, $live['result']['image']);
    check($live['result']['status'] === 'success' && getimagesizefromstring($live['result']['image']) !== false, "ao vivo: a Cloudflare gerou a prova ({$out})");
}

echo "Cloudflare: tudo certo.\n";
