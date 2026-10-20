<?php

declare(strict_types=1);

namespace Iter\Report\Reports;

use Iter\Report\Model\Format;
use Iter\Report\Model\Report;

/** O3: the shipments of the period per carrier and source (how many, how many without tracking), or one per line with the code */
final class Shipments extends Report
{
    public function common(): array
    {
        return ['period', 'website_id'];
    }

    public function filters(): array
    {
        return [
            'view' => ['label' => 'Mostrar', 'options' => ['summary' => 'Por transportadora e origem', 'list' => 'Lista dos envios']],
            'tracking' => ['label' => 'Rastreio', 'options' => ['' => 'Com e sem rastreio', 'missing' => 'Só sem rastreio']],
        ];
    }

    public function columns(array $f): array
    {
        $list = $f['view'] === 'list';

        return array_filter([
            'created_at' => $list ? ['label' => 'Data', 'type' => 'datetime'] : null,
            'number' => $list ? ['label' => 'Pedido', 'type' => 'text', 'link' => '/admin/order/view?id={order_id}'] : null,
            'carrier' => ['label' => 'Transportadora', 'type' => 'text'],
            'source' => ['label' => 'Origem', 'type' => 'text'],
            'tracking_code' => $list ? ['label' => 'Rastreio', 'type' => 'text'] : null,
            'username' => $list ? ['label' => 'Enviado por', 'type' => 'text'] : null,
            // the list's default sort (sort()): the newest shipment first
            'shipments' => ['label' => 'Envios', 'type' => 'int', 'sum' => true, 'hidden' => $list] + ($list ? ['sort' => 'created_at'] : []),
            'missing' => ['label' => 'Sem rastreio', 'type' => 'int', 'sum' => true, 'hidden' => $list],
            'ship_hours' => $list ? null : ['label' => 'Pagamento → envio (média)', 'type' => 'age'],
        ]);
    }

    public function sort(): array
    {
        return ['shipments', 'desc'];
    }

    public function lines(array $f): array
    {
        [$where, $bind] = self::orderWhere($f, 'o', 's.created_at');
        if ($f['tracking'] === 'missing') {
            $where .= " AND COALESCE(TRIM(s.tracking_code), '') = ''";
        }
        $from = "FROM sales_shipment s
            JOIN sales_order o ON o.order_id = s.order_id
            LEFT JOIN admin_user u ON u.user_id = s.admin_user_id
            WHERE {$where}";
        $carrier = "COALESCE(NULLIF(TRIM(s.carrier), ''), 'Não informada')";
        $missing = "CASE WHEN COALESCE(TRIM(s.tracking_code), '') = '' THEN 1 ELSE 0 END";
        if ($f['view'] === 'list') {
            return ["SELECT s.created_at, o.order_id, o.increment_id AS number, {$carrier} AS carrier, s.source_name AS source, NULLIF(TRIM(s.tracking_code), '') AS tracking_code,
                    COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.firstname, u.lastname)), ''), u.username) AS username, 1 AS shipments, {$missing} AS missing
                {$from}", $bind];
        }
        $paid = "(SELECT MIN(h.created_at) FROM sales_order_status_history h WHERE h.order_id = o.order_id AND h.status = 'processing')";

        return ["SELECT {$carrier} AS carrier, s.source_name AS source, COUNT(*) AS shipments, SUM({$missing}) AS missing,
                AVG(EXTRACT(EPOCH FROM s.created_at - {$paid}) / 3600) AS ship_hours
            {$from}
            GROUP BY {$carrier}, s.source_name", $bind];
    }

    public function tiles(array $t, array $f, callable $totalsOf): array
    {
        return [
            ['label' => 'Envios', 'value' => Format::show('int', $t['shipments']), 'note' => 'no período'],
            ['label' => 'Sem rastreio', 'value' => Format::show('int', $t['missing']), 'note' => 'envios sem código de rastreio'] + ((int) $t['missing'] ? ['tone' => 'warn'] : []),
        ];
    }

    public function note(array $t, array $f): string
    {
        return 'Pela data do envio. A origem é a de onde o estoque saiu. "Pagamento → envio" conta desde a primeira vez em "Pagamento confirmado". Na lista, o número abre o pedido.';
    }
}
