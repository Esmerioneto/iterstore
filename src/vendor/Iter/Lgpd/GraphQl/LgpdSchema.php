<?php

declare(strict_types=1);

namespace Iter\Lgpd\GraphQl;

use GraphQL\Error\Error;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use Iter\Core\Config;
use Iter\Core\Db\Connection;
use Iter\Core\RateLimit;
use Iter\Customer\Model\CustomerAuth;
use Iter\Lgpd\Model\CookieCategories;
use Iter\Lgpd\Model\Inventory;
use Iter\Lgpd\Model\PersonalDataService;
use Iter\Lgpd\Model\PolicyService;
use Iter\Lgpd\Model\RequestService;

/**
 * LGPD-PLAN §4 and §5: the data subject's rights from the app and the store web, always for the token's customer
 * (no customerId argument). Each call is recorded in LGPD → Solicitações as answered.
 * §7: the public privacy policy of a website, its acceptance, and Customer.privacyVersion.
 */
final class LgpdSchema
{
    /** fields of the Customer type (CustomerSchema::fields) */
    public static function customerFields(): array
    {
        return [
            'privacyVersion' => [
                'type' => Type::int(),
                'description' => 'A última versão da política de privacidade do website que o cliente aceitou; null se nenhuma. Diferente de privacy.version: mostre a política e peça o aceite (acceptPrivacy).',
                'resolve' => static fn (array $c) => (new PolicyService())->acceptedVersion((int) $c['customer_id']),
            ],
        ];
    }

    /** @return array{query: array, mutation: array} */
    public static function fields(): array
    {
        $me = static fn () => CustomerAuth::customer() ?? throw new Error('Faça login como cliente.', extensions: ['code' => 'UNAUTHENTICATED']);

        $privacyType = new ObjectType([
            'name' => 'Privacy',
            'fields' => [
                'websiteId' => Type::nonNull(Type::id()),
                'version' => ['type' => Type::int(), 'description' => 'Versão em vigor; null antes da primeira publicação (sem política para mostrar).'],
                'publishedAt' => ['type' => Type::string(), 'description' => 'AAAA-MM-DD HH:MM:SS.'],
                'text' => ['type' => Type::string(), 'description' => 'Texto puro; linha em branco separa parágrafos.'],
                'dpo' => [
                    'type' => new ObjectType(['name' => 'PrivacyDpo', 'fields' => ['name' => Type::string(), 'email' => Type::nonNull(Type::string())]]),
                    'description' => 'O encarregado (art. 41); null se não informado em Configurações → LGPD.',
                ],
                'operators' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull(new ObjectType([
                        'name' => 'PrivacyOperator',
                        'fields' => [
                            'name' => Type::nonNull(Type::string()),
                            'country' => Type::nonNull(Type::string()),
                            'purpose' => Type::nonNull(Type::string()),
                            'international' => ['type' => Type::nonNull(Type::boolean()), 'description' => 'Fora do Brasil: transferência internacional (art. 33).'],
                        ],
                    ])))),
                    'description' => 'Com quem a loja compartilha dados pessoais (LGPD → Inventário, só módulos ligados).',
                ],
                'cookieBannerText' => Type::nonNull(Type::string()),
                'cookieCategories' => Type::nonNull(Type::listOf(Type::nonNull(new ObjectType([
                    'name' => 'CookieCategory',
                    'fields' => [
                        'code' => ['type' => Type::nonNull(Type::string()), 'description' => 'necessary, statistics ou marketing.'],
                        'label' => Type::nonNull(Type::string()),
                        'description' => Type::nonNull(Type::string()),
                        'required' => ['type' => Type::nonNull(Type::boolean()), 'description' => 'Sempre ligada (sem opção de recusar).'],
                    ],
                ])))),
            ],
        ]);

        return [
            'query' => [
                'privacy' => [
                    'type' => $privacyType,
                    'description' => 'Política de privacidade de um website (pública, sem login), com o encarregado, os operadores e as categorias de cookies. Sem websiteId, o website padrão; website desconhecido dá null.',
                    'args' => ['websiteId' => Type::id()],
                    'resolve' => static function ($root, array $args): ?array {
                        $websiteId = isset($args['websiteId']) ? (int) $args['websiteId']
                            : (int) Connection::get()->adapter()->fetchOne('SELECT website_id FROM store_website ORDER BY is_default DESC, website_id LIMIT 1');
                        if (!Config::websiteExists($websiteId)) {
                            return null;
                        }
                        $policy = (new PolicyService())->current($websiteId);
                        $dpoEmail = (string) Config::get('lgpd/dpo/email', $websiteId);

                        return [
                            'websiteId' => $websiteId,
                            'version' => $policy['version'] ?? null,
                            'publishedAt' => $policy['published_at'] ?? null,
                            'text' => $policy['content'] ?? null,
                            'dpo' => $dpoEmail === '' ? null : ['name' => (string) Config::get('lgpd/dpo/name', $websiteId) ?: null, 'email' => $dpoEmail],
                            'operators' => array_map(static fn ($o) => ['name' => $o['name'], 'country' => Inventory::countryLabel($o['country']), 'purpose' => implode('; ', array_filter($o['purposes'])),
                                'international' => Inventory::isAbroad($o['country'])], Inventory::operators()),
                            'cookieBannerText' => (string) Config::get('lgpd/cookies/banner', $websiteId),
                            'cookieCategories' => CookieCategories::all($websiteId),
                        ];
                    },
                ],
                'myPersonalData' => [
                    'type' => Type::nonNull(Type::string()),
                    'description' => 'Cópia dos dados pessoais do cliente do token (LGPD, art. 18, II), em JSON: uma chave por assunto (cadastro, pedidos, consentimentos…). Fica registrada em LGPD → Solicitações.',
                    'resolve' => static function () use ($me): string {
                        $customer = $me();
                        // building the copy reads every module: a few per minute are plenty
                        if (!RateLimit::hit('lgpd_export:' . $customer->getId(), 3)) {
                            http_response_code(429);
                            throw new Error('Muitas cópias pedidas. Aguarde um minuto e tente de novo.', extensions: ['code' => 'TOO_MANY_REQUESTS']);
                        }
                        $id = (int) $customer->getId();
                        $email = (string) $customer->getData('email');
                        $data = (new PersonalDataService())->export($id, $email);
                        (new RequestService())->create(['website_id' => (int) $customer->getData('website_id'), 'customer_id' => $id, 'email' => $email,
                            'type' => 'export', 'origin' => PersonalDataService::origin(), 'status' => 'done']);

                        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                    },
                ],
            ],
            'mutation' => [
                'acceptPrivacy' => [
                    'type' => Type::nonNull(Type::boolean()),
                    'description' => 'O cliente do token aceita a política em vigor do website dele. version deve ser a de privacy.version (o texto que foi mostrado): outra dá POLICY_VERSION_OUTDATED. Aceitar de novo não muda nada.',
                    'args' => ['version' => Type::nonNull(Type::int())],
                    'resolve' => static function ($root, array $args) use ($me): bool {
                        $customer = $me();
                        (new PolicyService())->accept((int) $customer->getId(), (int) $customer->getData('website_id'), (int) $args['version'], PersonalDataService::origin());

                        return true;
                    },
                ],
                'deleteMe' => [
                    'type' => Type::nonNull(Type::boolean()),
                    'description' => 'Exclui a conta do cliente do token (LGPD, art. 18, VI): cadastro, endereços, favoritos, newsletter, mensagens e provador. Os pedidos ficam pelo prazo fiscal e são anonimizados depois. Pede a senha atual; com pedido em andamento devolve LGPD_ERASE_BLOCKED. Manda um e-mail de confirmação e o token deixa de valer.',
                    'args' => ['currentPassword' => Type::nonNull(Type::string())],
                    'resolve' => static function ($root, array $args) use ($me): bool {
                        $me();
                        if (!RateLimit::hit('customer_login:' . RateLimit::bucket(), (int) Config::get('customer/security/api_login_per_minute'))) {
                            http_response_code(429);
                            throw new Error('Muitas tentativas. Aguarde um minuto e tente de novo.', extensions: ['code' => 'TOO_MANY_REQUESTS']);
                        }
                        (new PersonalDataService())->eraseMe($args['currentPassword']);
                        CustomerAuth::revokeToken();

                        return true;
                    },
                ],
            ],
        ];
    }
}
