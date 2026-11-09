<?php

declare(strict_types=1);

namespace Iter\Cloudflare\Provider;

use Iter\Cloudflare\Client\WorkersAiClient;
use Iter\Core\Config;
use Iter\Core\Crypt;
use Iter\Core\Log;
use Iter\Tryon\Api\TryonProviderException;
use Iter\Tryon\Api\TryonProviderInterface;

/**
 * The provador on Cloudflare Workers AI (TRYON-PLAN, delivery 3): FLUX.2 klein edits the customer's photo
 * (input_image_0) with the garment (input_image_1), following the prompt in Configurações. Synchronous: the image
 * comes in the answer (base64), in about 3 s, so submit() already gives the result.
 */
class CloudflareProvider implements TryonProviderInterface
{
    public const MODELS = [
        'flux-2-klein-9b' => 0.017,
        'flux-2-klein-4b' => 0.001,
    ];
    /** the model takes inputs smaller than 512×512 */
    public const INPUT_SIDE = 511;
    /** the result, in the photo's proportions */
    public const OUTPUT_PIXELS = 1024 * 1024;

    public function __construct(private ?WorkersAiClient $client = null)
    {
        $this->client ??= new WorkersAiClient();
    }

    public function code(): string
    {
        return 'cloudflare';
    }

    public function isConfigured(): bool
    {
        return trim((string) Config::get('tryon/cloudflare/account_id')) !== '' && $this->token() !== '';
    }

    public function model(): string
    {
        return '@cf/black-forest-labs/' . $this->modelKey();
    }

    public function estimatedCost(int $garments): float
    {
        return self::MODELS[$this->modelKey()];
    }

    public function submit(array $job): array
    {
        $person = self::shrink($job['person']);
        $garment = self::shrink($job['garments'][0] ?? '');
        [$w, $h] = getimagesizefromstring($person);
        $scale = sqrt(self::OUTPUT_PIXELS / ($w * $h));
        $request = [
            'model' => $this->model(),
            'prompt' => (string) Config::get('tryon/cloudflare/prompt'),
            'width' => (string) (int) (round($w * $scale / 16) * 16), // multiples of 16
            'height' => (string) (int) (round($h * $scale / 16) * 16),
        ];
        $fields = ['prompt' => $request['prompt'], 'width' => $request['width'], 'height' => $request['height']];
        $files = ['input_image_0' => $person, 'input_image_1' => $garment];
        // the API answers an internal error now and then and the same call goes through right after: one retry
        for ($try = 1; $try <= 2; $try++) {
            [$status, $body] = $this->client->run(trim((string) Config::get('tryon/cloudflare/account_id')), $this->token(), $request['model'], $fields, $files);
            if ($status < 500) {
                break;
            }
        }

        $image = isset($body['result']['image']) && is_string($body['result']['image']) ? base64_decode($body['result']['image'], true) : false;
        if (isset($body['result']['image'])) {
            $body['result']['image'] = '<' . strlen((string) $body['result']['image']) . ' bytes em base64>';
        }
        $request += ['input_image_0' => self::size($person), 'input_image_1' => self::size($garment)];
        if ($status !== 200 || ($body['success'] ?? false) !== true || $image === false) {
            throw $this->error($status, $body);
        }

        return [
            'task' => 'cf-' . bin2hex(random_bytes(12)),
            'request' => $request,
            'result' => ['status' => 'success', 'image' => $image, 'cost' => $this->estimatedCost(1), 'response' => $body],
        ];
    }

    /** a synchronous provider: every request got its result in submit() */
    public function fetch(string $task): array
    {
        return ['status' => 'error', 'error' => TryonProviderException::FAILED, 'providerCode' => 'unknown_task', 'response' => []];
    }

    private function modelKey(): string
    {
        $key = (string) Config::get('tryon/cloudflare/model');

        return isset(self::MODELS[$key]) ? $key : array_key_first(self::MODELS);
    }

    private function token(): string
    {
        return trim(Crypt::decrypt(Config::get('tryon/cloudflare/api_token')));
    }

    /** longest side INPUT_SIDE, JPEG 92 (the store already sends a clean JPEG) */
    private static function shrink(string $bytes): string
    {
        $source = @imagecreatefromstring($bytes) ?: throw new TryonProviderException(TryonProviderException::FAILED, 'imagem inválida para o provedor', 'invalid_input');
        [$w, $h] = [imagesx($source), imagesy($source)];
        $scale = min(1, self::INPUT_SIDE / max($w, $h));
        $image = $scale < 1 ? imagescale($source, (int) floor($w * $scale), (int) floor($h * $scale), IMG_BICUBIC) : $source;
        ob_start();
        imagejpeg($image, null, 92);

        return (string) ob_get_clean();
    }

    private static function size(string $bytes): string
    {
        [$w, $h] = getimagesizefromstring($bytes);

        return "{$w}x{$h}";
    }

    /** Cloudflare's answer in the layer's four words; the token never goes into the message */
    private function error(int $status, array $body): TryonProviderException
    {
        $first = $body['errors'][0] ?? [];
        $code = isset($first['code']) ? (string) $first['code'] : null;
        $message = (string) ($first['message'] ?? 'sem resposta');
        // the free daily allocation used up: BUSY for the customer ("tente em instantes"), but the store has to
        // upgrade the plan (or wait for 00:00 UTC), so it goes to the log as an error, like an UNAVAILABLE
        $quota = $code === '4006' || stripos($message, 'daily free allocation') !== false || stripos($message, 'neurons') !== false;
        $layer = match (true) {
            $status === 401 || $status === 403 || in_array($code, ['10000', '10001', '9109'], true) => TryonProviderException::UNAVAILABLE,
            $quota => TryonProviderException::BUSY,
            (bool) preg_match('/nsfw|safety|flagged|inappropriate/i', $message) => TryonProviderException::REFUSED,
            $status === 429 || $status >= 500 => TryonProviderException::BUSY,
            default => TryonProviderException::FAILED,
        };
        $quota
            ? Log::error("Cloudflare Workers AI: cota grátis do dia esgotada (HTTP {$status}, {$code}): assine o Workers Paid ou espere 00:00 UTC. {$message}")
            : Log::info("Cloudflare Workers AI: HTTP {$status}, {$code}: {$message}");

        return new TryonProviderException($layer, "Cloudflare: HTTP {$status} {$message}", $code ?? ('http_' . $status), $body);
    }
}
