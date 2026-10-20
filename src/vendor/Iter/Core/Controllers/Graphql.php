<?php

declare(strict_types=1);

namespace Iter\Core\Controllers;

use Iter\Admin\Auth;
use Iter\Admin\Model\ApiKey;
use Iter\Core\Config;
use Iter\Core\Log;
use Iter\Core\RateLimit;
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
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Sales-Channel, X-Api-Key');
            header('Access-Control-Max-Age: 86400');
        }
        header('Vary: Origin');
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { // preflight
            http_response_code(204);
            return;
        }

        header('Content-Type: application/json');
        // file upload: GraphQL multipart request spec, operations = {query, variables}, map = {"0": ["variables.file"]}
        // (PHP already parsed it, limited by upload_max_filesize / post_max_size)
        $multipart = isset($_POST['operations']);

        // who calls is known from the headers alone: checked before the body is read
        // API login: a Bearer token acts as its admin user (generateAdminToken) or customer (generateCustomerToken,
        // which never passes the admin guard); a bad one is refused, not ignored
        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? (function_exists('getallheaders') ? (array_change_key_case(getallheaders())['authorization'] ?? '') : '');
        if (preg_match('/^Bearer\s+(\S+)$/i', (string) $authorization, $m)
            && !Auth::useToken($m[1]) && !(\isModuleEnabled('iter_customer') && CustomerAuth::useToken($m[1]))) {
            http_response_code(401);
            echo json_encode(['errors' => [['message' => 'Token inválido ou expirado. Faça login de novo.', 'extensions' => ['code' => 'TOKEN_INVALID']]]]);
            return;
        }

        // an admin (panel session or admin token) is trusted: no app key, no general limit, and introspection for GraphiQL
        $admin = Auth::user() !== null;
        // a key sent is always checked (and its "Último uso" marked), so the panel shows the app sends it before it's required
        $appKey = trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
        $validKey = $appKey !== '' && ApiKey::valid($appKey);
        if (!$admin && Config::get('system/api/require_key') && !$validKey) {
            http_response_code(401);
            echo json_encode(['errors' => [['message' => 'Chave do aplicativo ausente ou inválida (cabeçalho X-Api-Key).', 'extensions' => ['code' => 'API_KEY_INVALID']]]]);
            return;
        }
        if (!$admin && !RateLimit::hit('api:' . RateLimit::bucket(), (int) Config::get('system/api/requests_per_minute'))) {
            http_response_code(429);
            header('Retry-After: 60');
            echo json_encode(['errors' => [['message' => 'Muitas requisições. Aguarde um minuto e tente de novo.', 'extensions' => ['code' => 'TOO_MANY_REQUESTS']]]]);
            return;
        }

        // a multipart form from another site skips the CORS preflight: with the session cookie alone it would be CSRF
        if ($multipart && !isset($m[1])) {
            http_response_code(400);
            echo json_encode(['errors' => [['message' => 'Envio de arquivo exige o token (Authorization: Bearer).', 'extensions' => ['code' => 'UNAUTHENTICATED']]]]);
            return;
        }

        // on the panel's session cookie, JSON only: a form from another site can't send application/json without a
        // CORS preflight, so SameSite isn't the only thing between a sibling subdomain and the admin's API
        if ($admin && !isset($m[1]) && !$multipart && stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
            http_response_code(415);
            echo json_encode(['errors' => [['message' => 'Envie a chamada com Content-Type: application/json.', 'extensions' => ['code' => 'UNSUPPORTED_MEDIA_TYPE']]]]);
            return;
        }

        // the JSON body has a size cap (Configurações → API), read with a ceiling: Content-Length can be missing (chunked)
        $maxBytes = (int) Config::get($admin ? 'system/api/max_body_kb_admin' : 'system/api/max_body_kb') * 1024;
        $body = '';
        if (!$multipart) {
            $in = fopen('php://input', 'rb');
            $body = $maxBytes > 0 && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBytes ? null : (string) stream_get_contents($in, $maxBytes > 0 ? $maxBytes + 1 : -1);
            fclose($in);
            if ($body === null || ($maxBytes > 0 && strlen($body) > $maxBytes)) {
                http_response_code(413);
                echo json_encode(['errors' => [['message' => 'Chamada grande demais: o máximo é ' . intdiv($maxBytes, 1024) . ' KB.', 'extensions' => ['code' => 'PAYLOAD_TOO_LARGE']]]]);
                return;
            }
        }
        $input = $multipart ? self::multipart() : (json_decode($body, true) ?? []);
        $query = $input['query'] ?? '';
        $variables = $input['variables'] ?? null;

        $schema = Schema::build();
        if ($schema === null) {
            echo json_encode(['errors' => [['message' => 'Nenhum módulo com API está ativo.']]]);
            return;
        }
        // a huge or deeply nested query is refused before running (0 = no limit)
        $rules = DocumentValidator::allRules();
        $rules[] = new QueryDepth((int) Config::get('system/api/max_depth'));
        // a paged list costs its fields × pageSize (Schema::paged); the admin has its own limit (0 = none)
        $complexity = new QueryComplexity((int) Config::get($admin ? 'system/api/max_complexity_admin' : 'system/api/max_complexity'));
        $complexity->setRawVariableValues(is_array($variables) ? $variables : null); // else products(pageSize: $n) would cost the default page
        $rules[] = $complexity;
        if (!$admin && !Config::get('system/api/introspection')) {
            $rules[] = new DisableIntrospection(DisableIntrospection::ENABLED);
        }
        $result = \GraphQL\GraphQL::executeQuery($schema, $query, null, null, $variables, null, null, $rules);

        // every error carries extensions.code, so the clients branch on it instead of the message
        // Keep the real cause of unexpected resolver failures in the system log; GraphQL otherwise
        // masks it as "Internal server error", leaving checkout failures impossible to diagnose.
        $result->setErrorFormatter(static function (Error $e) use ($admin): array {
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
            // "Did you mean ...?" would list the schema's names to anyone, with the introspection off; the admin (GraphiQL) keeps it
            if (!$admin && $formatted['extensions']['code'] === 'GRAPHQL_VALIDATION_FAILED') {
                $formatted['message'] = (string) preg_replace('/ Did you mean .*\?$/s', '', $formatted['message']);
            }

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
