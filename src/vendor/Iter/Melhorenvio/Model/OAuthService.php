<?php

declare(strict_types=1);

namespace Iter\Melhorenvio\Model;

use GuzzleHttp\Client;
use Iter\Core\Config;
use Iter\Core\Crypt;
use RuntimeException;

/** OAuth 2.0 for Melhor Envio. Tokens are encrypted in core_config_data, never sent to the app. */
class OAuthService
{
    public function baseUrl(): string
    {
        return Config::get('melhorenvio/environment') === 'production'
            ? 'https://melhorenvio.com.br'
            : 'https://sandbox.melhorenvio.com.br';
    }

    public function redirectUri(): string
    {
        $configured = trim((string) Config::get('melhorenvio/redirect_uri'));
        if ($configured !== '') {
            return $configured;
        }

        $base = rtrim((string) Config::get('web/base_url'), '/');
        if ($base === '') {
            throw new RuntimeException('Configure a URL base do IterStore ou informe a URL de callback do Melhor Envio nas Configurações.');
        }

        return $base . '/admin/melhorenvio/callback';
    }

    public function authorizationUrl(string $state): string
    {
        $clientId = trim((string) Config::get('melhorenvio/client_id'));
        if ($clientId === '' || Crypt::decrypt(Config::get('melhorenvio/client_secret')) === '') {
            throw new RuntimeException('Preencha o Client ID e o Client Secret em Configurações → Melhor Envio antes de autorizar.');
        }
        if (trim((string) Config::get('melhorenvio/user_agent')) === '') {
            throw new RuntimeException('Configure o User-Agent com o nome da aplicação e um e-mail técnico antes de autorizar.');
        }

        $query = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'state' => $state,
            'scope' => 'shipping-calculate',
        ], '', '&', PHP_QUERY_RFC3986);

        return $this->baseUrl() . '/oauth/authorize?' . $query;
    }

    /** Exchange the one-time authorization code and persist the returned tokens. */
    public function exchangeCode(string $code): void
    {
        $this->assertCredentials();
        $data = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'client_id' => trim((string) Config::get('melhorenvio/client_id')),
            'client_secret' => Crypt::decrypt(Config::get('melhorenvio/client_secret')),
            'redirect_uri' => $this->redirectUri(),
            'code' => $code,
        ]);
        $this->saveTokens($data);
    }

    public function isConnected(): bool
    {
        return Crypt::decrypt(Config::get('melhorenvio/oauth/access_token')) !== ''
            || Crypt::decrypt(Config::get('melhorenvio/oauth/refresh_token')) !== '';
    }

    public function accessToken(): string
    {
        $access = Crypt::decrypt(Config::get('melhorenvio/oauth/access_token'));
        $refresh = Crypt::decrypt(Config::get('melhorenvio/oauth/refresh_token'));
        $expiresAt = (int) Config::get('melhorenvio/oauth/expires_at');

        if ($access !== '' && $expiresAt > time() + 120) {
            return $access;
        }
        if ($refresh === '') {
            throw new RuntimeException('O Melhor Envio ainda não está autorizado. Use Vendas → Conectar Melhor Envio.');
        }

        $this->assertCredentials();
        $data = $this->tokenRequest([
            'grant_type' => 'refresh_token',
            'client_id' => trim((string) Config::get('melhorenvio/client_id')),
            'client_secret' => Crypt::decrypt(Config::get('melhorenvio/client_secret')),
            'refresh_token' => $refresh,
        ]);
        $this->saveTokens($data);
        $access = Crypt::decrypt(Config::get('melhorenvio/oauth/access_token'));
        if ($access === '') {
            throw new RuntimeException('O Melhor Envio não retornou um token de acesso válido.');
        }

        return $access;
    }

    private function assertCredentials(): void
    {
        if (trim((string) Config::get('melhorenvio/client_id')) === ''
            || Crypt::decrypt(Config::get('melhorenvio/client_secret')) === '') {
            throw new RuntimeException('Configure o Client ID e o Client Secret em Configurações → Melhor Envio.');
        }
    }

    private function tokenRequest(array $form): array
    {
        $userAgent = trim((string) Config::get('melhorenvio/user_agent'));
        if ($userAgent === '') {
            throw new RuntimeException('Configure o User-Agent com o nome da aplicação e um e-mail técnico.');
        }

        $client = new Client(['base_uri' => $this->baseUrl(), 'timeout' => 15, 'http_errors' => false]);
        $response = $client->post('/oauth/token', [
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => $userAgent,
            ],
            'form_params' => $form,
        ]);
        $status = $response->getStatusCode();
        $data = json_decode((string) $response->getBody(), true);

        if ($status < 200 || $status >= 300 || !is_array($data) || empty($data['access_token']) || empty($data['refresh_token'])) {
            // Do not log request bodies: they contain client_secret and/or refresh_token.
            throw new RuntimeException('Falha na autenticação do Melhor Envio (HTTP ' . $status . '). Confira ambiente, credenciais, callback e autorização.');
        }

        return $data;
    }

    private function saveTokens(array $data): void
    {
        $access = (string) ($data['access_token'] ?? '');
        $refresh = (string) ($data['refresh_token'] ?? '');
        if ($access === '' || $refresh === '') {
            throw new RuntimeException('A resposta OAuth do Melhor Envio não contém os tokens esperados.');
        }
        $expiresIn = max(60, (int) ($data['expires_in'] ?? 2592000));
        Config::save([
            'melhorenvio/oauth/access_token' => Crypt::encrypt($access),
            'melhorenvio/oauth/refresh_token' => Crypt::encrypt($refresh),
            'melhorenvio/oauth/expires_at' => (string) (time() + $expiresIn),
        ]);
    }
}
