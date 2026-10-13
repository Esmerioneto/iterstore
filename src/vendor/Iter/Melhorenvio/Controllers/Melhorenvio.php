<?php

declare(strict_types=1);

namespace Iter\Melhorenvio\Controllers;

use Iter\Core\Log;
use Iter\Core\Messages;
use Iter\Melhorenvio\Model\OAuthService;
use Throwable;

class Melhorenvio
{
    public const ADMIN_RESOURCE = 'Iter_MelhorEnvio::config';

    public function Authorize(): void
    {
        try {
            $state = bin2hex(random_bytes(32));
            $_SESSION['iter_melhorenvio_oauth_state'] = $state;
            header('Location: ' . (new OAuthService())->authorizationUrl($state));
        } catch (Throwable $e) {
            Messages::addError($e->getMessage());
            header('Location: /admin/config/index');
        }
    }

    public function Callback(): void
    {
        $expected = (string) ($_SESSION['iter_melhorenvio_oauth_state'] ?? '');
        $received = (string) ($_GET['state'] ?? '');
        unset($_SESSION['iter_melhorenvio_oauth_state']);

        if ($expected === '' || $received === '' || !hash_equals($expected, $received)) {
            Messages::addError('Não foi possível validar o retorno de autorização do Melhor Envio. Tente conectar novamente.');
            header('Location: /admin/config/index');
            return;
        }
        if (isset($_GET['error']) || empty($_GET['code'])) {
            Messages::addError('A autorização do Melhor Envio foi cancelada ou não retornou um código.');
            header('Location: /admin/config/index');
            return;
        }

        try {
            (new OAuthService())->exchangeCode((string) $_GET['code']);
            Messages::addSuccess('Melhor Envio conectado. Os tokens foram armazenados de forma criptografada.');
        } catch (Throwable $e) {
            Log::error('Melhor Envio OAuth: ' . $e->getMessage());
            Messages::addError('Não foi possível conectar ao Melhor Envio. Confira ambiente, credenciais, callback e permissões.');
        }
        header('Location: /admin/config/index');
    }
}
