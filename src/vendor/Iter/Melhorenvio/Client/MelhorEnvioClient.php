<?php

declare(strict_types=1);

namespace Iter\Melhorenvio\Client;

use GuzzleHttp\Client;
use Iter\Core\Config;
use Iter\Melhorenvio\Model\OAuthService;
use RuntimeException;

/** Small REST client for the Melhor Envio API; no secrets leave the PHP backend. */
class MelhorEnvioClient
{
    public function __construct(private readonly ?OAuthService $oauth = null)
    {
    }

    public function post(string $path, array $payload): array
    {
        $oauth = $this->oauth ?? new OAuthService();
        $userAgent = trim((string) Config::get('melhorenvio/user_agent'));
        if ($userAgent === '') {
            throw new RuntimeException('Configure o User-Agent do Melhor Envio.');
        }

        $client = new Client(['base_uri' => $oauth->baseUrl(), 'timeout' => 12, 'http_errors' => false]);
        $response = $client->post('/api/v2/me/' . ltrim($path, '/'), [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $oauth->accessToken(),
                'User-Agent' => $userAgent,
            ],
            'json' => $payload,
        ]);
        $status = $response->getStatusCode();
        $data = json_decode((string) $response->getBody(), true);

        if ($status < 200 || $status >= 300 || !is_array($data)) {
            $message = 'Falha ao consultar o Melhor Envio (HTTP ' . $status . ').';
            if (is_array($data) && !empty($data['message']) && is_string($data['message'])) {
                $message .= ' ' . mb_substr($data['message'], 0, 250);
            }
            throw new RuntimeException($message);
        }

        return $data;
    }
}
