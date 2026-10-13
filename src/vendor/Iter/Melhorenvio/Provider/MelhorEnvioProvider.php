<?php

declare(strict_types=1);

namespace Iter\Melhorenvio\Provider;

use Iter\Catalog\Model\Product;
use Iter\Shipping\Api\ShippingRateProviderInterface;
use Iter\Core\Config;
use Iter\Core\Log;
use Iter\Eav\Model\ValueService;
use Iter\Melhorenvio\Client\MelhorEnvioClient;
use Iter\Melhorenvio\Model\OAuthService;
use RuntimeException;

/** Maps an IterStore cart to Melhor Envio's product-based shipping calculation. */
class MelhorEnvioProvider implements ShippingRateProviderInterface
{
    /** Every cart read quotes again (cart, each mutation, placeOrder): the same quote is reused this long */
    private const CACHE_SECONDS = 600;

    private readonly string $cacheDir;

    public function __construct(private readonly ?MelhorEnvioClient $client = null, ?string $cacheDir = null)
    {
        // ITER_MELHORENVIO_CACHE_DIR: another folder, used by the selftest so it doesn't touch the real cache
        $this->cacheDir = $cacheDir ?? (getenv('ITER_MELHORENVIO_CACHE_DIR') ?: dirname(SRC) . '/var/cache/melhorenvio/');
    }

    /**
     * @param array{website_id:int,items:array,subtotal:float,shipping_address:?array} $cart
     * @return array<string,array{code:string,label:string,amount:float,days:int}>
     */
    public function rates(array $cart): array
    {
        $websiteId = (int) $cart['website_id'];
        if (!Config::get('shipping/melhorenvio/active', $websiteId)) {
            return [];
        }
        if (!(new OAuthService())->isConnected()) {
            // ponytail: logs on every cart read while disconnected; that noise is the point
            Log::alert('Melhor Envio ativo, mas a loja não está conectada (OAuth). Sem cotação.', ['website_id' => $websiteId]);

            return [];
        }
        $origin = preg_replace('/\D/', '', (string) Config::get('shipping/melhorenvio/origin_postcode', $websiteId));
        $destination = preg_replace('/\D/', '', (string) ($cart['shipping_address']['postcode'] ?? ''));
        if (strlen($origin) !== 8 || strlen($destination) !== 8 || empty($cart['items'])) {
            return [];
        }

        $items = $cart['items'];
        $ids = array_values(array_unique(array_map(static fn ($item) => (int) ($item['product_id'] ?? 0), $items)));
        $dimensions = [];
        if (\isModuleEnabled('iter_eav') && $ids) {
            $dimensions = (new ValueService())->loadMany(Product::ENTITY_TYPE, $ids, ['width', 'height', 'length']);
        }

        $products = [];
        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $values = $dimensions[$productId] ?? [];
            $quantity = (float) ($item['quantity'] ?? 0);
            if ($quantity <= 0) {
                continue;
            }
            $unitPrice = isset($item['price']) && $item['price'] !== null
                ? (float) $item['price']
                : (float) ($item['row_total'] ?? 0) / $quantity;
            $weight = (float) ($item['weight'] ?? 0);
            if ($weight <= 0) {
                $weight = (float) Config::get('shipping/melhorenvio/default_weight', $websiteId);
            }
            if ($weight <= 0) {
                throw new RuntimeException('Configure um peso padrão de produto maior que zero em Configurações → Melhor Envio.');
            }
            $products[] = [
                'id' => (string) ($item['sku'] ?? $productId),
                'width' => $this->positive($values['width'] ?? null, (float) Config::get('shipping/melhorenvio/default_width', $websiteId)),
                'height' => $this->positive($values['height'] ?? null, (float) Config::get('shipping/melhorenvio/default_height', $websiteId)),
                'length' => $this->positive($values['length'] ?? null, (float) Config::get('shipping/melhorenvio/default_length', $websiteId)),
                'weight' => $weight,
                'insurance_value' => max(0, round($unitPrice, 2)),
                'quantity' => $quantity,
            ];
        }
        if (!$products) {
            return [];
        }

        $payload = [
            'from' => ['postal_code' => $origin],
            'to' => ['postal_code' => $destination],
            'products' => $products,
            'options' => ['receipt' => false, 'own_hand' => false],
        ];
        $services = trim((string) Config::get('shipping/melhorenvio/services', $websiteId));
        if ($services !== '') {
            $ids = array_values(array_filter(array_map('trim', explode(',', $services)), static fn ($id) => preg_match('/^\d+$/', $id)));
            if (!$ids) {
                throw new RuntimeException('Os IDs de serviço do Melhor Envio devem ser números separados por vírgula.');
            }
            $payload['services'] = implode(',', $ids);
        }

        $response = $this->calculate($payload);
        $rates = [];
        foreach ($response as $row) {
            if (!is_array($row) || !empty($row['error']) || !isset($row['id'])) {
                continue;
            }
            $serviceId = (string) $row['id'];
            $company = is_array($row['company'] ?? null) ? (string) ($row['company']['name'] ?? '') : (string) ($row['company'] ?? '');
            $serviceName = trim((string) ($row['name'] ?? ''));
            $label = trim(implode(' — ', array_filter([$company, $serviceName]))) ?: 'Melhor Envio';
            $price = $row['custom_price'] ?? $row['price'] ?? null;
            if (!is_numeric($price) || (float) $price < 0) {
                continue;
            }
            $days = $this->deliveryDays($row['custom_delivery_time'] ?? $row['delivery_time'] ?? 0);
            $code = 'melhorenvio:' . $serviceId;
            $rates[$code] = ['code' => $code, 'label' => $label, 'amount' => round((float) $price, 2), 'days' => $days];
        }

        return $rates;
    }

    /** shipment/calculate through a file cache keyed by the payload and the environment; failures aren't cached */
    private function calculate(array $payload): array
    {
        $file = $this->cacheDir . hash('sha256', json_encode([(new OAuthService())->baseUrl(), $payload])) . '.json';
        if (is_file($file) && filemtime($file) > time() - self::CACHE_SECONDS) {
            $cached = json_decode((string) file_get_contents($file), true);
            if (is_array($cached)) {
                return $cached;
            }
        }
        $response = ($this->client ?? new MelhorEnvioClient())->post('shipment/calculate', $payload);
        if (is_dir($this->cacheDir) || @mkdir($this->cacheDir, 0775, true)) {
            foreach (glob($this->cacheDir . '*.json') ?: [] as $old) {
                if (filemtime($old) <= time() - self::CACHE_SECONDS) {
                    @unlink($old);
                }
            }
            // temp file + rename: a concurrent request never reads it half written
            $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (file_put_contents($tmp, json_encode($response)) === false || !rename($tmp, $file)) {
                @unlink($tmp);
            }
        }

        return $response;
    }

    private function positive(mixed $value, float $fallback): float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : max(1, $fallback);
    }

    private function deliveryDays(mixed $value): int
    {
        if (is_numeric($value)) {
            return max(0, min(365, (int) $value));
        }
        if (is_array($value)) {
            $value = $value['days'] ?? $value['min'] ?? $value['minimum'] ?? 0;
        }
        return preg_match('/\d+/', (string) $value, $m) ? min(365, (int) $m[0]) : 0;
    }
}
