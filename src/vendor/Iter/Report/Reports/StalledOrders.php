<?php

declare(strict_types=1);

namespace Iter\Report\Reports;

use Iter\Report\Model\Format;
use Iter\Report\Model\Report;
use Iter\Sales\Model\OrderService;

/** O1: the service's work list, now: waiting for payment for more than N hours, and paid but not shipped for more than N days */
final class StalledOrders extends Report
{
    public function common(): array
    {
        return ['website_id'];
    }

    public function filters(): array
    {
        return [
            'pending_hours' => ['label' => 'Aguardando pagamento há mais de (horas)', 'type' => 'number', 'default' => 24],
            'ship_days' => ['label' => 'Pago e não enviado há mais de (dias)', 'type' => 'number', 'default' => 2],
        ];
    }

    public function columns(array $f): array
    {
        return [
            'number' => ['label' => 'Pedido', 'type' => 'text', 'link' => '/admin/order/view?id={order_id}'],
            'created_at' => ['label' => 'Data do pedido', 'type' => 'datetime'],
            'customer' => ['label' => 'Cliente', 'type' => 'text'],
            'website' => ['label' => 'Loja', 'type' => 'text'],
            'payment' => ['label' => 'Pagamento', 'type' => 'text'],
            'total' => ['label' => 'Total', 'type' => 'money', 'sum' => true],
            'situation' => ['label' => 'Situação', 'type' => 'text'],
            'age' => ['label' => 'Parado há', 'type' => 'age'],
            'orders' => ['label' => '', 'type' => 'int', 'sum' => true, 'hidden' => true],
            'pending' => ['label' => '', 'type' => 'int', 'sum' => true, 'hidden' => true],
            'pending_total' => ['label' => '', 'type' => 'money', 'sum' => true, 'hidden' => true],
        ];
    }

    public function sort(): array
    {
        return ['age', 'desc'];
    }

    public function lines(array $f): array
    {
        [$where, $bind] = self::orderWhere($f, 'o', null, false);
        $paidAt = "(SELECT MIN(h.created_at) FROM sales_order_status_history h WHERE h.order_id = o.order_id AND h.status = 'processing')";

        return ["SELECT o.order_id, o.increment_id AS number, o.created_at, TRIM(o.firstname || ' ' || o.lastname) || ' · ' || o.email AS customer,
                w.name AS website, o.payment_label AS payment, o.grand_total AS total,
                CASE o.status WHEN 'pending_payment' THEN :waiting ELSE :paid_label END AS situation,
                EXTRACT(EPOCH FROM NOW() - CASE o.status WHEN 'pending_payment' THEN o.created_at ELSE COALESCE({$paidAt}, o.created_at) END) / 3600 AS age,
                1 AS orders, CASE o.status WHEN 'pending_payment' THEN 1 ELSE 0 END AS pending,
                CASE o.status WHEN 'pending_payment' THEN o.grand_total ELSE 0 END AS pending_total
            FROM sales_order o
            LEFT JOIN store_website w ON w.website_id = o.website_id
            WHERE {$where} AND (
                (o.status = 'pending_payment' AND o.created_at < NOW() - make_interval(hours => :hours))
                OR (o.status = 'processing' AND COALESCE({$paidAt}, o.created_at) < NOW() - make_interval(days => :days)))",
            $bind + ['hours' => $f['pending_hours'], 'days' => $f['ship_days'], 'waiting' => OrderService::STATUS_LABELS['pending_payment'], 'paid_label' => 'Pago, não enviado']];
    }

    public function tiles(array $t, array $f, callable $totalsOf): array
    {
        $shipping = (int) $t['orders'] - (int) $t['pending'];

        return [
            ['label' => 'Aguardando pagamento', 'value' => Format::show('int', $t['pending']), 'note' => Format::show('money', $t['pending_total']) . ' · há mais de ' . $f['pending_hours'] . ' h', 'tone' => 'warn'],
            ['label' => 'Pagos sem envio', 'value' => Format::show('int', $shipping), 'note' => Format::show('money', (float) $t['total'] - (float) $t['pending_total']) . ' · há mais de ' . $f['ship_days'] . ' dia(s)', 'tone' => 'bad'],
        ];
    }

    public function note(array $t, array $f): string
    {
        return 'A situação de agora, sem período. "Parado há" conta desde o pedido (aguardando) ou desde o pagamento confirmado (pago sem envio). O número abre o pedido.';
    }
}
