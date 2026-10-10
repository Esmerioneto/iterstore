<?php

declare(strict_types=1);

namespace Iter\Mercadopago\GraphQl;

use GraphQL\Error\Error;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use Iter\Core\Config;
use Iter\Core\RateLimit;
use Iter\Customer\Model\CustomerAuth;
use Iter\Mercadopago\Model\MercadopagoService;
use Iter\Sales\Model\OrderService;
use Iter\Sales\Model\SalesException;
use Iter\Store\Model\Website;

/**
 * Paying an order with the Mercado Pago (MERCADOPAGO-PLAN). After placeOrder with a mercadopago_* method,
 * the app calls payOrder: Pix gives the QR code to show; a card goes with the token the app made with the
 * SDK (mercadopagoSettings.publicKey). The order turns "Pagamento confirmado" by itself when it's paid.
 */
class MercadopagoSchema
{
    /** @return array{query: array, mutation: array} */
    public static function fields(ObjectType $orderType): array
    {
        $service = new MercadopagoService();
        $orders = new OrderService();
        $websiteId = static fn (array $args): int => (int) ($args['websiteId'] ?? (new Website())->getResource()->getDefaultId());

        $paymentType = new ObjectType([
            'name' => 'OrderPayment',
            'fields' => [
                'id' => ['type' => Type::nonNull(Type::id()), 'description' => 'O id do pagamento no Mercado Pago.', 'resolve' => fn ($p) => $p['external_id']],
                'method' => ['type' => Type::nonNull(Type::string()), 'description' => 'mercadopago_pix, mercadopago_credit ou mercadopago_debit.'],
                'status' => ['type' => Type::nonNull(Type::string()), 'description' => 'O do Mercado Pago: pending, in_process, approved, rejected, cancelled, refunded...'],
                'statusLabel' => ['type' => Type::nonNull(Type::string()), 'resolve' => fn ($p) => $p['status_label']],
                'statusDetail' => ['type' => Type::string(), 'description' => 'O motivo do Mercado Pago (cc_rejected_insufficient_amount...), para a mensagem ao cliente.', 'resolve' => fn ($p) => $p['status_detail']],
                'amount' => Type::nonNull(Type::float()),
                'installments' => Type::int(),
                'cardBrand' => ['type' => Type::string(), 'description' => 'A bandeira do cartão (visa, master, elo...).', 'resolve' => fn ($p) => $p['card_brand']],
                'cardNumber' => ['type' => Type::string(), 'description' => 'O número do cartão mascarado (4509 95•• •••• 3704).', 'resolve' => fn ($p) => $p['card_number']],
                'pixQrCode' => ['type' => Type::string(), 'description' => 'O Pix copia e cola.', 'resolve' => fn ($p) => $p['qr_code']],
                'pixQrCodeBase64' => ['type' => Type::string(), 'description' => 'A imagem PNG do QR Code em base64.', 'resolve' => fn ($p) => $p['qr_code_base64']],
                'pixTicketUrl' => ['type' => Type::string(), 'description' => 'A página do Mercado Pago com o QR Code e as instruções.', 'resolve' => fn ($p) => $p['ticket_url']],
                'expiresAt' => ['type' => Type::string(), 'description' => 'Até quando o Pix pode ser pago.', 'resolve' => fn ($p) => $p['expires_at']],
                'challengeUrl' => ['type' => Type::string(), 'description' => 'Cartão com autenticação 3DS: o app mostra este desafio (com challengeCreq) e depois relê o pedido.', 'resolve' => fn ($p) => $p['challenge_url']],
                'challengeCreq' => ['type' => Type::string(), 'resolve' => fn ($p) => $p['challenge_creq']],
                'order' => ['type' => Type::nonNull($orderType), 'description' => 'O pedido como ficou (aprovado = "processing").', 'resolve' => fn ($p) => $orders->get((int) $p['order_id'])],
            ],
        ]);

        return [
            'query' => [
                'mercadopagoSettings' => [
                    'type' => Type::nonNull(new ObjectType([
                        'name' => 'MercadopagoSettings',
                        'fields' => [
                            'publicKey' => ['type' => Type::string(), 'description' => 'Para o SDK gerar o token do cartão; null sem Mercado Pago configurado.'],
                            'maxInstallments' => ['type' => Type::nonNull(Type::int()), 'description' => 'O máximo de parcelas no crédito.'],
                        ],
                    ])),
                    'args' => ['websiteId' => ['type' => Type::id(), 'description' => 'Omitido = website padrão.']],
                    'resolve' => static fn ($root, array $args): array => [
                        'publicKey' => MercadopagoService::publicKey($websiteId($args)) ?: null,
                        'maxInstallments' => MercadopagoService::maxInstallments($websiteId($args)),
                    ],
                ],
            ],
            'mutation' => [
                'payOrder' => [
                    'type' => Type::nonNull($paymentType),
                    'description' => 'Cobra pelo Mercado Pago um pedido "Aguardando pagamento". Cliente logado: um pedido dele. Visitante: o número e o e-mail do pedido. '
                        . 'Pix: devolve o QR Code (chamar de novo devolve o mesmo enquanto valer). Cartão: envie card; recusado, pode tentar de novo com outro token.',
                    'args' => [
                        'number' => Type::nonNull(Type::string()),
                        'email' => ['type' => Type::string(), 'description' => 'O do pedido, para o visitante.'],
                        'websiteId' => ['type' => Type::id(), 'description' => 'Quando dois websites usam o mesmo prefixo.'],
                        'card' => new InputObjectType([
                            'name' => 'MercadopagoCardInput',
                            'fields' => [
                                'token' => ['type' => Type::nonNull(Type::string()), 'description' => 'O token do cartão gerado pelo SDK (vale uma vez).'],
                                'paymentMethodId' => ['type' => Type::nonNull(Type::string()), 'description' => 'A bandeira que o SDK devolveu (visa, master, elo...).'],
                                'installments' => ['type' => Type::int(), 'defaultValue' => 1, 'description' => 'No débito, sempre 1.'],
                                'issuerId' => ['type' => Type::string(), 'description' => 'O emissor que o SDK devolveu.'],
                            ],
                        ]),
                    ],
                    'resolve' => static function ($root, array $args) use ($service, $orders): array {
                        // the guest's number + e-mail is a weak secret: the same limit as guestOrder
                        if (!RateLimit::hit('order_pay:' . RateLimit::ip(), (int) Config::get('sales/order/guest_lookup_per_minute'))) {
                            http_response_code(429);
                            throw new Error('Muitas tentativas seguidas. Aguarde um minuto.', extensions: ['code' => 'TOO_MANY_REQUESTS']);
                        }
                        $me = CustomerAuth::customer();
                        $websiteId = isset($args['websiteId']) ? (int) $args['websiteId'] : null;
                        $id = match (true) {
                            $me !== null => $orders->findIdByNumber($args['number'], (int) $me->getData('website_id'), ['customer_id' => (int) $me->getId()]),
                            ($args['email'] ?? '') !== '' => $orders->findIdByNumber($args['number'], $websiteId, ['email' => $args['email']]),
                            default => throw new Error('Faça login ou informe o e-mail do pedido.', extensions: ['code' => 'UNAUTHENTICATED']),
                        } ?? throw new SalesException('ORDER_NOT_FOUND', 'Pedido não encontrado.');
                        $card = isset($args['card']) ? ['token' => $args['card']['token'], 'payment_method_id' => $args['card']['paymentMethodId'],
                            'installments' => $args['card']['installments'], 'issuer_id' => $args['card']['issuerId'] ?? ''] : null;

                        return $service->pay($id, $card);
                    },
                ],
            ],
        ];
    }
}
