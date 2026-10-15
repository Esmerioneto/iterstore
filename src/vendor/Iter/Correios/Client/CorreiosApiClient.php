<?php

declare(strict_types=1);

namespace Iter\Correios\Client;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Iter\Core\Config;
use Iter\Core\Crypt;
use RuntimeException;

/** Authenticated GET on the Correios APIs (preco/v1, prazo/v1). Secrets and tokens stay server-side. */
class CorreiosApiClient
{
    /** the Token API hands back a new token up to 30 minutes before expiraEm; renew a bit before it ends */
    private const RENEW_SECONDS = 300;

    public function __construct(private readonly ?ClientInterface $http = null, private readonly ?TokenClient $tokens = null)
    {
    }

    /** $path from the host, e.g. "preco/v1/nacional/03220" */
    public function get(string $path, array $query = []): array
    {
        $response = ($this->http ?? new Client())->request('GET', TokenClient::host() . ltrim($path, '/'), [
            'headers' => ['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $this->token()],
            'query' => $query,
            'timeout' => 12,
            'http_errors' => false,
        ]);
        $status = $response->getStatusCode();
        $data = json_decode((string) $response->getBody(), true);
        if ($status < 200 || $status >= 300 || !is_array($data)) {
            // no token nor the whole upstream payload in the message: it goes to the log
            $messages = array_filter((array) ($data['msgs'] ?? []), 'is_string');
            throw new RuntimeException('Falha na API dos Correios (' . strtok($path, '/') . ', HTTP ' . $status . ')' . ($messages ? ': ' . mb_substr(implode('; ', $messages), 0, 300) : '.'));
        }

        return $data;
    }

    /** The saved token while it's valid and was issued for the same environment and credentials */
    private function token(): string
    {
        $key = hash('sha256', implode('|', [TokenClient::host(), Config::get('correios/username'), Config::get('correios/posting_card'), Config::get('correios/contract_number'), Config::get('correios/regional_code')]));
        $saved = Crypt::decrypt(Config::get('correios/oauth/token'));
        if ($saved !== '' && Config::get('correios/oauth/key') === $key && (int) Config::get('correios/oauth/expires_at') > time() + self::RENEW_SECONDS) {
            return $saved;
        }

        $data = ($this->tokens ?? new TokenClient($this->http))->authenticate();
        $expires = strtotime((string) $data['expiraEm']) ?: time() + 3600;
        Config::save([
            'correios/oauth/token' => Crypt::encrypt((string) $data['token']),
            'correios/oauth/expires_at' => (string) $expires,
            'correios/oauth/key' => $key,
        ]);

        return (string) $data['token'];
    }
}
