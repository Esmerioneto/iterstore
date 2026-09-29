<?php

declare(strict_types=1);

namespace Iter\Core\Controllers;

use Iter\Admin\Auth;
use Iter\Core\GraphQl\Schema;

class Graphql
{
    public function Index(): void
    {
        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $query = $input['query'] ?? '';
        $variables = $input['variables'] ?? null;

        // API login: a Bearer token (generateAdminToken) acts as its admin user; a bad one is refused, not ignored
        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? (function_exists('getallheaders') ? (array_change_key_case(getallheaders())['authorization'] ?? '') : '');
        if (preg_match('/^Bearer\s+(\S+)$/i', (string) $authorization, $m) && !Auth::useToken($m[1])) {
            http_response_code(401);
            echo json_encode(['errors' => [['message' => 'Token inválido ou expirado. Faça login de novo (generateAdminToken).', 'extensions' => ['code' => 'TOKEN_INVALID']]]]);
            return;
        }

        $schema = Schema::build();
        if ($schema === null) {
            echo json_encode(['errors' => [['message' => 'Nenhum módulo com API está ativo.']]]);
            return;
        }
        $result = \GraphQL\GraphQL::executeQuery($schema, $query, null, null, $variables);

        echo json_encode($result->toArray());
    }
}
