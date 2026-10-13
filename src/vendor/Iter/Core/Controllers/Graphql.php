<?php

declare(strict_types=1);

namespace Iter\Core\Controllers;

use Iter\Admin\Auth;
use Iter\Core\Config;
use Iter\Core\Log;
use GraphQL\Error\Error;
use GraphQL\Error\FormattedError;
use GraphQL\Error\SyntaxError;
use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\DisableIntrospection;
use GraphQL\Validator\Rules\QueryComplexity;
use GraphQL\Validator\Rules\QueryDepth;
use Iter\Core\GraphQl\Schema;
use Iter\Customer\Model\CustomerAuth;

class Graphql
{
    public function Index(): void
    {
        // CORS for a frontend on another site; no cookies cross-origin, it logs in with the Bearer token
        $origin = rtrim($_SERVER['HTTP_ORIGIN'] ?? '', '/');
        $allowed = array_map(static fn ($o) => rtrim($o, '/'), preg_split('/\s+/', trim((string) Config::get('web/cors_origins')), -1, PREG_SPLIT_NO_EMPTY));
        if ($origin !== '' && (in_array('*', $allowed, true) || in_array($origin, $allowed, true))) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Sales-Channel');
            header('Access-Control-Max-Age: 86400');
        }
        header('Vary: Origin');
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { // preflight
            http_response_code(204);
            return;
        }

        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        // file upload: GraphQL multipart request spec, operations = {query, variables}, map = {"0": ["variables.file"]}
        $multipart = isset($_POST['operations']);
        if ($multipart) {
            $input = self::multipart();
        }
        $query = $input['query'] ?? '';
        $variables = $input['variables'] ?? null;

        // API login: a Bearer token acts as its admin user (generateAdminToken) or customer (generateCustomerToken,
        // which never passes the admin guard); a bad one is refused, not ignored
        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? (function_exists('getallheaders') ? (array_change_key_case(getallheaders())['authorization'] ?? '') : '');
        if (preg_match('/^Bearer\s+(\S+)$/i', (string) $authorization, $m)
            && !Auth::useToken($m[1]) && !(\isModuleEnabled('iter_customer') && CustomerAuth::useToken($m[1]))) {
            http_response_code(401);
            echo json_encode(['errors' => [['message' => 'Token inválido ou expirado. Faça login de novo.', 'extensions' => ['code' => 'TOKEN_INVALID']]]]);
            return;
        }

        // a multipart form from another site skips the CORS preflight: with the session cookie alone it would be CSRF
        if ($multipart && !isset($m[1])) {
            http_response_code(400);
            echo json_encode(['errors' => [['message' => 'Envio de arquivo exige o token (Authorization: Bearer).', 'extensions' => ['code' => 'UNAUTHENTICATED']]]]);
            return;
        }

        $schema = Schema::build();
        if ($schema === null) {
            echo json_encode(['errors' => [['message' => 'Nenhum módulo com API está ativo.']]]);
            return;
        }
        // a huge or deeply nested query is refused before running (0 = no limit)
        $rules = DocumentValidator::allRules();
        $rules[] = new QueryDepth((int) Config::get('system/api/max_depth'));
        $rules[] = new QueryComplexity((int) Config::get('system/api/max_complexity'));
        if (!Config::get('system/api/introspection')) {
            $rules[] = new DisableIntrospection(DisableIntrospection::ENABLED);
        }
        $result = \GraphQL\GraphQL::executeQuery($schema, $query, null, null, $variables, null, null, $rules);

        // every error carries extensions.code, so the clients branch on it instead of the message
        // Keep the real cause of unexpected resolver failures in the system log; GraphQL otherwise
        // masks it as "Internal server error", leaving checkout failures impossible to diagnose.
        $result->setErrorFormatter(static function (Error $e): array {
            if (!$e->isClientSafe()) {
                $cause = $e->getPrevious();
                Log::error('GraphQL internal error: ' . ($cause ? get_class($cause) . ': ' . $cause->getMessage() : $e->getMessage()), [
                    'path' => implode('.', $e->getPath() ?? []),
                ]);
            }
            $formatted = FormattedError::createFromException($e);
            $formatted['extensions']['code'] ??= match (true) {
                $e->getPrevious() instanceof SyntaxError || $e instanceof SyntaxError => 'GRAPHQL_PARSE_FAILED',
                !$e->isClientSafe() => 'INTERNAL_ERROR',
                str_starts_with($e->getMessage(), 'Max query') => 'QUERY_LIMIT_EXCEEDED',
                $e->getPath() === null => 'GRAPHQL_VALIDATION_FAILED', // unknown field, wrong argument/variable, introspection off
                default => 'BAD_REQUEST',
            };

            return $formatted;
        });
        echo json_encode($result->toArray());
    }

    /**
     * {query, variables} from the multipart fields, each mapped file (its $_FILES entry) placed at its
     * path, e.g. "variables.file" or "variables.files.0". A single operation only (no batching).
     */
    private static function multipart(): array
    {
        $input = json_decode((string) $_POST['operations'], true);
        $input = is_array($input) ? $input : [];
        foreach (json_decode((string) ($_POST['map'] ?? ''), true) ?: [] as $key => $paths) {
            foreach ((array) $paths as $path) {
                $target = &$input;
                foreach (explode('.', (string) $path) as $part) {
                    $target = &$target[$part];
                }
                $target = $_FILES[$key] ?? null;
                unset($target);
            }
        }

        return $input;
    }
}
