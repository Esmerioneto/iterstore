<?php

declare(strict_types=1);

namespace Iter\Correios\Provider;

use Iter\Catalog\Model\Product;
use Iter\Core\Config;
use Iter\Core\Log;
use Iter\Correios\Client\CorreiosApiClient;
use Iter\Correios\Client\TokenClient;
use Iter\Eav\Model\ValueService;
use Iter\Shipping\Api\ShippingRateProviderInterface;
use RuntimeException;

/** Quotes the configured national Correios services: API Preço + API Prazo, one package for the whole cart. */
class CorreiosProvider implements ShippingRateProviderInterface
{
    /** Every cart read quotes again (cart, each mutation, placeOrder): the same quote is reused this long */
    private const CACHE_SECONDS = 600;

    private const LABELS = ['03220' => 'SEDEX', '03298' => 'PAC', '03158' => 'SEDEX 10', '03140' => 'SEDEX 12'];

    private readonly string $cacheDir;

    public function __construct(private readonly ?CorreiosApiClient $client = null, ?string $cacheDir = null)
    {
        // ITER_CORREIOS_CACHE_DIR: another folder, used by the selftest so it doesn't touch the real cache
        $this->cacheDir = $cacheDir ?? (getenv('ITER_CORREIOS_CACHE_DIR') ?: dirname(SRC) . '/var/cache/correios/');
    }

    /**
     * @param array{website_id:int,items:array,subtotal:float,shipping_address:?array} $cart
     * @return array<string,array{code:string,label:string,amount:float,days:int}>
     */
    public function rates(array $cart): array
    {
        $websiteId = (int) $cart['website_id'];
        if (!Config::get('shipping/correios/active', $websiteId)) {
            return [];
        }
        $origin = preg_replace('/\D/', '', (string) Config::get('shipping/correios/origin_postcode', $websiteId));
        $destination = preg_replace('/\D/', '', (string) ($cart['shipping_address']['postcode'] ?? ''));
        if (strlen($origin) !== 8 || strlen($destination) !== 8 || empty($cart['items'])) {
            return [];
        }
        $codes = array_values(array_unique(array_filter(
            array_map('trim', explode(',', (string) Config::get('shipping/correios/services', $websiteId))),
            static fn ($code) => $code !== ''
        )));
        if (!$codes) {
            throw new RuntimeException('Informe os códigos de serviço dos Correios em Configurações → Correios.');
        }
        if (array_filter($codes, static fn ($code) => !preg_match('/^\d{5}$/', $code))) {
            throw new RuntimeException('Os códigos de serviço dos Correios devem ter 5 dígitos, separados por vírgula (ex.: 03220, 03298).');
        }
        $contract = trim((string) Config::get('correios/contract_number'));
        $regional = trim((string) Config::get('correios/regional_code'));
        if (($contract === '') !== ($regional === '')) {
            // API Preço: nuDR is required whenever nuContrato goes
            throw new RuntimeException('Informe o número do contrato e a DR dos Correios juntos (ou nenhum dos dois).');
        }

        $query = $this->package($cart['items'], $websiteId) + ['cepOrigem' => $origin, 'cepDestino' => $destination];
        if ($contract !== '') {
            $query += ['nuContrato' => $contract, 'nuDR' => $regional];
        }
        if (!Config::get('shipping/correios/declared_value', $websiteId) || (float) $query['vlDeclarado'] <= 0) {
            unset($query['vlDeclarado']);
        }
        if ($query['psObjeto'] === '0') {
            return [];
        }

        $file = $this->cacheDir . hash('sha256', json_encode([TokenClient::host(), $codes, $query])) . '.json';
        if (is_file($file) && filemtime($file) > time() - self::CACHE_SECONDS) {
            $cached = json_decode((string) file_get_contents($file), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $client = $this->client ?? new CorreiosApiClient();
        $rates = [];
        $failed = false;
        foreach ($codes as $code) {
            try {
                $price = $client->get('preco/v1/nacional/' . $code, $query);
                $days = $client->get('prazo/v1/nacional/' . $code, ['cepOrigem' => $origin, 'cepDestino' => $destination]);
            } catch (\Throwable $e) {
                $failed = true;
                Log::error('Correios: serviço indisponível para esta cotação.', ['website_id' => $websiteId, 'service' => $code, 'reason' => $e->getMessage()]);
                continue;
            }
            $amount = $this->number($price['pcFinal'] ?? null);
            if (!empty($price['txErro']) || !empty($days['txErro']) || $amount === null || $amount < 0) {
                continue;
            }
            $label = self::LABELS[$code] ?? (trim((string) ($price['noProduto'] ?? $price['nomeProduto'] ?? '')) ?: $code);
            $rates['correios:' . $code] = [
                'code' => 'correios:' . $code,
                'label' => 'Correios — ' . $label,
                'amount' => round($amount, 2),
                'days' => max(0, min(365, (int) ($days['prazoEntrega'] ?? 0))),
            ];
        }
        // a service that failed (timeout, 5xx) is retried on the next read instead of hidden for 10 minutes
        if (!$failed) {
            $this->cache($file, $rates);
        }

        return $rates;
    }

    /**
     * The whole cart as one box: total weight in grams, the largest of each dimension in cm, the item total.
     * ponytail: no real packing; carts that need several boxes get an approximate quote
     */
    private function package(array $items, int $websiteId): array
    {
        $ids = array_values(array_unique(array_map(static fn ($item) => (int) ($item['product_id'] ?? 0), $items)));
        $dimensions = [];
        if (\isModuleEnabled('iter_eav') && $ids) {
            $dimensions = (new ValueService())->loadMany(Product::ENTITY_TYPE, $ids, ['width', 'height', 'length']);
        }
        $grams = 0.0;
        $value = 0.0;
        $size = ['comprimento' => 1.0, 'largura' => 1.0, 'altura' => 1.0];
        foreach ($items as $item) {
            $quantity = (float) ($item['quantity'] ?? 0);
            if ($quantity <= 0) {
                continue;
            }
            $weight = (float) ($item['weight'] ?? 0);
            if ($weight <= 0) {
                $weight = (float) Config::get('shipping/correios/default_weight', $websiteId);
            }
            if ($weight <= 0) {
                throw new RuntimeException('Configure um peso padrão de produto maior que zero em Configurações → Correios.');
            }
            $grams += $weight * 1000 * $quantity;
            $values = $dimensions[(int) ($item['product_id'] ?? 0)] ?? [];
            foreach (['comprimento' => 'length', 'largura' => 'width', 'altura' => 'height'] as $param => $attribute) {
                $size[$param] = max($size[$param], $this->positive($values[$attribute] ?? null, (float) Config::get('shipping/correios/default_' . $attribute, $websiteId)));
            }
            $value += isset($item['price']) && $item['price'] !== null ? (float) $item['price'] * $quantity : (float) ($item['row_total'] ?? 0);
        }

        return [
            'psObjeto' => (string) (int) ceil($grams),
            'tpObjeto' => '2',
            'comprimento' => (string) (int) ceil($size['comprimento']),
            'largura' => (string) (int) ceil($size['largura']),
            'altura' => (string) (int) ceil($size['altura']),
            'vlDeclarado' => number_format($value, 2, '.', ''),
        ];
    }

    /** pcFinal comes as text: "26.30", "26,30" or "1.026,30" */
    private function number(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        $value = trim((string) $value);
        if (str_contains($value, ',')) {
            $value = str_replace(['.', ','], ['', '.'], $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function positive(mixed $value, float $fallback): float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : max(1, $fallback);
    }

    /** temp file + rename: a concurrent request never reads it half written; expired files are removed */
    private function cache(string $file, array $rates): void
    {
        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0775, true)) {
            return;
        }
        foreach (glob($this->cacheDir . '*.json') ?: [] as $old) {
            if (filemtime($old) <= time() - self::CACHE_SECONDS) {
                @unlink($old);
            }
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, json_encode($rates)) === false || !rename($tmp, $file)) {
            @unlink($tmp);
        }
    }
}
