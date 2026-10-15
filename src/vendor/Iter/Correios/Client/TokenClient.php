<?php

declare(strict_types=1);

namespace Iter\Correios\Client;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Iter\Core\Config;
use Iter\Core\Crypt;
use RuntimeException;

/**
 * Correios Token API: a token by posting card. Every autentica/* call goes with Basic auth
 * (Meu Correios user + access code); the card, contract and DR go in the body.
 */
class TokenClient
{
    public function __construct(private readonly ?ClientInterface $http = null)
    {
    }

    public static function host(): string
    {
        return Config::get('correios/environment') === 'producao' ? 'https://api.correios.com.br/' : 'https://apihom.correios.com.br/';
    }

    /** @return array{token: string, expiraEm: string} */
    public function authenticate(): array
    {
        $username = trim((string) Config::get('correios/username'));
        $accessCode = Crypt::decrypt(Config::get('correios/access_code'));
        $card = trim((string) Config::get('correios/posting_card'));
        if ($username === '' || $accessCode === '' || $card === '') {
            throw new RuntimeException('Configure o usuário Meu Correios, o código de acesso e o cartão de postagem em Configurações → Correios.');
        }
        $payload = ['numero' => $card];
        $contract = trim((string) Config::get('correios/contract_number'));
        $regional = trim((string) Config::get('correios/regional_code'));
        if ($contract !== '') {
            $payload['contrato'] = $contract;
        }
        if ($regional !== '') {
            $payload['dr'] = (int) $regional;
        }

        $response = ($this->http ?? new Client())->request('POST', self::host() . 'token/v1/autentica/cartaopostagem', [
            'auth' => [$username, $accessCode],
            'headers' => ['Accept' => 'application/json'],
            'json' => $payload,
            'timeout' => 15,
            'http_errors' => false,
        ]);
        $status = $response->getStatusCode();
        $data = json_decode((string) $response->getBody(), true);
        if ($status < 200 || $status >= 300 || !is_array($data)) {
            $message = 'Falha na autenticação dos Correios (HTTP ' . $status . ').';
            $messages = array_filter((array) ($data['msgs'] ?? []), 'is_string');
            if ($messages) {
                $message .= ' ' . mb_substr(implode('; ', $messages), 0, 300);
            }
            throw new RuntimeException($message);
        }
        if (empty($data['token']) || empty($data['expiraEm'])) {
            throw new RuntimeException('A API Token dos Correios respondeu sem token ou data de expiração.');
        }

        return $data;
    }
}
