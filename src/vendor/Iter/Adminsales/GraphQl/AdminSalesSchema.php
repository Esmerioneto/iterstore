<?php

declare(strict_types=1);

namespace Iter\Adminsales\GraphQl;

use GraphQL\Error\Error;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use Iter\Admin\Auth;
use Iter\Adminsales\Model\AdminCartService;
use Iter\Core\GraphQl\Schema;
use Iter\Sales\Model\OrderService;
use Iter\Store\Model\Website;

/**
 * Orders created by the admin through the API (ADMINSALES-PLAN §4): createAdminCart gives a cartId, the
 * cart mutations (addToCart, setCartGuestInfo, addresses, shipping, payment) fill it, placeAdminOrder
 * closes it. Everything needs Iter_Adminsales::create.
 */
class AdminSalesSchema
{
    /** @return array{query: array, mutation: array} */
    public static function fields(ObjectType $orderType): array
    {
        $service = new AdminCartService();
        $guard = static fn (callable $resolve) => Schema::guard('Iter_Adminsales::create', $resolve);
        $userId = static fn (): int => (int) Auth::user()->getId();

        return [
            'query' => [],
            'mutation' => [
                'createAdminCart' => [
                    'type' => Type::nonNull(Type::string()),
                    'description' => 'Um pedido em montagem pelo admin; devolve o cartId, usado nas mutations do carrinho e no placeAdminOrder. Sem customerId, o pedido é de um visitante (setCartGuestInfo), se o website vende para visitante.',
                    'args' => ['websiteId' => ['type' => Type::id(), 'description' => 'Omitido = website padrão.'], 'customerId' => ['type' => Type::id(), 'description' => 'Um cliente do website.']],
                    'resolve' => $guard(static fn ($root, array $args) => $service->create(
                        (int) ($args['websiteId'] ?? (new Website())->getResource()->getDefaultId()),
                        isset($args['customerId']) ? (int) $args['customerId'] : null,
                        $userId()
                    )),
                ],
                'placeAdminOrder' => [
                    'type' => Type::nonNull($orderType),
                    'description' => 'Fecha o pedido em montagem. O pedido fica com o admin do token.',
                    'args' => [
                        'cartId' => Type::nonNull(Type::string()),
                        'paymentReceived' => ['type' => Type::boolean(), 'defaultValue' => false, 'description' => 'Já confirma o pagamento (balcão, dinheiro). Exige Iter_Sales::order_payment.'],
                        'notifyCustomer' => ['type' => Type::boolean(), 'defaultValue' => true, 'description' => 'Manda ao cliente os e-mails do pedido.'],
                        'comment' => ['type' => Type::string(), 'description' => 'Comentário interno no histórico (não vai para o cliente).'],
                    ],
                    'resolve' => $guard(static function ($root, array $args) use ($service, $userId): array {
                        if ($args['paymentReceived'] && !Auth::isAllowed('Iter_Sales::order_payment')) {
                            throw new Error('O seu perfil não pode confirmar pagamentos: envie paymentReceived: false.', extensions: ['code' => 'FORBIDDEN']);
                        }
                        // a retry after the order was placed finds the closed cart: the same order comes back
                        $orderId = $service->place($service->resolve($args['cartId'], true), $userId(), $args['paymentReceived'], $args['notifyCustomer'], (string) ($args['comment'] ?? ''));

                        return (new OrderService())->get($orderId);
                    }),
                ],
            ],
        ];
    }
}
