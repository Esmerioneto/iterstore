<?php

declare(strict_types=1);

namespace Iter\Gemini\Client;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/** POST to a Gemini model's generateContent; nothing about the provador here. Tests replace it with a fake. */
class GeminiClient
{
    public const API = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public function __construct(private readonly ?ClientInterface $http = null)
    {
    }

    /**
     * @return array{0: int, 1: array} HTTP status (0 = no answer) and the decoded body
     */
    public function generate(string $apiKey, string $model, array $body): array
    {
        try {
            $response = ($this->http ?? new Client())->request('POST', self::API . rawurlencode($model) . ':generateContent', [
                'headers' => ['X-goog-api-key' => $apiKey],
                'json' => $body,
                'connect_timeout' => 10,
                'timeout' => 90,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            return [0, ['error' => ['message' => $e->getMessage()]]];
        }

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true) ?: []];
    }
}
