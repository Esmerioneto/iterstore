<?php

declare(strict_types=1);

namespace Iter\Huggingface\Provider;

use Iter\Core\Config;
use Iter\Core\Crypt;
use Iter\Core\Log;
use Iter\Huggingface\Client\GradioClient;
use Iter\Tryon\Api\TryonProviderException;
use Iter\Tryon\Api\TryonProviderInterface;

/**
 * The provador on FASHN VTON 1.5's free Space on Hugging Face (fashn-ai/fashn-vton-1.5, Apache-2.0): a model made
 * for try-on, no prompt, the garment's part of the body from "Tipo de peça no provador". For development only:
 * a public demo, without a contract or uptime, so it's used only when chosen by name in Configurações → Provedor
 * (never as "the first configured"). Synchronous: the call waits for the image (about 30 s on the free GPU).
 */
class HuggingfaceProvider implements TryonProviderInterface
{
    public const ENDPOINT = 'try_on';

    public function __construct(private ?GradioClient $client = null)
    {
        $this->client ??= new GradioClient();
    }

    public function code(): string
    {
        return 'huggingface';
    }

    public function isConfigured(): bool
    {
        return Config::get('catalog/tryon/provider') === $this->code() && $this->space() !== '';
    }

    public function model(): string
    {
        return $this->space();
    }

    public function estimatedCost(int $garments): float
    {
        return 0.0;
    }

    public function submit(array $job): array
    {
        $request = [
            'space' => $this->space(),
            'category' => in_array($job['category'] ?? '', ['tops', 'bottoms', 'one-pieces'], true) ? $job['category'] : 'tops',
            'garment_photo_type' => Config::get('tryon/huggingface/garment_photo_type') === 'model' ? 'model' : 'flat-lay',
            'num_timesteps' => max(10, min(50, (int) Config::get('tryon/huggingface/steps'))),
        ];
        // person, garment, category, garment_photo_type, num_timesteps, guidance_scale, seed, segmentation_free
        [$state, $data] = $this->client->predict($request['space'], $this->token(), self::ENDPOINT, ['person' => $job['person'], 'garment' => $job['garments'][0] ?? ''],
            [$request['category'], $request['garment_photo_type'], $request['num_timesteps'], 1.5, 42, true]);
        $url = $state === 'complete' ? ($data[0]['url'] ?? null) : null;
        // only from the Space itself: the token goes with the download
        $image = is_string($url) && str_starts_with($url, GradioClient::host($request['space']) . '/') ? $this->client->download($url, $this->token()) : null;
        if ($image === null) {
            throw $this->error($state, $data);
        }

        return [
            'task' => 'hf-' . bin2hex(random_bytes(12)),
            'request' => $request,
            'result' => ['status' => 'success', 'image' => $image, 'cost' => 0.0, 'response' => ['state' => $state, 'output' => $data[0]['orig_name'] ?? basename($url)]],
        ];
    }

    /** a synchronous provider: every request got its result in submit() */
    public function fetch(string $task): array
    {
        return ['status' => 'error', 'error' => TryonProviderException::FAILED, 'providerCode' => 'unknown_task', 'response' => []];
    }

    private function space(): string
    {
        return trim((string) Config::get('tryon/huggingface/space'), " /");
    }

    private function token(): string
    {
        return trim(Crypt::decrypt(Config::get('tryon/huggingface/token')));
    }

    /** the Space's answer in the layer's four words; the token never goes into the message */
    private function error(string $state, mixed $data): TryonProviderException
    {
        $status = $state === 'http' ? (int) ($data[0] ?? 0) : 200;
        $message = $state === 'error' ? (is_string($data) ? $data : 'erro do Space') : ($state === 'complete' ? 'sem imagem' : 'HTTP ' . $status);
        // the free GPU's quota used up (per IP, or per account with a token): the store has to wait or add a token
        $quota = stripos($message, 'quota') !== false || stripos($message, 'ZeroGPU') !== false;
        [$layer, $code] = match (true) {
            $status === 401 || $status === 403 => [TryonProviderException::UNAVAILABLE, 'http_' . $status],
            $status === 404 => [TryonProviderException::UNAVAILABLE, 'space_not_found'],
            $quota => [TryonProviderException::BUSY, 'gpu_quota'],
            $state === 'http' => [TryonProviderException::BUSY, $status === 0 ? 'no_answer' : 'http_' . $status], // asleep, starting, queue full or too slow
            default => [TryonProviderException::FAILED, $state === 'error' ? 'space_error' : 'no_image'],
        };
        $quota || $layer === TryonProviderException::UNAVAILABLE
            ? Log::error("Hugging Face ({$this->space()}): {$code}: {$message}. Confira o Space e o token em Configurações → Provador virtual.")
            : Log::info("Hugging Face ({$this->space()}): {$code}: {$message}");

        return new TryonProviderException($layer, "Hugging Face: {$message}", $code, ['state' => $state, 'status' => $status, 'message' => mb_substr($message, 0, 500)]);
    }
}
