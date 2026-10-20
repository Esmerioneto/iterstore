<?php

declare(strict_types=1);

namespace Iter\Report\Reports;

use Iter\Report\Model\Format;
use Iter\Report\Model\Report;

/**
 * K5: who bought without an account (paid guest orders in the period), by the order's e-mail, and whether
 * they have an account now (the same e-mail or CPF/CNPJ): the ones to invite to sign up.
 */
final class GuestBuyers extends Report
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
            'who' => ['label' => 'Cadastro', 'options' => ['' => 'Ainda sem cadastro', 'all' => 'Todos']],
            'q' => ['label' => 'Busca', 'type' => 'search', 'placeholder' => 'Nome, e-mail ou CPF/CNPJ'],
        ];
    }

    public function columns(array $f): array
    {
        return [
            'customer' => ['label' => 'Cliente', 'type' => 'text', 'link' => '/admin/customer/edit?id={customer_id}'],
            'email' => ['label' => 'E-mail', 'type' => 'text'],
            'taxvat' => ['label' => 'CPF/CNPJ', 'type' => 'text'],
            'telephone' => ['label' => 'Telefone', 'type' => 'text'],
            'orders' => ['label' => 'Pedidos', 'type' => 'int', 'sum' => true],
            'total' => ['label' => 'Total gasto', 'type' => 'money', 'sum' => true],
            'last_order' => ['label' => 'Última compra', 'type' => 'date'],
            'account' => ['label' => 'Cadastro', 'type' => 'text'],
            'lines' => ['label' => '', 'type' => 'int', 'sum' => true, 'hidden' => true],
            'signed_up' => ['label' => '', 'type' => 'int', 'sum' => true, 'hidden' => true],
        ];
    }

    public function sort(): array
    {
        return ['total', 'desc'];
    }

    public function lines(array $f): array
    {
        [$where, $bind] = self::orderWhere($f);
        if ($f['q'] !== '') {
            $where .= " AND CONCAT_WS(' ', o.firstname, o.lastname, o.email, o.taxvat) ILIKE :q";
            $bind['q'] = '%' . addcslashes($f['q'], '%_\\') . '%';
        }

        return ["SELECT x.*, acc.customer_id, CASE WHEN acc.customer_id IS NULL THEN 'Não' ELSE 'Já tem' END AS account,
                1 AS lines, CASE WHEN acc.customer_id IS NULL THEN 0 ELSE 1 END AS signed_up
            FROM (SELECT LOWER(o.email) AS key,
                    (ARRAY_AGG(TRIM(o.firstname || ' ' || o.lastname) ORDER BY o.created_at DESC))[1] AS customer,
                    (ARRAY_AGG(o.email ORDER BY o.created_at DESC))[1] AS email,
                    (ARRAY_AGG(o.taxvat ORDER BY o.created_at DESC) FILTER (WHERE o.taxvat <> ''))[1] AS taxvat,
                    (ARRAY_AGG(ad.telephone ORDER BY o.created_at DESC) FILTER (WHERE ad.telephone <> ''))[1] AS telephone,
                    COUNT(*) AS orders, SUM(o.grand_total) AS total, MAX(o.created_at) AS last_order
                FROM sales_order o
                LEFT JOIN sales_order_address ad ON ad.order_id = o.order_id AND ad.type = 'shipping'
                WHERE o.customer_id IS NULL AND " . self::paid() . " AND {$where}
                GROUP BY LOWER(o.email)) x
            LEFT JOIN LATERAL (SELECT c.customer_id FROM customer_flat c
                WHERE LOWER(c.email) = x.key OR (x.taxvat IS NOT NULL AND c.taxvat = x.taxvat) ORDER BY c.customer_id LIMIT 1) acc ON TRUE"
            . ($f['who'] === '' ? ' WHERE acc.customer_id IS NULL' : ''), $bind];
    }

    public function tiles(array $t, array $f, callable $totalsOf): array
    {
        return [
            ['label' => 'Compraram sem cadastro', 'value' => Format::show('int', $t['lines']), 'note' => Format::show('int', $t['orders']) . ' pedido(s) pago(s) no período'],
            ['label' => 'Total gasto', 'value' => Format::show('money', $t['total']), 'note' => (int) $t['lines'] ? Format::show('money', (float) $t['total'] / (int) $t['lines']) . ' por cliente' : ''],
        ] + ($f['who'] === 'all' ? [2 => ['label' => 'Já se cadastraram', 'value' => Format::show('int', $t['signed_up']), 'note' => 'depois de comprar como visitante', 'tone' => 'ok']] : []);
    }

    public function note(array $t, array $f): string
    {
        return 'Pedidos pagos sem cadastro no período, juntados pelo e-mail do pedido. "Já tem" cadastro quando há uma conta com o mesmo e-mail ou o mesmo CPF/CNPJ (o nome leva a ela). Dados pessoais: cada exportação fica no log de operações.';
    }
}
