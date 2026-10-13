<?php

declare(strict_types=1);

namespace Iter\Shipping\GraphQl;

use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Error\Error;
use Iter\Core\Log;
use Iter\Customer\Model\CustomerAuth;
use Iter\Sales\Model\CartService;
use Iter\Core\GraphQl\Schema;
use Iter\Shipping\Model\ShippingService;
use Iter\Store\Model\Website;

/** The table rate for the admin (Iter_Shipping::rates); the cart's shipping fields are in Sales' CartSchema */
class ShippingSchema
{
    /** @return array{query: array, mutation: array} */
    public static function fields(): array
    {
        $service = new ShippingService();
        $carts = new CartService();
        $guard = static fn (callable $resolve) => Schema::guard('Iter_Shipping::rates', $resolve);
        $website = static fn (array $args): int => (int) ($args['websiteId'] ?? (new Website())->getResource()->getDefaultId());
        $rateFields = [
            'postcodeFrom' => Type::nonNull(Type::string()),
            'postcodeTo' => Type::nonNull(Type::string()),
            'weightTo' => ['type' => Type::float(), 'description' => 'Peso até (kg); null = qualquer peso.'],
            'price' => Type::nonNull(Type::float()),
            'days' => ['type' => Type::nonNull(Type::int()), 'description' => 'Prazo em dias úteis.'],
        ];
        $rateType = new ObjectType(['name' => 'ShippingTableRate', 'fields' => array_replace($rateFields, [
            'postcodeFrom' => ['type' => Type::nonNull(Type::string()), 'resolve' => fn ($r) => $r['postcode_from']],
            'postcodeTo' => ['type' => Type::nonNull(Type::string()), 'resolve' => fn ($r) => $r['postcode_to']],
            'weightTo' => ['type' => Type::float(), 'resolve' => fn ($r) => $r['weight_to']],
        ])]);
        $rateInput = new InputObjectType(['name' => 'ShippingTableRateInput', 'fields' => array_replace($rateFields, ['days' => ['type' => Type::int(), 'defaultValue' => 0]])]);
        $websiteArg = ['websiteId' => ['type' => Type::id(), 'description' => 'Omitido = website padrão.']];

        return [
            'query' => [
                'shippingTableRates' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($rateType))),
                    'args' => $websiteArg,
                    'resolve' => $guard(static fn ($root, array $args) => $service->getTableRates($website($args))),
                ],
                'shippingQuotes' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull(new ObjectType([
                        'name' => 'ShippingQuote',
                        'fields' => [
                            'code' => Type::nonNull(Type::string()),
                            'label' => Type::nonNull(Type::string()),
                            'amount' => Type::nonNull(Type::float()),
                            'days' => Type::nonNull(Type::int()),
                        ],
                    ])))),
                    'description' => 'Todas as opções de frete disponíveis para o carrinho e CEP informados, usando o mesmo serviço central do checkout. Não salva o endereço nem seleciona o frete.',
                    'args' => [
                        'cartId' => ['type' => Type::string(), 'description' => 'O cartId do visitante; cliente logado pode omitir.'],
                        'destinationCep' => Type::nonNull(Type::string()),
                    ],
                    'resolve' => static function ($root, array $args) use ($carts, $service): array {
                        $cep = preg_replace('/\\D/', '', (string) $args['destinationCep']);
                        if (strlen($cep) !== 8) {
                            throw new Error('Informe um CEP de destino com 8 dígitos.', extensions: ['code' => 'SHIPPING_CEP_INVALID']);
                        }
                        $quoteId = $carts->resolve($args['cartId'] ?? null, CustomerAuth::customer());
                        $cart = $carts->view($quoteId);
                        try {
                            return array_values($service->rates($cart, $cep));
                        } catch (\Throwable $e) {
                            Log::error('Shipping GraphQL: falha na cotação.', ['website_id' => (int) $cart['website_id'], 'reason' => $e->getMessage()]);
                            throw new Error('Não foi possível consultar os fretes agora. Tente novamente.', extensions: ['code' => 'SHIPPING_UNAVAILABLE']);
                        }
                    },
                ],
            ],
            'mutation' => [
                'saveShippingTableRates' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($rateType))),
                    'description' => 'A lista enviada é a tabela inteira do website (faixa ausente é removida). CEP com ou sem máscara. Para um CEP, vale a faixa com o menor "peso até" que comporte o carrinho.',
                    'args' => $websiteArg + ['rates' => Type::nonNull(Type::listOf(Type::nonNull($rateInput)))],
                    'resolve' => $guard(static function ($root, array $args) use ($service, $website): array {
                        $service->saveTableRates($website($args), array_map(static fn ($r) => [
                            'postcode_from' => $r['postcodeFrom'], 'postcode_to' => $r['postcodeTo'], 'weight_to' => $r['weightTo'] ?? null, 'price' => $r['price'], 'days' => $r['days'],
                        ], $args['rates']));

                        return $service->getTableRates($website($args));
                    }),
                ],
            ],
        ];
    }
}
