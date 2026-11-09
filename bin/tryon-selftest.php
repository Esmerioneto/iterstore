<?php

declare(strict_types=1);

// Provador virtual (TRYON-PLAN delivery 2) against the real DB, inside a transaction that is rolled back, with a fake
// provider (no network, no cost): the images, the "same request" reuse, the limits, the states and the API.

require_once __DIR__ . '/../path.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once FUNCTIONS . 'bootstrap.php';

use Iter\Core\Config;
use Iter\Core\Db\Connection;
use Iter\Core\GraphQl\Schema;
use Iter\Customer\Model\CustomerAuth;
use Iter\Customer\Model\Customer;
use Iter\Eav\Model\AttributeException;
use Iter\Tryon\Api\TryonProviderException;
use Iter\Tryon\Api\TryonProviderInterface;
use Iter\Tryon\Model\TryonService;

function check(bool $condition, string $message, mixed $got = null): void
{
    if (!$condition) {
        fwrite(STDERR, "FALHOU: {$message}\n" . ($got === null ? '' : 'obtido: ' . var_export($got, true) . "\n"));
        exit(1);
    }
    echo "OK: {$message}\n";
}

/** the AttributeException code $fn throws, or 'none' */
function code(callable $fn): string
{
    try {
        $fn();
    } catch (AttributeException $e) {
        return $e->errorCode;
    }

    return 'none';
}

if (!\isModuleEnabled('iter_tryon')) {
    echo "PULADO: Provador virtual (módulo desligado)\n";
    exit(0);
}

/** what the provider would do, decided by the test; records every call */
final class FakeProvider implements TryonProviderInterface
{
    public array $submits = [];
    public int $fetches = 0;
    public ?TryonProviderException $submitError = null;
    public array $next = ['status' => 'processing', 'progress' => 40, 'response' => ['data' => [['status' => 'processing']]]];

    public function code(): string { return 'fake'; }
    public function isConfigured(): bool { return true; }
    public function model(): string { return 'fake:vto@1'; }
    public function estimatedCost(int $garments): float { return 0.015; }

    public function submit(array $job): array
    {
        if ($this->submitError) {
            throw $this->submitError;
        }
        $this->submits[] = $job;

        return ['task' => 'task-' . count($this->submits) . '-' . bin2hex(random_bytes(3)), 'request' => ['model' => $this->model(), 'images' => '<sha256>']];
    }

    public function fetch(string $task): array
    {
        $this->fetches++;

        return $this->next;
    }
}

$tmp = sys_get_temp_dir() . '/iter-tryon-selftest-' . bin2hex(random_bytes(4)) . '/';
mkdir($tmp);
putenv('ITER_LOG_DIR=' . $tmp . 'log/');
$media = Config::mediaDir() . 'eav/selftest-tryon-' . bin2hex(random_bytes(4)) . '/';
mkdir($media, 0775, true);
$db = Connection::get()->adapter();
$db->beginTransaction();
// the month's spend already in this database (real provas): the ceilings below are on top of it
$spentBefore = (float) $db->fetchOne("SELECT COALESCE(SUM(cost), 0) FROM tryon_request WHERE created_at >= :month AND status <> 'error'", ['month' => date('Y-m-01 00:00:00')]);
$budget = static fn (float $ceiling): string => sprintf('%.4F', $spentBefore + $ceiling);
register_shutdown_function(static function () use ($db, $tmp, $media): void {
    $db->inTransaction() && $db->rollBack();
    exec('rm -rf ' . escapeshellarg($tmp) . ' ' . escapeshellarg($media));
});

// config in memory only: the module on, small limits
$values = new ReflectionProperty(Config::class, 'values');
Config::get('x');
$real = $values->getValue();
$config = static function (array $set) use ($values, $real): void {
    $v = $real;
    $v['default:0'] = $set + ['catalog/tryon/active' => '1', 'catalog/tryon/daily_per_customer' => '3', 'catalog/tryon/concurrent' => '4',
        'catalog/tryon/monthly_budget_usd' => '30', 'catalog/tryon/result_hours' => '24', 'catalog/tryon/max_upload_kb' => '4096',
        'tryon/cloudflare/account_id' => '', 'tryon/gemini/api_key' => '', 'catalog/tryon/provider' => ''] + ($v['default:0'] ?? []); // no real provider configured: the fake one is passed in
    $values->setValue(null, $v);
};
$config([]);

// --- images ----------------------------------------------------------------------------------------------
$png = static function (int $w, int $h, array $rgb): string {
    $img = imagecreatetruecolor($w, $h);
    imagesavealpha($img, true);
    imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127)); // transparent
    imagefilledrectangle($img, (int) ($w / 4), (int) ($h / 4), (int) ($w * 3 / 4), (int) ($h * 3 / 4), imagecolorallocate($img, ...$rgb));
    ob_start();
    imagepng($img);

    return (string) ob_get_clean();
};
$jpeg = static function (int $w, int $h, array $rgb): string {
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, ...$rgb));
    ob_start();
    imagejpeg($img, null, 95);

    return (string) ob_get_clean();
};
$big = TryonService::normalize($png(3000, 2000, [200, 30, 30]));
$info = getimagesizefromstring($big);
check([$info[0], $info[1], $info[2]] === [1536, 1024, IMAGETYPE_JPEG], 'PNG de 3000×2000 vira JPEG com o lado maior em 1536', $info);
$corner = imagecolorsforindex($i = imagecreatefromstring($big), imagecolorat($i, 2, 2));
check($corner['red'] > 245 && $corner['green'] > 245 && $corner['blue'] > 245, 'a transparência vira fundo branco', $corner);
check(hash('sha256', TryonService::normalize($png(3000, 2000, [200, 30, 30]))) === hash('sha256', $big), 'a mesma imagem dá os mesmos bytes (o hash acha a consulta repetida)');
$withExif = substr_replace($jpeg(800, 1200, [10, 120, 200]), "\xFF\xE1\x00\x18Exif\x00\x00GPS-latitude-123", 2, 0);
check(str_contains($withExif, 'Exif') && getimagesizefromstring($withExif) !== false, 'a foto de teste tem um bloco EXIF');
check(!str_contains(TryonService::normalize($withExif), 'Exif') && !str_contains(TryonService::normalize($withExif), 'GPS'), 'o EXIF (localização) não sai na foto enviada');
check(code(static fn () => TryonService::normalize($jpeg(100, 400, [0, 0, 0]))) === 'INVALID_IMAGE', 'foto com lado menor que 256 px: INVALID_IMAGE');
check(code(static fn () => TryonService::normalize("não sou imagem\n")) === 'INVALID_IMAGE', 'texto renomeado para .jpg: INVALID_IMAGE');

// --- data: two customers, a product with an image and "Provador virtual" on, one without -------------------
$websiteId = (int) $db->fetchOne('SELECT website_id FROM store_website ORDER BY website_id LIMIT 1');
$customerSet = (int) $db->fetchOne("SELECT s.attribute_set_id FROM eav_attribute_set s JOIN eav_entity_type t ON t.entity_type_id = s.entity_type_id AND t.code = 'customer' ORDER BY s.is_default DESC, s.attribute_set_id LIMIT 1");
$group = (int) $db->fetchOne('SELECT customer_group_id FROM customer_group ORDER BY customer_group_id LIMIT 1');
$newCustomer = static fn (): int => (int) $db->insert('customer_entity', ['website_id' => $websiteId, 'attribute_set_id' => $customerSet, 'group_id' => $group])['customer_id'];
[$ana, $bia, $caio] = [$newCustomer(), $newCustomer(), $newCustomer()];
$customerAttr = (int) $db->fetchOne("SELECT a.attribute_id FROM eav_attribute a JOIN eav_entity_type t ON t.entity_type_id = a.entity_type_id AND t.code = 'customer' WHERE a.code = :code", ['code' => TryonService::ATTRIBUTE]);
foreach ([$ana, $bia] as $allowed) { // Caio isn't: "Pode usar o provador virtual" off, the default
    $db->insert('eav_value_boolean', ['attribute_id' => $customerAttr, 'entity_id' => $allowed, 'value' => true]);
}
$productSet = (int) $db->fetchOne("SELECT s.attribute_set_id FROM eav_attribute_set s JOIN eav_entity_type t ON t.entity_type_id = s.entity_type_id AND t.code = 'product' ORDER BY s.is_default DESC, s.attribute_set_id LIMIT 1");
$attr = static fn (string $code): int => (int) $db->fetchOne("SELECT a.attribute_id FROM eav_attribute a JOIN eav_entity_type t ON t.entity_type_id = a.entity_type_id AND t.code = 'product' WHERE a.code = :code", ['code' => $code]);
$imageCode = (string) $db->fetchOne("SELECT a.code FROM eav_attribute a JOIN eav_entity_type t ON t.entity_type_id = a.entity_type_id AND t.code = 'product' WHERE a.input_type = 'image' ORDER BY a.sort_order, a.attribute_id LIMIT 1");
$garmentFile = $media . 'camisa.jpg';
file_put_contents($garmentFile, $jpeg(1086, 1600, [90, 160, 220]));
$newProduct = static function (bool $tryon, bool $image = true) use ($db, $productSet, $attr, $imageCode, $media): int {
    $id = (int) $db->insert('catalog_product', ['attribute_set_id' => $productSet, 'sku' => 'TRYON-ST-' . bin2hex(random_bytes(4)), 'name' => 'Camisa do teste do provador'])['product_id'];
    $tryon && $db->insert('eav_value_boolean', ['attribute_id' => $attr(TryonService::ATTRIBUTE), 'entity_id' => $id, 'value' => true]);
    $image && $db->insert('eav_value_varchar', ['attribute_id' => $attr($imageCode), 'entity_id' => $id, 'value' => basename($media) . '/camisa.jpg']);

    return $id;
};
$shirt = $newProduct(true);
$plain = $newProduct(false);
$noImage = $newProduct(true, false);
$person = $tmp . 'pessoa.jpg';
file_put_contents($person, $jpeg(634, 1013, [180, 160, 140]));
$otherPhoto = $tmp . 'outra.jpg';
file_put_contents($otherPhoto, $jpeg(634, 1013, [40, 60, 80]));

$fake = new FakeProvider();
$service = new TryonService($fake);
check($service->garmentFile($shirt) === $garmentFile, 'a imagem da peça é o arquivo da primeira imagem do produto');
check($service->productAllowed($shirt) && !$service->productAllowed($plain), '"Provador virtual" ligado no produto libera; desligado, não');
check($service->customerAllowed($ana) && !$service->customerAllowed($caio), '"Pode usar o provador virtual" no cadastro do cliente libera; sem ele (o padrão), não');
check(code(static fn () => $service->start($caio, $websiteId, $person, $shirt)) === 'TRYON_NOT_ALLOWED' && !$fake->submits, 'cliente sem permissão: TRYON_NOT_ALLOWED, sem chamar o provedor');
check(!$service->settings($caio)['enabled'] && $service->settings($ana)['enabled'], 'tryOnSettings.enabled: falso para o cliente sem permissão, verdadeiro para quem tem');

// --- start, poll, success --------------------------------------------------------------------------------
$first = $service->start($ana, $websiteId, $person, $shirt);
check([$first['state'], $first['cached'], count($fake->submits)] === ['PROCESSING', false, 1], 'startTryOn: PROCESSING, o provedor chamado uma vez', [$first['state'], $first['cached'], count($fake->submits)]);
$job = $fake->submits[0];
check($job['resultSeconds'] === 86400 && count($job['garments']) === 1 && getimagesizefromstring($job['person'])[2] === IMAGETYPE_JPEG && $job['garments'][0] === TryonService::normalize((string) file_get_contents($garmentFile)),
    'o provedor recebe a foto e a peça normalizadas e o tempo de vida (24 h)');
$row = $db->fetchRow('SELECT * FROM tryon_request WHERE tryon_id = :id', ['id' => $first['tryon_id']]);
check($row['provider'] === 'fake' && str_starts_with((string) $row['provider_task'], 'task-1-') && strlen($row['person_hash']) === 64 && !str_contains((string) $row['request_json'], base64_encode(substr($job['person'], 0, 30))),
    'a linha guarda provedor, tarefa e os hashes; request_json sem as imagens', $row);
$again = $service->get((int) $first['tryon_id'], $ana);
check([$again['state'], (int) $again['progress'], $fake->fetches] === ['PROCESSING', 40, 1], 'tryOn ainda processando: progresso 40', [$again['state'], $again['progress'], $fake->fetches]);
$service->get((int) $first['tryon_id'], $ana);
check($fake->fetches === 1, 'duas consultas em menos de 2 s perguntam ao provedor uma vez só');
$db->update('tryon_request', ['polled_at' => date('Y-m-d H:i:s', time() - 5)], ['tryon_id = ?' => $first['tryon_id']]);
$fake->next = ['status' => 'success', 'imageUrl' => 'https://im.example/prova.jpg', 'cost' => 0.0123, 'response' => ['data' => [['status' => 'success', 'imageURL' => 'https://im.example/prova.jpg']]]];
$done = $service->get((int) $first['tryon_id'], $ana);
check([$done['state'], $done['image_url'], (float) $done['cost'], $fake->fetches] === ['SUCCESS', 'https://im.example/prova.jpg', 0.0123, 2] && str_contains((string) $done['response_json'], 'prova.jpg'),
    'tryOn com o provedor pronto: SUCCESS, URL e custo gravados, resposta guardada', [$done['state'], $done['image_url'], $done['cost'], $fake->fetches]);
check(abs(strtotime($done['image_expires_at']) - strtotime($done['created_at']) - 86400) < 2, 'a imagem vale 24 h');
check(code(static fn () => $service->get((int) $first['tryon_id'], $bia)) === 'TRYON_NOT_FOUND', 'a prova de outro cliente: TRYON_NOT_FOUND');

// --- same request in 24 h ------------------------------------------------------------------------------
$reused = $service->start($ana, $websiteId, $person, $shirt);
check([$reused['tryon_id'], $reused['cached'], $reused['state'], count($fake->submits), (int) $db->fetchOne('SELECT hits FROM tryon_request WHERE tryon_id = ?', [$first['tryon_id']])] === [$first['tryon_id'], true, 'SUCCESS', 1, 1],
    'a mesma foto com o mesmo produto: o resultado guardado, sem chamar o provedor, hits = 1');
check($service->settings($ana)['remaining_today'] === 2, 'a consulta repetida não conta no limite do dia (3 − 1 = 2)');
$db->insert('eav_value_varchar', ['attribute_id' => $attr(TryonService::CATEGORY_ATTRIBUTE), 'entity_id' => $shirt, 'value' => 'bottoms']);
$asBottoms = $service->start($ana, $websiteId, $person, $shirt);
check($asBottoms['tryon_id'] !== $first['tryon_id'] && !$asBottoms['cached'] && end($fake->submits)['category'] === 'bottoms', 'o mesmo produto com outro "Tipo de peça no provador": gera de novo, com o tipo novo');
$db->delete('eav_value_varchar', ['attribute_id = ?' => $attr(TryonService::CATEGORY_ATTRIBUTE), 'entity_id = ?' => $shirt]);
$db->delete('tryon_request', ['tryon_id = ?' => $asBottoms['tryon_id']]);
array_pop($fake->submits);
$bias = $service->start($bia, $websiteId, $person, $shirt);
check($bias['tryon_id'] !== $first['tryon_id'] && !$bias['cached'] && count($fake->submits) === 2, 'outro cliente com a mesma foto: gera a sua (nunca recebe a de outro)');
$db->update('tryon_request', ['image_expires_at' => date('Y-m-d H:i:s', time() + 300)], ['tryon_id = ?' => $first['tryon_id']]);
$db->update('tryon_request', ['status' => 'error', 'error_code' => 'TRYON_FAILED'], ['tryon_id = ?' => $bias['tryon_id']]); // Bia's out of the way
$fake->next = ['status' => 'processing', 'response' => []];
$fresh = $service->start($ana, $websiteId, $person, $shirt);
check($fresh['tryon_id'] !== $first['tryon_id'] && !$fresh['cached'], 'o resultado guardado a 5 minutos de vencer: gera de novo');
$whileProcessing = $service->start($ana, $websiteId, $person, $shirt);
check($whileProcessing['tryon_id'] === $fresh['tryon_id'] && $whileProcessing['state'] === 'PROCESSING' && count($fake->submits) === 3, 'a mesma consulta ainda processando: devolve a mesma, sem chamar de novo');
check(code(static fn () => $service->start($ana, $websiteId, $otherPhoto, $shirt)) === 'TRYON_IN_PROGRESS', 'outra foto com uma prova em andamento: TRYON_IN_PROGRESS');

// --- timeout, errors ---------------------------------------------------------------------------------------
$db->update('tryon_request', ['created_at' => date('Y-m-d H:i:s', time() - TryonService::TIMEOUT_SECONDS - 1)], ['tryon_id = ?' => $fresh['tryon_id']]);
$timedOut = $service->get((int) $fresh['tryon_id'], $ana);
check([$timedOut['state'], $timedOut['error_code'], TryonService::message($timedOut['error_code'])] === ['ERROR', 'TRYON_TIMEOUT', 'Demorou demais. Tente de novo.'], 'sem resposta em 120 s: TRYON_TIMEOUT');
$db->update('tryon_request', ['created_at' => date('Y-m-d H:i:s')], ['tryon_id = ?' => $fresh['tryon_id']]); // back to today, for the daily count
$fake->next = ['status' => 'error', 'error' => TryonProviderException::REFUSED, 'providerCode' => 'nsfw', 'response' => ['data' => [['NSFWContent' => true]]]];
$refused = $service->start($ana, $websiteId, $otherPhoto, $shirt);
$refused = $service->get((int) $refused['tryon_id'], $ana);
check([$refused['state'], $refused['error_code'], $refused['provider_code'], $refused['image_url']] === ['ERROR', 'TRYON_REFUSED', 'nsfw', null], 'conteúdo recusado pelo provedor: TRYON_REFUSED, sem URL');
$fake->submitError = new TryonProviderException(TryonProviderException::UNAVAILABLE, 'sem crédito', 'thirdPartyInsufficientCredits', ['errors' => [['code' => 'thirdPartyInsufficientCredits']]]);
$thirdPhoto = $tmp . 'terceira.jpg';
file_put_contents($thirdPhoto, $jpeg(700, 1000, [1, 2, 3]));
check(code(static fn () => $service->start($ana, $websiteId, $thirdPhoto, $shirt)) === 'TRYON_UNAVAILABLE', 'provedor sem crédito: TRYON_UNAVAILABLE');
check($db->fetchRow("SELECT status, error_code, provider_code FROM tryon_request WHERE customer_id = :c ORDER BY tryon_id DESC LIMIT 1", ['c' => $ana]) === ['status' => 'error', 'error_code' => 'TRYON_UNAVAILABLE', 'provider_code' => 'thirdPartyInsufficientCredits'],
    'a recusa fica gravada com o código do provedor');
foreach ([TryonProviderException::BUSY => 'TRYON_BUSY', TryonProviderException::FAILED => 'TRYON_FAILED'] as $layer => $ours) {
    $fake->submitError = new TryonProviderException($layer, 'x');
    check(code(static fn () => $service->start($ana, $websiteId, $thirdPhoto, $shirt)) === $ours, "erro {$layer} do provedor: {$ours}");
}
$fake->submitError = null;
$fake->next = ['status' => 'processing', 'response' => []];
check(code(static fn () => $service->get((int) $refused['tryon_id'], $ana)) === 'none' && $service->settings($ana)['remaining_today'] === 2,
    'as provas com erro não contam no limite do dia');

// --- limits ------------------------------------------------------------------------------------------------
$service->start($ana, $websiteId, $thirdPhoto, $shirt); // 2nd of the day that counts
$db->update('tryon_request', ['status' => 'success', 'image_url' => 'https://im.example/2.jpg', 'image_expires_at' => date('Y-m-d H:i:s', time() + 86400)], ['customer_id = ?' => $ana, 'status = ?' => 'processing']);
$fourth = $tmp . 'quarta.jpg';
file_put_contents($fourth, $jpeg(700, 1000, [4, 5, 6]));
$service->start($ana, $websiteId, $fourth, $shirt); // 3rd
$db->update('tryon_request', ['status' => 'success', 'image_url' => 'https://im.example/3.jpg', 'image_expires_at' => date('Y-m-d H:i:s', time() + 86400)], ['customer_id = ?' => $ana, 'status = ?' => 'processing']);
$fifth = $tmp . 'quinta.jpg';
file_put_contents($fifth, $jpeg(700, 1000, [7, 8, 9]));
$got = code(static fn () => $service->start($ana, $websiteId, $fifth, $shirt));
check($got === 'TRYON_DAILY_LIMIT' && TryonService::message('TRYON_DAILY_LIMIT') === 'Você já usou o provador 3 vezes hoje. Volte amanhã.', '4ª prova do dia com limite 3: TRYON_DAILY_LIMIT', [$got, $db->fetchAll('SELECT tryon_id, status, error_code, created_at FROM tryon_request WHERE customer_id = ?', [$ana])]);
check($service->start($ana, $websiteId, $fourth, $shirt)['cached'], 'no limite do dia, uma prova repetida ainda vem (não conta)');
$config(['catalog/tryon/concurrent' => '1', 'catalog/tryon/daily_per_customer' => '0']);
$service->start($bia, $websiteId, $otherPhoto, $shirt);
check(code(static fn () => $service->start($ana, $websiteId, $fifth, $shirt)) === 'TRYON_BUSY', 'gerações ao mesmo tempo na loja no máximo: TRYON_BUSY');
$db->update('tryon_request', ['status' => 'error'], ['status = ?' => 'processing', 'customer_id = ?' => $bia]);
$config(['catalog/tryon/daily_per_customer' => '0', 'catalog/tryon/monthly_budget_usd' => $budget(0.02)]);
check($service->available() && code(static fn () => $service->start($ana, $websiteId, $fifth, $shirt)) === 'TRYON_UNAVAILABLE',
    'teto do mês que a próxima prova passaria (0,0123 gasto + 0,015 estimado > 0,02, além do que o banco já gastou): startTryOn dá TRYON_UNAVAILABLE');
$config(['catalog/tryon/daily_per_customer' => '0', 'catalog/tryon/monthly_budget_usd' => $budget(0.012)]);
check(!$service->available() && !$service->settings($ana)['enabled'] && code(static fn () => $service->start($ana, $websiteId, $fifth, $shirt)) === 'TRYON_UNAVAILABLE',
    'teto do mês atingido (0,0123 ≥ 0,012, além do que o banco já gastou): o provador some do app');
$config(['catalog/tryon/daily_per_customer' => '0']);
$config(['catalog/tryon/max_upload_kb' => '100']);
file_put_contents($tmp . 'grande.jpg', random_bytes(150 * 1024));
check(code(static fn () => $service->start($ana, $websiteId, $tmp . 'grande.jpg', $shirt)) === 'FILE_TOO_LARGE', 'foto acima do tamanho máximo: FILE_TOO_LARGE');
$config([]);
check(code(static fn () => $service->start($ana, $websiteId, $fifth, $plain)) === 'PRODUCT_NOT_TRYON', 'produto sem "Provador virtual": PRODUCT_NOT_TRYON');
check(code(static fn () => $service->start($ana, $websiteId, $fifth, $noImage)) === 'INVALID_IMAGE', 'produto liberado sem imagem: INVALID_IMAGE');
check(code(static fn () => $service->start($ana, $websiteId, $fifth, 0)) === 'PRODUCT_NOT_FOUND', 'produto inexistente: PRODUCT_NOT_FOUND');
$db->update('tryon_request', ['image_expires_at' => date('Y-m-d H:i:s', time() - 1)], ['tryon_id = ?' => $first['tryon_id']]);
check($service->get((int) $first['tryon_id'], $ana)['state'] === 'EXPIRED', 'imagem vencida: EXPIRED');
$config(['catalog/tryon/active' => '0']);
check(code(static fn () => $service->start($ana, $websiteId, $fifth, $shirt)) === 'TRYON_DISABLED' && !$service->settings($ana)['enabled'], 'provador desligado: TRYON_DISABLED');
$config([]);
check(code(static fn () => (new TryonService())->start($ana, $websiteId, $fifth, $shirt)) === 'TRYON_DISABLED', 'sem provedor instalado: TRYON_DISABLED');

// --- API -------------------------------------------------------------------------------------------------
$gql = static function (string $query, array $variables = []): array {
    $result = \GraphQL\GraphQL::executeQuery(Schema::build(), $query, null, null, $variables)->toArray();

    return [$result['data'] ?? null, $result['errors'][0]['extensions']['code'] ?? null];
};
$as = static function (?int $customerId): void {
    (new ReflectionProperty(CustomerAuth::class, 'customer'))->setValue(null, $customerId === null ? null : (new Customer())->load($customerId));
};
$as(null);
check($gql('{ tryOnSettings { enabled remainingToday tips termsText } }')[0]['tryOnSettings']['enabled'] === false, 'tryOnSettings sem login: enabled false');
check($gql('{ tryOn(id: "' . $first['tryon_id'] . '") { id } }')[1] === 'UNAUTHENTICATED', 'tryOn sem login: UNAUTHENTICATED');
check($gql('mutation($p: Upload!) { startTryOn(person: $p, productId: "' . $shirt . '") { id } }', ['p' => ['tmp_name' => $person, 'error' => 0, 'name' => 'p.jpg', 'size' => 1]])[1] === 'UNAUTHENTICATED', 'startTryOn sem login: UNAUTHENTICATED');
$as($ana);
$settings = $gql('{ tryOnSettings { enabled remainingToday maxUploadKb tips termsText } }')[0]['tryOnSettings'];
check($settings['maxUploadKb'] === 4096 && count($settings['tips']) === 4 && str_contains($settings['termsText'], 'parceiro'), 'tryOnSettings com login: dicas e consentimento das Configurações', $settings);
[$data] = $gql('{ tryOn(id: "' . $done['tryon_id'] . '") { id status imageUrl errorCode cached product { id } } }');
check($data['tryOn']['status'] === 'EXPIRED' && $data['tryOn']['imageUrl'] === null && (int) $data['tryOn']['product']['id'] === $shirt, 'tryOn pela API: status, sem URL vencida, o produto', $data);
[$data] = $gql('{ tryOn(id: "' . $refused['tryon_id'] . '") { status errorCode errorMessage } }');
check($data['tryOn'] === ['status' => 'ERROR', 'errorCode' => 'TRYON_REFUSED', 'errorMessage' => 'Não foi possível usar esta foto. Tente outra.'], 'tryOn com erro: o código e a mensagem ao cliente', $data);
$as($bia);
check($gql('{ tryOn(id: "' . $done['tryon_id'] . '") { id } }')[1] === 'TRYON_NOT_FOUND', 'tryOn de outro cliente pela API: TRYON_NOT_FOUND');
check($gql('mutation($p: Upload!) { startTryOn(person: $p, productId: "' . $shirt . '") { id } }', ['p' => ['tmp_name' => $person, 'error' => 0, 'name' => 'p.jpg', 'size' => 1]])[1] === 'FILE_UPLOAD_FAILED',
    'startTryOn só aceita arquivo enviado de verdade (is_uploaded_file): FILE_UPLOAD_FAILED');
[$data] = $gql('{ a: product(id: "' . $shirt . '") { tryOnAvailable } }');
check($data['a']['tryOnAvailable'] === false, 'Product.tryOnAvailable: false sem provedor instalado, mesmo com o produto liberado', $data);
$as($caio);
check($gql('mutation { updateMe(attributes: [{code: "tryon_enabled", value: "1"}]) { id } }')[1] === 'ATTRIBUTE_READ_ONLY' && !$service->customerAllowed($caio),
    'o cliente não se libera sozinho pelo "Minha conta" (updateMe): ATTRIBUTE_READ_ONLY');
$as(null);
check($gql('mutation { registerCustomer(email: "tryon-st@selftest.local", password: "segredo123", firstname: "T", lastname: "S", type: PF, taxvat: "52998224725", attributes: [{code: "tryon_enabled", value: "1"}]) { token } }')[1] === 'ATTRIBUTE_READ_ONLY',
    'nem no cadastro pelo app (registerCustomer): ATTRIBUTE_READ_ONLY');
check(str_contains((string) file_get_contents(dirname(__DIR__) . '/schema.graphql'), 'startTryOn('), 'schema.graphql tem o provador');

echo "Provador virtual: tudo certo.\n";
