<?php

declare(strict_types=1);

namespace Iter\Cloudflare\Client;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/** POST to a Workers AI model; nothing about the provador here. Tests replace it with a fake. */
class WorkersAiClient
{
    public const API = 'https://api.cloudflare.com/client/v4/accounts/';

    public function __construct(private readonly ?ClientInterface $http = null)
    {
    }

    /**
     * @param array<string, string> $fields multipart text fields
     * @param array<string, string> $files multipart files: name => JPEG bytes
     * @return array{0: int, 1: array} HTTP status (0 = no answer) and the decoded body
     */
    public function run(string $accountId, string $token, string $model, array $fields, array $files = []): array
    {
        $multipart = [];
        foreach ($fields as $name => $value) {
            $multipart[] = ['name' => $name, 'contents' => $value];
        }
        foreach ($files as $name => $bytes) {
            $multipart[] = ['name' => $name, 'contents' => $bytes, 'filename' => 'image.jpg', 'headers' => ['Content-Type' => 'image/jpeg']];
        }
        try {
            $response = ($this->http ?? new Client())->request('POST', self::API . rawurlencode($accountId) . '/ai/run/' . $model, [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'multipart' => $multipart,
                'connect_timeout' => 10,
                'timeout' => 60,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            return [0, ['errors' => [['message' => $e->getMessage()]]]];
        }

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true) ?: []];
    }
}
