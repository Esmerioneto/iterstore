<?php

declare(strict_types=1);

// Shipping providers (Iter_Shipping + Iter_Melhorenvio + Iter_Correios) without touching the DB config or the network:
// config values are injected in memory and the HTTP clients are fakes (Guzzle's MockHandler for the Correios clients).

require_once __DIR__ . '/../path.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once FUNCTIONS . 'bootstrap.php';

use Iter\Core\Config;
use Iter\Core\Crypt;
use Iter\Core\Db\Connection;
use Iter\Correios\Client\CorreiosApiClient;
use Iter\Correios\Client\TokenClient;
use Iter\Correios\Provider\CorreiosProvider;
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
$real = array_map(static fn (array $scope) => array_filter($scope, static fn ($p) => !str_contains($p, 'melhorenvio') && !str_starts_with($p, 'correios/') && !str_starts_with($p, 'shipping/'), ARRAY_FILTER_USE_KEY), $values->getValue());
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

// Correios: Token API (Basic auth + posting card in the body), preco/v1 and prazo/v1, provider
check(in_array(CorreiosProvider::class, $classes, true), 'o registro encontra o provedor dos Correios (etc/shipping_provider.json)');
$correiosCache = sys_get_temp_dir() . '/iter-correios-selftest-' . bin2hex(random_bytes(4)) . '/';
putenv('ITER_CORREIOS_CACHE_DIR=' . $correiosCache);
register_shutdown_function(static fn () => array_map('unlink', glob($correiosCache . '*') ?: []) && @rmdir($correiosCache));
$http = static function (array $responses, array &$history): GuzzleHttp\Client {
    $stack = GuzzleHttp\HandlerStack::create(new GuzzleHttp\Handler\MockHandler($responses));
    $stack->push(GuzzleHttp\Middleware::history($history));

    return new GuzzleHttp\Client(['handler' => $stack]);
};
$credentials = ['correios/username' => 'user', 'correios/access_code' => Crypt::encrypt('code'), 'correios/posting_card' => '0067', 'correios/contract_number' => '999', 'correios/regional_code' => '20'];

$history = [];
$withConfig($credentials, []);
$data = (new TokenClient($http([new GuzzleHttp\Psr7\Response(201, [], '{"token":"tk","expiraEm":"2030-01-01T10:00:00"}')], $history)))->authenticate();
$request = $history[0]['request'];
check($request->getMethod() === 'POST' && (string) $request->getUri() === 'https://apihom.correios.com.br/token/v1/autentica/cartaopostagem', 'token: POST no cartaopostagem da homologação');
check($request->getHeaderLine('Authorization') === 'Basic ' . base64_encode('user:code'), 'token: Basic com o usuário e o código de acesso');
check(json_decode((string) $request->getBody(), true) === ['numero' => '0067', 'contrato' => '999', 'dr' => 20] && $data['token'] === 'tk', 'token: cartão, contrato e DR no corpo');
$history = [];
$withConfig(['correios/environment' => 'producao'] + $credentials, []);
try { (new TokenClient($http([new GuzzleHttp\Psr7\Response(401, [], '{"msgs":["Usuário ou senha inválidos"]}')], $history)))->authenticate(); check(false, 'token: 401 vira erro'); }
catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'HTTP 401') && str_contains($e->getMessage(), 'Usuário ou senha inválidos') && str_starts_with((string) $history[0]['request']->getUri(), 'https://api.correios.com.br/'), 'token: produção no host de produção; 401 vira erro com a mensagem dos Correios'); }
$withConfig(['correios/access_code' => ''] + $credentials, []);
try { (new TokenClient($http([], $history)))->authenticate(); check(false, 'token: sem código de acesso, sem chamada'); }
catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'código de acesso'), 'token: sem código de acesso, erro antes de chamar a API'); }

// a valid saved token for these credentials is reused: GET with Bearer, nothing saved
$history = [];
$key = hash('sha256', implode('|', ['https://apihom.correios.com.br/', 'user', '0067', '999', '20']));
$withConfig($credentials + ['correios/oauth/token' => Crypt::encrypt('saved'), 'correios/oauth/expires_at' => (string) (time() + 3600), 'correios/oauth/key' => $key], []);
$price = (new CorreiosApiClient($http([new GuzzleHttp\Psr7\Response(200, [], '{"pcFinal":"26,30"}')], $history)))->get('preco/v1/nacional/03220', ['cepOrigem' => '01310100']);
$request = $history[0]['request'];
check((string) $request->getUri() === 'https://apihom.correios.com.br/preco/v1/nacional/03220?cepOrigem=01310100' && $request->getHeaderLine('Authorization') === 'Bearer saved' && $price['pcFinal'] === '26,30', 'API: preco/v1 sem versão duplicada, com o token salvo');

$correios = new class extends CorreiosApiClient {
    public array $calls = [];
    public array $responses = [];
    public function get(string $path, array $query = []): array
    {
        $this->calls[] = [$path, $query];
        $response = $this->responses[$path] ?? throw new RuntimeException('HTTP 503');

        return $response;
    }
};
$correiosActive = ['shipping/correios/active' => '1', 'shipping/correios/origin_postcode' => '01310-100', 'shipping/correios/services' => '03220, 03298, 04014'];
$withConfig($credentials, []);
check((new CorreiosProvider($correios))->rates($cart) === [] && !$correios->calls, 'Correios desativado no website: sem cotação e sem chamada');
$correios->responses = [
    'preco/v1/nacional/03220' => ['pcFinal' => '1.026,30'], 'prazo/v1/nacional/03220' => ['prazoEntrega' => 2],
    'preco/v1/nacional/03298' => ['pcFinal' => '25.40'], 'prazo/v1/nacional/03298' => ['prazoEntrega' => 7],
    'preco/v1/nacional/04014' => ['txErro' => 'Serviço indisponível'], 'prazo/v1/nacional/04014' => ['prazoEntrega' => 3],
];
$withConfig($credentials, $correiosActive);
$rates = (new CorreiosProvider($correios))->rates($cart);
check($correios->calls[0] === ['preco/v1/nacional/03220', ['psObjeto' => '1000', 'tpObjeto' => '2', 'comprimento' => '20', 'largura' => '15', 'altura' => '10', 'cepOrigem' => '01310100', 'cepDestino' => '20040020', 'nuContrato' => '999', 'nuDR' => '20']], 'preço: peso total em gramas, medidas padrão, CEPs só com dígitos, contrato e DR, sem valor declarado');
check($correios->calls[1] === ['prazo/v1/nacional/03220', ['cepOrigem' => '01310100', 'cepDestino' => '20040020']], 'prazo: origem e destino');
check($rates === ['correios:03220' => ['code' => 'correios:03220', 'label' => 'Correios — SEDEX', 'amount' => 1026.3, 'days' => 2], 'correios:03298' => ['code' => 'correios:03298', 'label' => 'Correios — PAC', 'amount' => 25.4, 'days' => 7]], 'cotações: "1.026,30" e "25.40" lidos, serviço com txErro fica de fora');
$calls = count($correios->calls);
check((new CorreiosProvider($correios))->rates($cart) === $rates && count($correios->calls) === $calls, 'mesmo carrinho e CEP: a cotação vem do cache, sem chamada');
$withConfig($credentials, $correiosActive + ['shipping/correios/declared_value' => '1']);
(new CorreiosProvider($correios))->rates($cart);
check(($correios->calls[$calls][1]['vlDeclarado'] ?? null) === '100.00', 'valor declarado ligado: soma dos itens');
unset($correios->responses['prazo/v1/nacional/03298']);
$correios->calls = [];
$other = ['shipping_address' => ['postcode' => '40010-000']] + $cart;
foreach ([1, 2] as $try) { $partial = (new CorreiosProvider($correios))->rates($other); }
check(array_keys($partial) === ['correios:03220'] && count($correios->calls) === 12, 'serviço com falha fica de fora e a cotação parcial não vai para o cache');
$withConfig(['correios/regional_code' => ''] + $credentials, $correiosActive);
try { (new CorreiosProvider($correios))->rates($cart); check(false, 'contrato sem DR recusado'); }
catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'DR'), 'contrato sem DR recusado antes de chamar a API'); }
$withConfig($credentials, ['shipping/correios/services' => '03220, sedex'] + $correiosActive);
try { (new CorreiosProvider($correios))->rates($cart); check(false, 'código de serviço inválido recusado'); }
catch (RuntimeException) { check(true, 'código de serviço inválido recusado'); }

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
