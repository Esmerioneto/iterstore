<?php

declare(strict_types=1);

namespace Iter\Report\Reports;

use Iter\Report\Model\Format;
use Iter\Report\Model\Report;

/**
 * E4: every stock movement of the period, one per line: what orders reserved, what cancellations gave
 * back and what shipments took (inventory_reservation), and the manual changes (the operation log's
 * "Estoque — <origem>", before → after, with the user).
 */
final class StockMoves extends Report
{
    public const KINDS = ['order' => 'Reserva (pedido)', 'canceled' => 'Devolução (cancelamento)', 'shipped' => 'Baixa (envio)', 'adjust' => 'Ajuste manual'];

    public function common(): array
    {
        return ['period', 'website_id'];
    }

    public function filters(): array
    {
        return [
            'q' => ['label' => 'Busca', 'type' => 'search', 'placeholder' => 'SKU ou nome'],
            'kind' => ['label' => 'Movimento', 'options' => ['' => 'Todos os movimentos'] + self::KINDS],
        ];
    }

    public function columns(array $f): array
    {
        return [
            'created_at' => ['label' => 'Data', 'type' => 'datetime'],
            'sku' => ['label' => 'SKU', 'type' => 'text', 'link' => '/admin/product/edit?id={edit_id}'],
            'name' => ['label' => 'Produto', 'type' => 'text'],
            'kind_label' => ['label' => 'Movimento', 'type' => 'text'],
            'source' => ['label' => 'Origem', 'type' => 'text'],
            'quantity' => ['label' => 'Quantidade', 'type' => 'qty'],
            'reference' => ['label' => 'Pedido', 'type' => 'text', 'link' => '/admin/order/view?id={order_id}'],
            'username' => ['label' => 'Usuário', 'type' => 'text'],
            'reserved' => ['label' => '', 'type' => 'qty', 'sum' => true, 'hidden' => true],
            'returned' => ['label' => '', 'type' => 'qty', 'sum' => true, 'hidden' => true],
            'shipped' => ['label' => '', 'type' => 'qty', 'sum' => true, 'hidden' => true],
            'adjusted_in' => ['label' => '', 'type' => 'qty', 'sum' => true, 'hidden' => true],
            'adjusted_out' => ['label' => '', 'type' => 'qty', 'sum' => true, 'hidden' => true],
            'lines' => ['label' => '', 'type' => 'int', 'sum' => true, 'hidden' => true],
        ];
    }

    public function sort(): array
    {
        return ['created_at', 'desc'];
    }

    public function lines(array $f): array
    {
        $bind = ['from' => $f['from'], 'to_next' => $f['to_next']];
        $where = self::productWhere(['website_id' => null] + $f, $bind); // the website is the movement's, below
        $website = (int) $f['website_id'];
        // "7" or "7 (fora de estoque)" in the log
        $number = static fn (string $v) => "COALESCE(SUBSTRING({$v} FROM '^[0-9]+(?:\\.[0-9]+)?')::numeric, 0)";
        $kind = $f['kind'] !== '' ? ' WHERE m.kind = :kind' : '';
        if ($kind) {
            $bind['kind'] = $f['kind'];
        }
        $label = 'CASE m.kind' . implode('', array_map(static fn ($k, $l) => " WHEN '{$k}' THEN '{$l}'", array_keys(self::KINDS), self::KINDS)) . ' ELSE m.kind END';

        return ["SELECT m.*, {$label} AS kind_label, 1 AS lines,
                CASE m.kind WHEN 'order' THEN -m.quantity ELSE 0 END AS reserved,
                CASE m.kind WHEN 'canceled' THEN m.quantity ELSE 0 END AS returned,
                CASE m.kind WHEN 'shipped' THEN -m.quantity ELSE 0 END AS shipped,
                CASE WHEN m.kind = 'adjust' AND m.quantity > 0 THEN m.quantity ELSE 0 END AS adjusted_in,
                CASE WHEN m.kind = 'adjust' AND m.quantity < 0 THEN -m.quantity ELSE 0 END AS adjusted_out
            FROM (
                SELECT r.created_at, p.sku, p.name, COALESCE(p.parent_id, p.product_id) AS edit_id, r.reason AS kind, NULL AS source,
                    CASE r.reason WHEN 'shipped' THEN -r.quantity ELSE r.quantity END AS quantity,
                    r.reference, o.order_id, NULL AS username
                FROM inventory_reservation r
                JOIN catalog_product p ON p.product_id = r.product_id
                LEFT JOIN sales_order o ON o.increment_id = r.reference
                WHERE r.created_at >= :from AND r.created_at < :to_next" . ($website ? " AND r.website_id = {$website}" : '') . "{$where}
                UNION ALL
                SELECT l.created_at, p.sku, p.name, COALESCE(p.parent_id, p.product_id), 'adjust', SUBSTRING(e.key FROM 11),
                    {$number('e.value->>1')} - {$number('e.value->>0')}, NULL, NULL, l.admin_name
                FROM log_operation l
                CROSS JOIN LATERAL jsonb_each(l.changes) e
                JOIN catalog_product p ON p.product_id = l.entity_id
                WHERE l.entity_type = 'product' AND l.action = 'update' AND e.key LIKE 'Estoque — %'
                    AND l.created_at >= :from AND l.created_at < :to_next{$where}"
                    . ($website ? " AND EXISTS (SELECT 1 FROM inventory_source s WHERE s.name = SUBSTRING(e.key FROM 11) AND s.website_id = {$website})" : '') . "
            ) m{$kind}", $bind];
    }

    public function tiles(array $t, array $f, callable $totalsOf): array
    {
        return [
            ['label' => 'Reservado', 'value' => Format::show('qty', $t['reserved']), 'note' => 'unidades em pedidos'],
            ['label' => 'Devolvido', 'value' => Format::show('qty', $t['returned']), 'note' => 'de pedidos cancelados'],
            ['label' => 'Baixado', 'value' => Format::show('qty', $t['shipped']), 'note' => 'saiu da origem com o envio'],
            ['label' => 'Ajustes manuais', 'value' => '+' . Format::show('qty', $t['adjusted_in']) . ' / −' . Format::show('qty', $t['adjusted_out']), 'note' => 'lançados no produto'],
        ];
    }

    public function note(array $t, array $f): string
    {
        return 'Reserva: o pedido segura as unidades (sai do vendável). Devolução: o cancelamento as devolve. Baixa: o envio tira as unidades do físico da origem e solta a reserva do pedido, sem mudar o vendável. Ajuste manual: a diferença no físico lançada no produto (tela, grade ou API), com o usuário; só aparece com o log de operações ligado.';
    }
}
