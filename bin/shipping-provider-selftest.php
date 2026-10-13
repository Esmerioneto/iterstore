<?php

declare(strict_types=1);

// Shipping providers (Iter_Shipping + Iter_Melhorenvio) without touching the DB config or the network:
// config values are injected in memory and the Melhor Envio HTTP client is a fake.

require_once __DIR__ . '/../path.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once FUNCTIONS . 'bootstrap.php';

use Iter\Core\Config;
use Iter\Core\Crypt;
use Iter\Core\Db\Connection;
use Iter\Melhorenvio\Client\MelhorEnvioClient;
use Iter\Melhorenvio\Provider\MelhorEnvioProvider;
use Iter\Sales\Model\CartService;
use Iter\Shipping\Model\ShippingRateProviderRegistry;
use Iter\Shipping\Model\ShippingService;

function check(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FALHOU: {$message}\n"); exit(1); }
    echo "OK: {$message}\n";
}

$cacheDir = sys_get_temp_dir() . '/iter-melhorenvio-selftest-' . bin2hex(random_bytes(4)) . '/';
putenv('ITER_MELHORENVIO_CACHE_DIR=' . $cacheDir);
register_shutdown_function(static fn () => array_map('unlink', glob($cacheDir . '*') ?: []) && @rmdir($cacheDir));
$logSize = (int) @filesize(dirname(__DIR__) . '/var/log/iter-' . date('Y-m-d') . '.log');
$db = Connection::get()->adapter();
$websiteId = (int) $db->fetchOne($db->select()->from('store_website', ['website_id'])->order('website_id')->limit(1));
$values = new ReflectionProperty(Config::class, 'values');
Config::get('x');
// the real config minus Melhor Envio's own settings, so each case sets exactly what it needs
$real = array_map(static fn (array $scope) => array_filter($scope, static fn ($p) => !str_contains($p, 'melhorenvio') && !str_starts_with($p, 'shipping/'), ARRAY_FILTER_USE_KEY), $values->getValue());
$withConfig = static function (array $default, array $website) use ($values, $real, $websiteId): void {
    $v = $real;
    $v['default:0'] = $default + ($v['default:0'] ?? []);
    $v['websites:' . $websiteId] = $website + ($v['websites:' . $websiteId] ?? []);
    $values->setValue(null, $v);
};
$connected = ['melhorenvio/oauth/access_token' => Crypt::encrypt('tok'), 'melhorenvio/oauth/expires_at' => (string) (time() + 3600), 'melhorenvio/user_agent' => 'Selftest (dev@example.com)'];
$active = ['shipping/melhorenvio/active' => '1', 'shipping/melhorenvio/origin_postcode' => '01310-100'];

$fake = new class extends MelhorEnvioClient {
    public array $calls = [];
    public array $response = [];
    public function post(string $path, array $payload): array { $this->calls[] = [$path, $payload]; return $this->response; }
};
$cart = ['website_id' => $websiteId, 'subtotal' => 100.0, 'shipping_address' => ['postcode' => '20040-020'],
    'items' => [['product_id' => 0, 'sku' => 'SKU-1', 'quantity' => 2.0, 'price' => 50.0, 'row_total' => 100.0, 'weight' => 0.5]]];

// registry
$classes = array_map('get_class', (new ShippingRateProviderRegistry())->all());
check(in_array(MelhorEnvioProvider::class, $classes, true), 'o registro encontra o provedor do Melhor Envio (etc/shipping_provider.json)');

// provider: off / not connected / bad CEP = no call
$withConfig($connected, []);
check((new MelhorEnvioProvider($fake))->rates($cart) === [] && !$fake->calls, 'Melhor Envio desativado no website: sem cotação e sem chamada HTTP');
$withConfig([], $active);
check((new MelhorEnvioProvider($fake))->rates($cart) === [] && !$fake->calls, 'sem OAuth conectado: sem cotação e sem chamada HTTP');
$withConfig($connected, $active);
check((new MelhorEnvioProvider($fake))->rates(['shipping_address' => ['postcode' => '123']] + $cart) === [] && !$fake->calls, 'CEP de destino inválido: sem chamada HTTP');

// provider: payload and parsing
$fake->response = [
    ['id' => 1, 'name' => 'PAC', 'company' => ['name' => 'Correios'], 'price' => '25.40', 'custom_price' => '27.90', 'custom_delivery_time' => 8],
    ['id' => 2, 'name' => 'SEDEX', 'company' => ['name' => 'Correios'], 'price' => '41.10', 'delivery_time' => ['min' => 2, 'max' => 3]],
    ['id' => 3, 'name' => 'Jadlog', 'error' => 'Serviço indisponível para o trecho.'],
    ['id' => 4, 'name' => 'Sem preço', 'company' => ['name' => 'X']],
];
$rates = (new MelhorEnvioProvider($fake))->rates($cart);
[$path, $payload] = $fake->calls[0];
check($path === 'shipment/calculate' && $payload['from']['postal_code'] === '01310100' && $payload['to']['postal_code'] === '20040020', 'payload: origem e destino só com dígitos');
check($payload['products'][0] === ['id' => 'SKU-1', 'width' => 10.0, 'height' => 10.0, 'length' => 10.0, 'weight' => 0.5, 'insurance_value' => 50.0, 'quantity' => 2.0], 'payload: medidas padrão, peso do item, seguro unitário e quantidade');
check(!isset($payload['services']), 'payload: sem filtro de serviços quando a config está vazia');
check(array_keys($rates) === ['melhorenvio:1', 'melhorenvio:2'], 'serviços com erro ou sem preço ficam de fora');
check($rates['melhorenvio:1'] === ['code' => 'melhorenvio:1', 'label' => 'Correios — PAC', 'amount' => 27.9, 'days' => 8], 'custom_price e custom_delivery_time têm prioridade');
check($rates['melhorenvio:2']['days'] === 2, 'prazo em faixa usa o mínimo');

// provider: cache
$calls = count($fake->calls);
check((new MelhorEnvioProvider($fake))->rates($cart) === $rates && count($fake->calls) === $calls, 'mesmo carrinho e CEP: a cotação vem do cache, sem chamada HTTP');
(new MelhorEnvioProvider($fake))->rates(['shipping_address' => ['postcode' => '30130-010']] + $cart);
check(count($fake->calls) === $calls + 1, 'outro CEP: nova chamada HTTP');
(new MelhorEnvioProvider($fake))->rates(['items' => [['quantity' => 3.0] + $cart['items'][0]]] + $cart);
check(count($fake->calls) === $calls + 2, 'outra quantidade: nova chamada HTTP');
array_map('touch', glob($cacheDir . '*.json'), array_fill(0, count(glob($cacheDir . '*.json')), time() - 601));
(new MelhorEnvioProvider($fake))->rates($cart);
check(count($fake->calls) === $calls + 3 && count(glob($cacheDir . '*.json')) === 1, 'depois de 10 minutos: nova chamada HTTP e os arquivos vencidos são apagados');
$failing = new class extends MelhorEnvioClient {
    public int $calls = 0;
    public function post(string $path, array $payload): array { $this->calls++; throw new RuntimeException('HTTP 500'); }
};
$other = ['shipping_address' => ['postcode' => '40010-000']] + $cart;
foreach ([1, 2] as $try) { try { (new MelhorEnvioProvider($failing))->rates($other); } catch (RuntimeException) {} }
check($failing->calls === 2, 'falha da API não vai para o cache: a próxima leitura tenta de novo');

$withConfig($connected, $active + ['shipping/melhorenvio/services' => '1, 2']);
$fake->calls = [];
(new MelhorEnvioProvider($fake))->rates($cart);
check($fake->calls[0][1]['services'] === '1,2', 'filtro de serviços enviado como IDs separados por vírgula');
$withConfig($connected, $active + ['shipping/melhorenvio/services' => 'pac']);
try { (new MelhorEnvioProvider($fake))->rates($cart); check(false, 'IDs de serviço inválidos recusados'); }
catch (RuntimeException) { check(true, 'IDs de serviço inválidos recusados'); }

// ShippingService: a failing provider doesn't take the other methods down
$withConfig(['melhorenvio/user_agent' => ''] + $connected, $active + ['shipping/pickup/active' => '1']); // client throws before any HTTP
$service = (new ShippingService())->rates($cart, '50010-000'); // a CEP with no cached quote
check(isset($service['pickup']) && !array_filter(array_keys($service), static fn ($c) => str_starts_with($c, 'melhorenvio:')), 'provedor com erro é registrado no log e as outras formas de entrega continuam');
$withConfig([], ['shipping/pickup/active' => '1']);
$service = (new ShippingService())->rates(['shipping_address' => null] + $cart, '20040-020');
check(isset($service['pickup']), 'rates() aceita o CEP de destino sem endereço salvo');

// GraphQL shippingQuotes: a guest cart, a CEP, nothing saved
$values->setValue(null, null);
$schema = Iter\Core\GraphQl\Schema::build();
check($schema->getType('MelhorEnvioQuote') === null && $schema->getQueryType()->hasField('shippingQuotes'), 'só existe o shippingQuotes do Iter_Shipping (sem MelhorEnvioQuote)');
$token = (new CartService())->create($websiteId);
$bad = GraphQL\GraphQL::executeQuery($schema, 'query($c:String){ shippingQuotes(cartId:$c, destinationCep:"123") { code } }', null, null, ['c' => $token])->toArray();
check(($bad['errors'][0]['extensions']['code'] ?? null) === 'SHIPPING_CEP_INVALID', 'shippingQuotes recusa CEP com menos de 8 dígitos');
$ok = GraphQL\GraphQL::executeQuery($schema, 'query($c:String){ shippingQuotes(cartId:$c, destinationCep:"20040-020") { code label amount days } }', null, null, ['c' => $token])->toArray();
check(!isset($ok['errors']) && is_array($ok['data']['shippingQuotes']), 'shippingQuotes responde para um carrinho de visitante');
$saved = $db->fetchOne($db->select()->from('sales_quote', ['shipping_address'])->where('token_hash = ?', hash('sha256', $token)));
check($saved === null || $saved === false, 'shippingQuotes não grava o endereço no carrinho');
$db->delete('sales_quote', ['token_hash = ?' => hash('sha256', $token)]);

$log = dirname(__DIR__) . '/var/log/iter-' . date('Y-m-d') . '.log';
check(!str_contains(substr((string) @file_get_contents($log), $logSize), 'Falha ao consultar o Melhor Envio (HTTP'), 'nenhuma chamada real à API do Melhor Envio durante o teste');

echo "Todos os testes passaram.\n";
