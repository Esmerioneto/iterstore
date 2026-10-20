<?php

declare(strict_types=1);

namespace Iter\Report\Reports;

use Iter\Report\Model\Format;
use Iter\Report\Model\Report;

/** K3: registered customers whose last paid order is more than N days old (and, optionally, those who never bought), for marketing */
final class LapsedCustomers extends Report
{
    public function common(): array
    {
        return ['website_id'];
    }

    public function filters(): array
    {
        return [
            'days' => ['label' => 'Sem comprar há mais de (dias)', 'type' => 'number', 'default' => 90],
            'who' => ['label' => 'Clientes', 'options' => ['' => 'Já compraram', 'all' => 'Também quem nunca comprou']],
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
            'website' => ['label' => 'Loja', 'type' => 'text'],
            'orders' => ['label' => 'Pedidos', 'type' => 'int', 'sum' => true],
            'total' => ['label' => 'Total gasto', 'type' => 'money', 'sum' => true],
            'last_order' => ['label' => 'Última compra', 'type' => 'date'],
            'age' => ['label' => 'Sem comprar há', 'type' => 'age'],
            'lines' => ['label' => '', 'type' => 'int', 'sum' => true, 'hidden' => true],
            'never' => ['label' => '', 'type' => 'int', 'sum' => true, 'hidden' => true],
        ];
    }

    public function sort(): array
    {
        return ['total', 'desc'];
    }

    public function lines(array $f): array
    {
        $where = '';
        $bind = ['days' => $f['days']];
        if ($f['website_id']) {
            $where .= ' AND c.website_id = :website_id';
            $bind['website_id'] = $f['website_id'];
        }
        if ($f['q'] !== '') {
            $where .= " AND CONCAT_WS(' ', c.firstname, c.lastname, c.email, c.taxvat) ILIKE :q";
            $bind['q'] = '%' . addcslashes($f['q'], '%_\\') . '%';
        }
        $never = $f['who'] === 'all' ? ' OR (b.last_order IS NULL AND c.created_at < NOW() - make_interval(days => :days))' : '';

        return ["SELECT c.customer_id, TRIM(CONCAT_WS(' ', c.firstname, c.lastname)) AS customer, c.email, ph.telephone,
                CASE c.customer_type WHEN 'pj' THEN 'PJ' ELSE 'PF' END AS kind, w.name AS website,
                b.orders, b.total, b.last_order, EXTRACT(EPOCH FROM NOW() - COALESCE(b.last_order, c.created_at)) / 3600 AS age,
                1 AS lines, CASE WHEN b.last_order IS NULL THEN 1 ELSE 0 END AS never
            FROM customer_flat c
            LEFT JOIN store_website w ON w.website_id = c.website_id
            LEFT JOIN LATERAL (SELECT COUNT(*) AS orders, COALESCE(SUM(o.grand_total), 0) AS total, MAX(o.created_at) AS last_order
                FROM sales_order o WHERE o.customer_id = c.customer_id AND " . self::paid() . ") b ON TRUE
            LEFT JOIN LATERAL (SELECT a.telephone FROM customer_address_flat a WHERE a.customer_id = c.customer_id AND a.telephone <> ''
                ORDER BY a.is_default_shipping DESC NULLS LAST LIMIT 1) ph ON TRUE
            WHERE c.is_active = 1{$where} AND (b.last_order < NOW() - make_interval(days => :days){$never})", $bind];
    }

    public function tiles(array $t, array $f, callable $totalsOf): array
    {
        $bought = (int) $t['lines'] - (int) $t['never'];

        return [
            ['label' => 'Clientes sem comprar', 'value' => Format::show('int', $bought), 'note' => 'há mais de ' . $f['days'] . ' dias', 'tone' => 'warn'],
            ['label' => 'Já gastaram', 'value' => Format::show('money', $t['total']), 'note' => $bought ? Format::show('money', (float) $t['total'] / $bought) . ' por cliente' : ''],
        ] + ($f['who'] === 'all' ? [2 => ['label' => 'Nunca compraram', 'value' => Format::show('int', $t['never']), 'note' => 'cadastrados há mais de ' . $f['days'] . ' dias']] : []);
    }

    public function note(array $t, array $f): string
    {
        return 'A situação de agora, sem período: clientes cadastrados e ativos cuja última compra paga (pagamento confirmado, enviado ou concluído) foi há mais de ' . $f['days'] . ' dias. Quem nunca comprou conta desde o cadastro. O telefone é o do endereço de entrega padrão. Dados pessoais: cada exportação fica no log de operações.';
    }
}
