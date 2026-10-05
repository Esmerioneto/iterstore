<?php

declare(strict_types=1);

namespace Iter\Sales\GraphQl;

use GraphQL\Error\Error;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use Iter\Admin\Auth;
use Iter\Core\GraphQl\Schema;
use Iter\Sales\Model\OrderService;
use Iter\Sales\Model\SalesException;

/**
 * Order service for an external admin app (COMMERCE-PLAN §8): the admin screen's list and actions, with
 * the same permissions. Every change goes to the history with the token's admin.
 */
class OrderSchema
{
    /** @return array{query: array, mutation: array} */
    public static function fields(ObjectType $orderType): array
    {
        $service = new OrderService();
        $userId = static fn (): int => (int) Auth::user()->getId();
        $view = static fn (int $id): array => $service->get($id);
        $date = static function (?string $value, string $name): string {
            if ($value !== null && $value !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                throw new Error("{$name}: use AAAA-MM-DD.", extensions: ['code' => 'BAD_USER_INPUT']);
            }
            return (string) $value;
        };
        $filters = static fn (array $args): array => [
            'status' => (string) ($args['status'] ?? ''),
            'website_id' => (string) ($args['websiteId'] ?? ''),
            'customer_id' => (string) ($args['customerId'] ?? ''),
            'q' => trim((string) ($args['search'] ?? '')),
            'from' => $date($args['from'] ?? null, 'from'),
            'to' => $date($args['to'] ?? null, 'to'),
        ];
        $statusEnum = new EnumType(['name' => 'OrderStatus', 'values' => array_combine(array_keys(OrderService::STATUS_LABELS),
            array_map(static fn ($code, $label) => ['value' => $code, 'description' => $label], array_keys(OrderService::STATUS_LABELS), OrderService::STATUS_LABELS))]);
        $filterArgs = [
            'status' => $statusEnum,
            'websiteId' => Type::id(),
            'customerId' => Type::id(),
            'search' => ['type' => Type::string(), 'description' => 'Parte do número, do e-mail ou do nome.'],
            'from' => ['type' => Type::string(), 'description' => 'Feitos a partir deste dia (AAAA-MM-DD).'],
            'to' => ['type' => Type::string(), 'description' => 'Até este dia, inclusive (AAAA-MM-DD).'],
        ];
        $idArg = ['id' => Type::nonNull(Type::id())];
        // runs one action, then gives the order back as it is now
        $act = static fn (string $resource, callable $action) => Schema::guard($resource, static function ($root, array $args) use ($action, $view, $userId): array {
            $action((int) $args['id'], $args, $userId());

            return $view((int) $args['id']);
        });

        return [
            'query' => [
                'orders' => [
                    'type' => Type::nonNull(new ObjectType([
                        'name' => 'OrderList',
                        'fields' => [
                            'items' => Type::nonNull(Type::listOf(Type::nonNull($orderType))),
                            'total' => Type::nonNull(Type::int()),
                            'amount' => ['type' => Type::nonNull(Type::float()), 'description' => 'Soma dos pedidos filtrados, sem os cancelados.'],
                        ],
                    ])),
                    'args' => $filterArgs + [
                        'page' => ['type' => Type::int(), 'defaultValue' => 1],
                        'pageSize' => ['type' => Type::int(), 'defaultValue' => 20, 'description' => 'Máximo 100.'],
                        'sort' => ['type' => new EnumType(['name' => 'OrderSort', 'values' => ['CREATED_AT' => ['value' => 'created_at'], 'NUMBER' => ['value' => 'number'],
                            'CUSTOMER' => ['value' => 'customer'], 'TOTAL' => ['value' => 'total'], 'STATUS' => ['value' => 'status']]]), 'defaultValue' => 'created_at'],
                        'sortDir' => ['type' => new EnumType(['name' => 'SortDirection', 'values' => ['ASC' => ['value' => 'asc'], 'DESC' => ['value' => 'desc']]]), 'defaultValue' => 'desc'],
                    ],
                    'resolve' => Schema::guard('Iter_Sales::order', static function ($root, array $args) use ($service, $filters, $view): array {
                        $result = $service->search($filters($args), max(1, $args['page']), min(100, max(1, $args['pageSize'])), $args['sort'], $args['sortDir']);
                        // ponytail: get() per row (items, addresses, history): a page of 100 is ~400 small queries; batch when it shows
                        return ['items' => array_map(static fn ($o) => $view((int) $o['order_id']), $result['items'])] + $result;
                    }),
                ],
                'order' => [
                    'type' => $orderType,
                    'description' => 'Pelo id ou pelo número (SP-000001). null se não existir.',
                    'args' => ['id' => Type::id(), 'number' => Type::string(), 'websiteId' => ['type' => Type::id(), 'description' => 'Com number, quando dois websites usam o mesmo prefixo.']],
                    'resolve' => Schema::guard('Iter_Sales::order', static function ($root, array $args) use ($service, $view): ?array {
                        $id = isset($args['id']) ? (int) $args['id'] : (isset($args['number']) ? $service->findIdByNumber($args['number'], isset($args['websiteId']) ? (int) $args['websiteId'] : null) : null);
                        try {
                            return $id === null ? null : $view($id);
                        } catch (SalesException) { // ORDER_NOT_FOUND
                            return null;
                        }
                    }),
                ],
                'salesSummary' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull(new ObjectType([
                        'name' => 'SalesSummaryRow',
                        'fields' => [
                            'status' => Type::nonNull($statusEnum),
                            'statusLabel' => ['type' => Type::nonNull(Type::string()), 'resolve' => fn ($r) => OrderService::STATUS_LABELS[$r['status']]],
                            'count' => Type::nonNull(Type::int()),
                            'amount' => Type::nonNull(Type::float()),
                        ],
                    ])))),
                    'description' => 'Quantos pedidos e quanto em cada situação, no período (todas as situações vêm, mesmo com 0).',
                    'args' => array_intersect_key($filterArgs, array_flip(['websiteId', 'from', 'to'])),
                    'resolve' => Schema::guard('Iter_Sales::order', static function ($root, array $args) use ($service, $filters): array {
                        $rows = [];
                        foreach ($service->totalsByStatus($filters($args)) as $status => $totals) {
                            $rows[] = ['status' => $status] + $totals;
                        }
                        return $rows;
                    }),
                ],
            ],
            'mutation' => [
                'confirmOrderPayment' => [
                    'type' => Type::nonNull($orderType),
                    'description' => 'Aguardando pagamento → Pagamento confirmado. Avisa o cliente. Exige Iter_Sales::order_payment.',
                    'args' => $idArg + ['comment' => ['type' => Type::string(), 'description' => 'Ex.: comprovante recebido pelo WhatsApp.']],
                    'resolve' => $act('Iter_Sales::order_payment', static fn (int $id, array $a, int $u) => $service->confirmPayment($id, $a['comment'] ?? null, $u)),
                ],
                'shipOrder' => [
                    'type' => Type::nonNull($orderType),
                    'description' => 'Pagamento confirmado → Enviado: baixa o estoque da origem e manda o rastreio ao cliente. Exige Iter_Sales::order_ship.',
                    'args' => $idArg + [
                        'sourceCode' => ['type' => Type::string(), 'description' => 'A origem de onde sai. Omitida = a sugerida (a que tem tudo e mais estoque).'],
                        'carrier' => Type::string(),
                        'trackingCode' => Type::string(),
                    ],
                    'resolve' => $act('Iter_Sales::order_ship', static function (int $id, array $a, int $u) use ($service): void {
                        $sources = $service->shipSources($id);
                        $source = isset($a['sourceCode'])
                            ? (array_column($sources, null, 'code')[$a['sourceCode']] ?? throw new SalesException('SOURCE_INVALID', "Origem '{$a['sourceCode']}' não é uma origem ativa do website do pedido."))
                            : ($sources[0] ?? throw new SalesException('SOURCE_INVALID', 'O website do pedido não tem origem ativa.'));
                        $service->ship($id, $source['source_id'], (string) ($a['carrier'] ?? ''), (string) ($a['trackingCode'] ?? ''), $u);
                    }),
                ],
                'completeOrder' => [
                    'type' => Type::nonNull($orderType),
                    'description' => 'Enviado → Concluído (o cliente recebeu). Exige Iter_Sales::order_ship.',
                    'args' => $idArg,
                    'resolve' => $act('Iter_Sales::order_ship', static fn (int $id, array $a, int $u) => $service->complete($id, $u)),
                ],
                'cancelOrder' => [
                    'type' => Type::nonNull($orderType),
                    'description' => 'Antes do envio: devolve a reserva e avisa o cliente com o motivo. Exige Iter_Sales::order_cancel.',
                    'args' => $idArg + ['reason' => Type::nonNull(Type::string())],
                    'resolve' => $act('Iter_Sales::order_cancel', static fn (int $id, array $a, int $u) => $service->cancel($id, $a['reason'], $u)),
                ],
                'addOrderComment' => [
                    'type' => Type::nonNull($orderType),
                    'description' => 'Comentário no histórico, em qualquer situação.',
                    'args' => $idArg + ['comment' => Type::nonNull(Type::string()), 'notifyCustomer' => ['type' => Type::boolean(), 'defaultValue' => false, 'description' => 'Manda o comentário ao cliente por e-mail.']],
                    'resolve' => $act('Iter_Sales::order', static fn (int $id, array $a, int $u) => $service->addComment($id, $a['comment'], $a['notifyCustomer'], $u)),
                ],
            ],
        ];
    }
}
