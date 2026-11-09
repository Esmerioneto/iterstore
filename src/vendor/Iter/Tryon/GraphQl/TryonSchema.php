<?php

declare(strict_types=1);

namespace Iter\Tryon\GraphQl;

use GraphQL\Error\Error;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use Iter\Catalog\Model\ProductService;
use Iter\Core\Config;
use Iter\Core\GraphQl\Schema;
use Iter\Core\RateLimit;
use Iter\Customer\Model\CustomerAuth;
use Iter\Eav\Model\ValueService;
use Iter\Tryon\Model\TryonService;

/**
 * The provador virtual for the app (TRYON-PLAN §4): the customer of the token sends a photo with startTryOn and
 * asks tryOn(id) every 2 s until SUCCESS or ERROR. The provider behind it never shows here.
 */
class TryonSchema
{
    /** Product.tryOnAvailable, given to ProductSchema before its type exists */
    public static function productFields(): array
    {
        return [
            'tryOnAvailable' => [
                'type' => Type::nonNull(Type::boolean()),
                'description' => 'O app mostra "Experimentar": o cliente do token pode usar o provador (no cadastro dele), o produto tem "Provador virtual" ligado e o provador está disponível. Sem login, false. Não peça nas listas.',
                'resolve' => static function (array $p): bool {
                    static $customer = null; // once per request: the customer and the provador don't change between products
                    $service = new TryonService();
                    $me = CustomerAuth::customer();
                    $customer ??= $me !== null && $service->customerAllowed((int) $me->getId()) && $service->available();

                    return $customer && $service->productAllowed((int) $p['product_id']);
                },
            ],
        ];
    }

    /** @return array{query: array, mutation: array} */
    public static function fields(ObjectType $productType): array
    {
        $service = new TryonService();
        $customer = static fn () => CustomerAuth::customer() ?? throw new Error('Entre na sua conta para usar o provador.', extensions: ['code' => 'UNAUTHENTICATED']);
        $tryOnType = new ObjectType([
            'name' => 'TryOn',
            'fields' => [
                'id' => ['type' => Type::nonNull(Type::id()), 'resolve' => static fn (array $t) => $t['tryon_id']],
                'status' => ['type' => Type::nonNull(new EnumType(['name' => 'TryOnStatus', 'values' => ['PROCESSING', 'SUCCESS', 'ERROR', 'EXPIRED']])),
                    'description' => 'PROCESSING: consulte de novo em 2 s. EXPIRED: a imagem deixou de valer.', 'resolve' => static fn (array $t) => $t['state']],
                'progress' => ['type' => Type::int(), 'description' => '0 a 100, quando o provedor informa.', 'resolve' => static fn (array $t) => $t['progress'] === null ? null : (int) $t['progress']],
                'imageUrl' => ['type' => Type::string(), 'description' => 'A foto com a peça, em SUCCESS.', 'resolve' => static fn (array $t) => $t['state'] === 'SUCCESS' ? $t['image_url'] : null],
                'expiresAt' => ['type' => Type::string(), 'description' => 'Até quando imageUrl vale.', 'resolve' => static fn (array $t) => $t['state'] === 'SUCCESS' ? $t['image_expires_at'] : null],
                'errorCode' => ['type' => Type::string(), 'description' => 'Em ERROR: TRYON_FAILED, TRYON_BUSY, TRYON_REFUSED, TRYON_TIMEOUT, TRYON_UNAVAILABLE.', 'resolve' => static fn (array $t) => $t['state'] === 'ERROR' ? $t['error_code'] : null],
                'errorMessage' => ['type' => Type::string(), 'description' => 'Em ERROR, para mostrar ao cliente.', 'resolve' => static fn (array $t) => $t['state'] === 'ERROR' ? TryonService::message($t['error_code']) : null],
                'product' => ['type' => $productType, 'resolve' => static fn (array $t) => $t['product_id'] === null ? null : (new ProductService())->get((int) $t['product_id'])->getData()],
                'cached' => ['type' => Type::nonNull(Type::boolean()), 'description' => 'true = o resultado de uma prova igual (mesma foto, mesmo produto) ainda válida: veio na hora e não contou no limite.'],
                'createdAt' => ['type' => Type::nonNull(Type::string()), 'resolve' => static fn (array $t) => $t['created_at']],
            ],
        ]);

        return [
            'query' => [
                'tryOnSettings' => [
                    'type' => Type::nonNull(new ObjectType([
                        'name' => 'TryOnSettings',
                        'fields' => [
                            'enabled' => ['type' => Type::nonNull(Type::boolean()), 'description' => 'false sem login, para cliente sem "Pode usar o provador virtual" no cadastro, com o provador desligado ou o teto do mês atingido.'],
                            'remainingToday' => ['type' => Type::nonNull(Type::int()), 'resolve' => static fn (array $s) => $s['remaining_today']],
                            'maxUploadKb' => ['type' => Type::nonNull(Type::int()), 'description' => 'O app reduz a foto abaixo disso.', 'resolve' => static fn (array $s) => $s['max_upload_kb']],
                            'tips' => Type::nonNull(Type::listOf(Type::nonNull(Type::string()))),
                            'termsText' => ['type' => Type::nonNull(Type::string()), 'description' => 'O consentimento, antes da primeira foto; texto novo pede o aceite de novo.', 'resolve' => static fn (array $s) => $s['terms']],
                        ],
                    ])),
                    'description' => 'Antes de mostrar o provador. Funciona sem login (enabled: false).',
                    'resolve' => static fn (): array => $service->settings(CustomerAuth::customer() === null ? null : (int) CustomerAuth::customer()->getId()),
                ],
                'tryOn' => [
                    'type' => Type::nonNull($tryOnType),
                    'description' => 'Uma prova do cliente do token; consulte a cada 2 s enquanto PROCESSING. Erro: TRYON_NOT_FOUND.',
                    'args' => ['id' => Type::nonNull(Type::id())],
                    'resolve' => static fn ($root, array $args): array => $service->get((int) $args['id'], (int) $customer()->getId()),
                ],
            ],
            'mutation' => [
                'startTryOn' => [
                    'type' => Type::nonNull($tryOnType),
                    'description' => 'A foto do cliente vestindo o produto, pelo multipart request spec com o token do cliente. Devolve PROCESSING (consulte tryOn) '
                        . 'ou, com a mesma foto e o mesmo produto ainda válidos, o resultado na hora (cached). Erros: TRYON_DISABLED, TRYON_NOT_ALLOWED, TRYON_UNAVAILABLE, '
                        . 'TRYON_DAILY_LIMIT, TRYON_IN_PROGRESS, TRYON_BUSY, TRYON_FAILED, TRYON_REFUSED, TOO_MANY_REQUESTS, FILE_TOO_LARGE, FILE_UPLOAD_FAILED, '
                        . 'INVALID_IMAGE, PRODUCT_NOT_FOUND, PRODUCT_NOT_TRYON.',
                    'args' => [
                        'person' => ['type' => Type::nonNull(Schema::upload()), 'description' => 'A foto do cliente: JPG, PNG ou WebP.'],
                        'productId' => ['type' => Type::nonNull(Type::id()), 'description' => 'O produto (ou a variação, para a cor escolhida) com "Provador virtual" ligado.'],
                        'imageCode' => ['type' => Type::string(), 'description' => 'O campo de imagem do produto; omitido = a primeira imagem.'],
                    ],
                    'resolve' => static function ($root, array $args) use ($service, $customer): array {
                        $me = $customer();
                        if (!RateLimit::hit('tryon:' . $me->getId(), (int) Config::get('catalog/tryon/per_minute'))) {
                            http_response_code(429);
                            throw new Error('Muitos pedidos seguidos. Aguarde um minuto.', extensions: ['code' => 'TOO_MANY_REQUESTS']);
                        }
                        $photo = ValueService::uploaded($args['person']); // FILE_TOO_LARGE, FILE_UPLOAD_FAILED

                        return $service->start((int) $me->getId(), (int) $me->getData('website_id'), $photo['path'], (int) $args['productId'], $args['imageCode'] ?? null);
                    },
                ],
            ],
        ];
    }
}
