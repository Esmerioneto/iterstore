<?php

declare(strict_types=1);

namespace Iter\Marketing\GraphQl;

use GraphQL\Error\Error;
use GraphQL\Type\Definition\Type;
use Iter\Core\Config;
use Iter\Core\RateLimit;
use Iter\Marketing\Model\SubscriberService;
use Iter\Store\Model\Website;

/** NEWSLETTER-PLAN §3: public (the app's key is enough), no login */
final class NewsletterSchema
{
    /** @return array{query: array, mutation: array} */
    public static function fields(): array
    {
        $service = new SubscriberService();

        return [
            'query' => [],
            'mutation' => [
                'subscribeNewsletter' => [
                    'type' => Type::nonNull(Type::boolean()),
                    'description' => 'Inscreve o e-mail na newsletter da loja e manda o e-mail de confirmação (só recebe depois de confirmar). '
                        . 'Sempre true, mesmo para um e-mail já inscrito, para não revelar quem está cadastrado. Erros: INVALID_INPUT (e-mail inválido ou website desconhecido), '
                        . 'TOO_MANY_REQUESTS (Configurações → Marketing → Newsletter → inscrições por minuto, por IP).',
                    'args' => ['email' => Type::nonNull(Type::string()), 'websiteId' => ['type' => Type::id(), 'description' => 'Omitido = website padrão.']],
                    'resolve' => static function ($root, array $args) use ($service): bool {
                        if (!RateLimit::hit('newsletter:' . RateLimit::bucket(), (int) Config::get('marketing/newsletter/per_minute'))) {
                            http_response_code(429);
                            throw new Error('Muitas inscrições seguidas. Aguarde um minuto e tente de novo.', extensions: ['code' => 'TOO_MANY_REQUESTS']);
                        }
                        $service->subscribe($args['email'], (int) ($args['websiteId'] ?? (new Website())->getResource()->getDefaultId()), RateLimit::ip());

                        return true;
                    },
                ],
                'confirmNewsletter' => [
                    'type' => Type::nonNull(Type::boolean()),
                    'description' => 'A página do link do e-mail de confirmação chama com o token do link. Vale uma vez. Erro: TOKEN_INVALID (desconhecido, já usado ou vencido).',
                    'args' => ['token' => Type::nonNull(Type::string())],
                    'resolve' => static function ($root, array $args) use ($service): bool {
                        $service->confirm($args['token']);

                        return true;
                    },
                ],
            ],
        ];
    }
}
