<?php

declare(strict_types=1);

namespace Iter\Fashnlocal\Provider;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Iter\Core\Config;
use Iter\Core\Crypt;
use Iter\Core\Log;
use Iter\Tryon\Api\TryonProviderException;
use Iter\Tryon\Api\TryonProviderInterface;

/**
 * The provador on FASHN VTON 1.5 (Apache-2.0) served by the store's own machine: one multipart POST {url}/tryon
 * (person_image, garment_image, category, garment_photo_type, num_timesteps), the image in the answer, a JSON
 * {"detail"} on error. Synchronous, no cost. Used only when chosen by name in Configurações → Provedor.
 */
class FashnLocalProvider implements TryonProviderInterface
{
    public function __construct(private readonly ?ClientInterface $http = null)
    {
    }

    public function code(): string
    {
        return 'fashn_local';
    }

    public function isConfigured(): bool
    {
        return Config::get('catalog/tryon/provider') === $this->code() && $this->url() !== '';
    }

    public function model(): string
    {
        return 'fashn-vton-1.5@' . $this->url();
    }

    public function estimatedCost(int $garments): float
    {
        return 0.0;
    }

    public function submit(array $job): array
    {
        $request = [
            'url' => $this->url(),
            'category' => in_array($job['category'] ?? '', ['tops', 'bottoms', 'one-pieces'], true) ? $job['category'] : 'tops',
            'garment_photo_type' => Config::get('tryon/fashn_local/garment_photo_type') === 'model' ? 'model' : 'flat-lay',
            'num_timesteps' => max(10, min(50, (int) Config::get('tryon/fashn_local/steps'))),
        ];
        $key = trim(Crypt::decrypt(Config::get('tryon/fashn_local/api_key')));
        $multipart = [
            ['name' => 'person_image', 'contents' => $job['person'], 'filename' => 'person.jpg', 'headers' => ['Content-Type' => 'image/jpeg']],
            ['name' => 'garment_image', 'contents' => $job['garments'][0] ?? '', 'filename' => 'garment.jpg', 'headers' => ['Content-Type' => 'image/jpeg']],
        ];
        foreach (['category', 'garment_photo_type', 'num_timesteps'] as $field) {
            $multipart[] = ['name' => $field, 'contents' => (string) $request[$field]];
        }
        try {
            $response = ($this->http ?? new Client())->request('POST', $request['url'] . '/tryon', [
                'multipart' => $multipart,
                'headers' => $key !== '' ? ['x-api-key' => $key] : [],
                'connect_timeout' => 10,
                'timeout' => 110,
                'http_errors' => false,
            ]);
            [$status, $type, $body] = [$response->getStatusCode(), $response->getHeaderLine('Content-Type'), (string) $response->getBody()];
        } catch (GuzzleException) {
            [$status, $type, $body] = [0, '', ''];
        }
        if ($status !== 200 || !str_starts_with($type, 'image/') || $body === '') {
            throw $this->error($status, $body);
        }

        return [
            'task' => 'fl-' . bin2hex(random_bytes(12)),
            'request' => $request,
            'result' => ['status' => 'success', 'image' => $body, 'cost' => 0.0, 'response' => ['status' => $status, 'type' => $type]],
        ];
    }

    /** a synchronous provider: every request got its result in submit() */
    public function fetch(string $task): array
    {
        return ['status' => 'error', 'error' => TryonProviderException::FAILED, 'providerCode' => 'unknown_task', 'response' => []];
    }

    private function url(): string
    {
        return rtrim(trim((string) Config::get('tryon/fashn_local/url')), '/');
    }

    /** the server's answer in the layer's four words; the key never goes into the message */
    private function error(int $status, string $body): TryonProviderException
    {
        $detail = json_decode($body, true)['detail'] ?? null;
        $message = is_string($detail) ? $detail : ($status === 0 ? 'sem resposta' : ($status === 200 ? 'sem imagem' : 'HTTP ' . $status));
        [$layer, $code] = match (true) {
            $status === 401 || $status === 403 => [TryonProviderException::UNAVAILABLE, 'http_' . $status],
            $status === 404 => [TryonProviderException::UNAVAILABLE, 'not_found'],
            $status === 0 || $status === 429 || $status >= 500 => [TryonProviderException::BUSY, $status === 0 ? 'no_answer' : 'http_' . $status],
            default => [TryonProviderException::FAILED, $status === 200 ? 'no_image' : 'http_' . $status],
        };
        $layer === TryonProviderException::FAILED
            ? Log::info("FASHN local ({$this->url()}): {$code}: {$message}")
            : Log::error("FASHN local ({$this->url()}): {$code}: {$message}. Confira o endereço e a chave em Configurações → Provador virtual.");

        return new TryonProviderException($layer, "FASHN local: {$message}", $code, ['status' => $status, 'message' => mb_substr($message, 0, 500)]);
    }
}
