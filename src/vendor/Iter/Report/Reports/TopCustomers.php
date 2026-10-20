<?php

declare(strict_types=1);

namespace Iter\Report\Reports;

use Iter\Report\Model\Format;
use Iter\Report\Model\Report;

/**
 * K2: who bought the most in the period (paid orders): a registered customer by the account, a guest
 * by the e-mail. First and last purchase count every paid order, of any day.
 */
final class TopCustomers extends Report
{
    public function common(): array
    {
        return ['period', 'website_id'];
    }

    public function preset(): string
    {
        return 'year';
    }

    public function filters(): array
    {
        return [
            'who' => ['label' => 'Clientes', 'options' => ['' => 'Cadastrados e visitantes', 'registered' => 'Só cadastrados', 'guest' => 'Só visitantes']],
            'q' => ['label' => 'Busca', 'type' => 'search', 'placeholder' => 'Nome, e-mail ou CPF/CNPJ'],
        ];
    }

    public function columns(array $f): array
    {
        return [
            'customer' => ['label' => 'Cliente', 'type' => 'text', 'link' => '/admin/customer/edit?id={customer_id}'],
            'email' => ['label' => 'E-mail', 'type' => 'text'],
            'telephone' => ['label' => 'Telefone', 'type' => 'text'],
            'kind' => ['label' => 'Tipo', 'type' => 'text'],
            'orders' => ['label' => 'Pedidos', 'type' => 'int', 'sum' => true],
            'total' => ['label' => 'Total gasto', 'type' => 'money', 'sum' => true],
            'ticket' => ['label' => 'Ticket médio', 'type' => 'money'],
            'share' => ['label' => '% do total', 'type' => 'pct', 'sum' => true],
            'first_order' => ['label' => 'Primeira compra', 'type' => 'date'],
            'last_order' => ['label' => 'Última compra', 'type' => 'date'],
            'lines' => ['label' => '', 'type' => 'int', 'sum' => true, 'hidden' => true],
            'guests' => ['label' => '', 'type' => 'int', 'sum' => true, 'hidden' => true],
            'repeat' => ['label' => '', 'type' => 'int', 'sum' => true, 'hidden' => true],
        ];
    }

    public function sort(): array
    {
        return ['total', 'desc'];
    }

    public function lines(array $f): array
    {
        [$where, $bind] = self::orderWhere($f);
        $key = "COALESCE('c' || o.customer_id, 'g' || LOWER(o.email))";
        $where .= match ($f['who']) {
            'registered' => ' AND o.customer_id IS NOT NULL',
            'guest' => ' AND o.customer_id IS NULL',
            default => '',
        };
        if ($f['q'] !== '') {
            $where .= " AND CONCAT_WS(' ', o.firstname, o.lastname, o.email, o.taxvat) ILIKE :q";
            $bind['q'] = '%' . addcslashes($f['q'], '%_\\') . '%';
        }
        $mine = self::paid('a') . " AND COALESCE('c' || a.customer_id, 'g' || LOWER(a.email)) = x.key"; // ponytail: no index on the key, fine for thousands of orders

        return ["SELECT x.*, h.first_order, h.last_order, h.telephone, x.total / x.orders AS ticket, x.total / NULLIF(SUM(x.total) OVER (), 0) AS share, 1 AS lines,
                CASE WHEN x.customer_id IS NULL THEN 1 ELSE 0 END AS guests,
                CASE WHEN x.orders > 1 THEN 1 ELSE 0 END AS repeat
            FROM (SELECT {$key} AS key, MAX(o.customer_id) AS customer_id,
                    (ARRAY_AGG(TRIM(o.firstname || ' ' || o.lastname) ORDER BY o.created_at DESC))[1] AS customer,
                    (ARRAY_AGG(o.email ORDER BY o.created_at DESC))[1] AS email,
                    CASE WHEN MAX(o.customer_id) IS NULL THEN 'Visitante' ELSE 'Cadastrado' END AS kind,
                    COUNT(*) AS orders, SUM(o.grand_total) AS total
                FROM sales_order o
                WHERE " . self::paid() . " AND {$where}
                GROUP BY {$key}) x
            LEFT JOIN LATERAL (SELECT MIN(a.created_at) AS first_order, MAX(a.created_at) AS last_order,
                    (ARRAY_AGG(ad.telephone ORDER BY a.created_at DESC) FILTER (WHERE ad.telephone <> ''))[1] AS telephone
                FROM sales_order a LEFT JOIN sales_order_address ad ON ad.order_id = a.order_id AND ad.type = 'shipping'
                WHERE {$mine}) h ON TRUE", $bind];
    }

    public function totals(array $t, array $f): array
    {
        $t['ticket'] = (int) $t['orders'] > 0 ? (float) $t['total'] / (int) $t['orders'] : null;

        return $t;
    }

    public function tiles(array $t, array $f, callable $totalsOf): array
    {
        $lines = (int) $t['lines'];

        return [
            ['label' => 'Clientes que compraram', 'value' => Format::show('int', $lines), 'note' => Format::show('int', $t['guests']) . ' visitante(s), sem cadastro'],
            ['label' => 'Compraram mais de uma vez', 'value' => Format::show('int', $t['repeat']), 'note' => $lines ? Format::show('pct', (int) $t['repeat'] / $lines) . ' dos clientes' : '', 'tone' => 'ok'],
            ['label' => 'Ticket médio', 'value' => Format::show('money', $t['ticket']), 'note' => 'por pedido, com frete'],
            ['label' => 'Gasto por cliente', 'value' => Format::show('money', $lines ? (float) $t['total'] / $lines : null), 'note' => 'no período'],
        ];
    }

    public function note(array $t, array $f): string
    {
        return 'Pedidos pagos no período. O cliente cadastrado é a conta; o visitante, o e-mail do pedido. Primeira e última compra contam todos os pedidos pagos, de qualquer data. Dados pessoais: cada exportação fica no log de operações.';
    }
}
