<?php

declare(strict_types=1);

namespace Iter\Gemini\Provider;

use Iter\Core\Config;
use Iter\Core\Crypt;
use Iter\Core\Log;
use Iter\Gemini\Client\GeminiClient;
use Iter\Tryon\Api\TryonProviderException;
use Iter\Tryon\Api\TryonProviderInterface;

/**
 * The provador on the Gemini API (Nano Banana): the model edits the customer's photo (first image) with the garment
 * (second image), following the prompt in Configurações. Synchronous: the image comes in the answer (base64).
 * The image models have no free tier: the Google project needs billing on.
 */
class GeminiProvider implements TryonProviderInterface
{
    /** model => US$ per 1K image (ai.google.dev/gemini-api/docs/pricing, 2026-10-29) */
    public const MODELS = [
        'gemini-3.1-flash-image' => 0.067,
        'gemini-nano-banana-2.1' => 0.0336,
        'gemini-3-pro-image' => 0.134,
    ];
    /** the ratios the model takes; the closest to the photo's */
    public const RATIOS = ['1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9'];
    /** a block for content (prompt or result): REFUSED; anything else without an image is FAILED */
    private const SAFETY = ['SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'SPII', 'IMAGE_SAFETY', 'IMAGE_PROHIBITED_CONTENT'];

    public function __construct(private ?GeminiClient $client = null)
    {
        $this->client ??= new GeminiClient();
    }

    public function code(): string
    {
        return 'gemini';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '';
    }

    public function model(): string
    {
        $key = (string) Config::get('tryon/gemini/model');

        return isset(self::MODELS[$key]) ? $key : array_key_first(self::MODELS);
    }

    public function estimatedCost(int $garments): float
    {
        return self::MODELS[$this->model()];
    }

    public function submit(array $job): array
    {
        $person = $job['person'];
        $garment = $job['garments'][0] ?? '';
        [$w, $h] = getimagesizefromstring($person) ?: throw new TryonProviderException(TryonProviderException::FAILED, 'imagem inválida para o provedor', 'invalid_input');
        $request = ['model' => $this->model(), 'prompt' => (string) Config::get('tryon/gemini/prompt'), 'aspectRatio' => self::ratio($w, $h)];
        $body = [
            'contents' => [['parts' => [
                ['text' => $request['prompt']],
                ['inline_data' => ['mime_type' => 'image/jpeg', 'data' => base64_encode($person)]],
                ['inline_data' => ['mime_type' => 'image/jpeg', 'data' => base64_encode($garment)]],
            ]]],
            'generationConfig' => ['responseModalities' => ['IMAGE'], 'imageConfig' => ['aspectRatio' => $request['aspectRatio']]],
        ];
        // "overloaded" or an internal error now and then, and the same call goes through right after: one retry
        for ($try = 1; $try <= 2; $try++) {
            [$status, $answer] = $this->client->generate($this->apiKey(), $request['model'], $body);
            if ($status < 500) {
                break;
            }
        }

        $image = null;
        foreach ($answer['candidates'][0]['content']['parts'] ?? [] as $part) {
            $data = $part['inlineData']['data'] ?? $part['inline_data']['data'] ?? null;
            $image ??= is_string($data) ? (base64_decode($data, true) ?: null) : null;
        }
        array_walk_recursive($answer, static function (&$value, $key): void {
            if (in_array($key, ['data', 'thoughtSignature'], true) && is_string($value)) {
                $value = '<' . strlen($value) . ' bytes em base64>';
            }
        });
        $request += ['person' => self::size($person), 'garment' => self::size($garment)];
        if ($status !== 200 || $image === null) {
            throw $this->error($status, $answer);
        }

        return [
            'task' => 'gm-' . bin2hex(random_bytes(12)),
            'request' => $request,
            'result' => ['status' => 'success', 'image' => $image, 'cost' => $this->estimatedCost(1), 'response' => $answer],
        ];
    }

    /** a synchronous provider: every request got its result in submit() */
    public function fetch(string $task): array
    {
        return ['status' => 'error', 'error' => TryonProviderException::FAILED, 'providerCode' => 'unknown_task', 'response' => []];
    }

    private function apiKey(): string
    {
        return trim(Crypt::decrypt(Config::get('tryon/gemini/api_key')));
    }

    private static function ratio(int $w, int $h): string
    {
        $distance = static function (string $ratio) use ($w, $h): float {
            [$a, $b] = explode(':', $ratio);

            return abs(log(($w / $h) / ((int) $a / (int) $b)));
        };
        $ratios = self::RATIOS;
        usort($ratios, static fn ($x, $y) => $distance($x) <=> $distance($y));

        return $ratios[0];
    }

    private static function size(string $bytes): string
    {
        [$w, $h] = getimagesizefromstring($bytes) ?: [0, 0];

        return "{$w}x{$h}";
    }

    /** Google's answer in the layer's four words; the key never goes into the message */
    private function error(int $status, array $answer): TryonProviderException
    {
        $error = $answer['error'] ?? [];
        $message = (string) ($error['message'] ?? '');
        $reasons = array_filter(array_column($error['details'] ?? [], 'reason'));
        $block = $answer['promptFeedback']['blockReason'] ?? null;
        $finish = $answer['candidates'][0]['finishReason'] ?? null;
        // no billing (the image models' free tier is 0) or the day's quota used up: BUSY for the customer, but the
        // store has to act, so it goes to the log as an error
        $quota = $status === 429 && (str_contains($message, 'limit: 0') || stripos($message, 'per day') !== false || stripos($message, 'billing') !== false);
        $layer = match (true) {
            $status === 200 && (in_array($block, self::SAFETY, true) || in_array($finish, self::SAFETY, true) || $block === 'OTHER') => TryonProviderException::REFUSED,
            $status === 401 || $status === 403 || in_array('API_KEY_INVALID', $reasons, true) => TryonProviderException::UNAVAILABLE,
            $status === 429 || $status >= 500 => TryonProviderException::BUSY,
            default => TryonProviderException::FAILED,
        };
        $code = $status === 200 ? (string) ($block ?? $finish ?? 'no_image') : (string) ($reasons ? reset($reasons) : ($error['status'] ?? 'http_' . $status));
        $message = $status === 200 ? "sem imagem ({$code})" : ($message ?: 'sem resposta');
        $quota || $layer === TryonProviderException::UNAVAILABLE
            ? Log::error("Gemini: HTTP {$status}, {$code}: confira a chave e o faturamento do projeto no Google AI Studio. {$message}")
            : Log::info("Gemini: HTTP {$status}, {$code}: {$message}");

        return new TryonProviderException($layer, "Gemini: HTTP {$status} {$message}", $code, $answer);
    }
}
