<?php

declare(strict_types=1);

namespace Iter\Report\Reports;

use Iter\Core\Db\Connection;
use Iter\Report\Model\Format;
use Iter\Report\Model\Report;

/** V5: paid orders per sales channel and, for the orders an admin placed (Novo pedido), per admin user */
final class SalesByChannel extends Report
{
    public function filters(): array
    {
        $users = Connection::get()->adapter()->fetchPairs("SELECT u.user_id, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.firstname, u.lastname)), ''), u.username) AS name
            FROM admin_user u WHERE EXISTS (SELECT 1 FROM sales_order o WHERE o.admin_user_id = u.user_id) ORDER BY 2");

        return [
            'by' => ['label' => 'Agrupar', 'options' => ['user' => 'Por canal e atendente', 'channel' => 'Só por canal']],
            'admin_user_id' => ['label' => 'Atendente', 'options' => ['' => 'Todos os atendentes'] + array_map('strval', $users)],
        ];
    }

    public function columns(array $f): array
    {
        return array_filter([
            'channel' => ['label' => 'Canal', 'type' => 'text'],
            'attendant' => $f['by'] === 'user' ? ['label' => 'Atendente', 'type' => 'text'] : null,
            'orders' => ['label' => 'Pedidos', 'type' => 'int', 'sum' => true],
            'total' => ['label' => 'Valor', 'type' => 'money', 'sum' => true],
            'share' => ['label' => '% do valor', 'type' => 'pct', 'sum' => true],
            'ticket' => ['label' => 'Ticket médio', 'type' => 'money'],
            'discount' => ['label' => 'Descontos', 'type' => 'money', 'sum' => true],
            'by_admin' => ['label' => '', 'type' => 'int', 'sum' => true, 'hidden' => true],
        ]);
    }

    public function sort(): array
    {
        return ['total', 'desc'];
    }

    public function lines(array $f): array
    {
        [$where, $bind] = self::orderWhere($f);
        if ($f['admin_user_id'] !== '') {
            $where .= ' AND o.admin_user_id = :admin_user_id';
            $bind['admin_user_id'] = (int) $f['admin_user_id'];
        }
        $user = $f['by'] === 'user' ? ', o.admin_user_id' : '';

        return ["SELECT COALESCE(o.sales_channel, 'Sem canal') AS channel,
                " . ($user ? "COALESCE(MAX(COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.firstname, u.lastname)), ''), u.username)), CASE WHEN o.admin_user_id IS NULL THEN '—' ELSE 'Usuário excluído' END)" : 'NULL') . " AS attendant,
                COUNT(*) AS orders, SUM(o.grand_total) AS total, SUM(o.grand_total) / NULLIF(SUM(SUM(o.grand_total)) OVER (), 0) AS share,
                SUM(o.grand_total) / COUNT(*) AS ticket, SUM(o.discount_amount) AS discount,
                COUNT(*) FILTER (WHERE o.admin_user_id IS NOT NULL) AS by_admin
            FROM sales_order o
            LEFT JOIN admin_user u ON u.user_id = o.admin_user_id
            WHERE " . self::paid() . " AND {$where}
            GROUP BY COALESCE(o.sales_channel, 'Sem canal'){$user}", $bind];
    }

    public function totals(array $t, array $f): array
    {
        $t['ticket'] = (int) $t['orders'] > 0 ? (float) $t['total'] / (int) $t['orders'] : null;

        return $t;
    }

    public function tiles(array $t, array $f, callable $totalsOf): array
    {
        $orders = (int) $t['orders'];

        return [
            ['label' => 'Pedidos pagos', 'value' => Format::show('int', $orders), 'note' => Format::show('money', $t['total'])],
            ['label' => 'Feitos no painel', 'value' => Format::show('int', $t['by_admin']), 'note' => $orders ? Format::show('pct', (int) $t['by_admin'] / $orders) . ' dos pedidos (Novo pedido)' : ''],
            ['label' => 'Ticket médio', 'value' => Format::show('money', $t['ticket']), 'note' => 'com frete'],
        ];
    }

    public function note(array $t, array $f): string
    {
        return 'Pedidos pagos, pelo canal gravado no pedido. O atendente é o usuário do painel que fez o pedido em Vendas → Novo pedido; os pedidos feitos pelo cliente aparecem com "—".';
    }
}
