<?php

declare(strict_types=1);

namespace Iter\Report\Reports;

use Iter\Report\Model\Format;
use Iter\Report\Model\Report;

/**
 * K4: store carts with items, not turned into an order, untouched for more than N hours. The value is at
 * today's price (price_promo in its dates, as Product::finalPrice), without coupons or shipping.
 */
final class AbandonedCarts extends Report
{
    public function common(): array
    {
        return ['website_id'];
    }

    public function filters(): array
    {
        return [
            'hours' => ['label' => 'Parado há mais de (horas)', 'type' => 'number', 'default' => 24],
            'who' => ['label' => 'Clientes', 'options' => ['' => 'Cadastrados e visitantes', 'registered' => 'Só cadastrados', 'guest' => 'Só visitantes', 'email' => 'Com e-mail (cadastrados e visitantes que informaram)']],
        ];
    }

    public function columns(array $f): array
    {
        return [
            'customer' => ['label' => 'Cliente', 'type' => 'text', 'link' => '/admin/customer/edit?id={customer_id}'],
            'email' => ['label' => 'E-mail', 'type' => 'text'],
            'kind' => ['label' => 'Tipo', 'type' => 'text'],
            'website' => ['label' => 'Loja', 'type' => 'text'],
            'products' => ['label' => 'Itens', 'type' => 'text'],
            'qty' => ['label' => 'Quantidade', 'type' => 'qty', 'sum' => true],
            'value' => ['label' => 'Valor', 'type' => 'money', 'sum' => true],
            'updated_at' => ['label' => 'Última alteração', 'type' => 'datetime'],
            'age' => ['label' => 'Parado há', 'type' => 'age'],
            'carts' => ['label' => '', 'type' => 'int', 'sum' => true, 'hidden' => true],
            'with_email' => ['label' => '', 'type' => 'int', 'sum' => true, 'hidden' => true],
        ];
    }

    public function sort(): array
    {
        return ['value', 'desc'];
    }

    public function lines(array $f): array
    {
        $where = match ($f['who']) {
            'registered' => ' AND q.customer_id IS NOT NULL',
            'guest' => ' AND q.customer_id IS NULL',
            'email' => " AND COALESCE(c.email, q.email, '') <> ''",
            default => '',
        };
        $bind = ['hours' => $f['hours']];
        if ($f['website_id']) {
            $where .= ' AND q.website_id = :website_id';
            $bind['website_id'] = $f['website_id'];
        }
        $promo = "pp.value > 0 AND pp.value < pr.value AND (pf.value IS NULL OR pf.value::date <= CURRENT_DATE) AND (pt.value IS NULL OR pt.value::date >= CURRENT_DATE)";

        return ["SELECT q.quote_id, q.customer_id,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', c.firstname, c.lastname)), ''), NULLIF(TRIM(CONCAT_WS(' ', q.firstname, q.lastname)), ''), 'Visitante') AS customer,
                COALESCE(c.email, q.email) AS email, CASE WHEN q.customer_id IS NULL THEN 'Visitante' ELSE 'Cadastrado' END AS kind,
                w.name AS website, i.products, i.qty, i.value, q.updated_at, EXTRACT(EPOCH FROM NOW() - q.updated_at) / 3600 AS age,
                1 AS carts, CASE WHEN COALESCE(c.email, q.email, '') <> '' THEN 1 ELSE 0 END AS with_email
            FROM sales_quote q
            LEFT JOIN customer_flat c ON c.customer_id = q.customer_id
            LEFT JOIN store_website w ON w.website_id = q.website_id
            JOIN LATERAL (SELECT COUNT(*) AS items, SUM(qi.quantity) AS qty,
                    SUM(qi.quantity * CASE WHEN {$promo} THEN pp.value ELSE pr.value END) AS value,
                    STRING_AGG(p.name || ' × ' || REPLACE(TRIM_SCALE(qi.quantity)::text, '.', ','), ' · ' ORDER BY qi.item_id) AS products
                FROM sales_quote_item qi
                JOIN catalog_product p ON p.product_id = qi.product_id
                " . self::attribute('pr', 'price') . '
                ' . self::attribute('pp', 'price_promo') . '
                ' . self::attribute('pf', 'price_promo_from', 'datetime') . '
                ' . self::attribute('pt', 'price_promo_to', 'datetime') . "
                WHERE qi.quote_id = q.quote_id) i ON i.items > 0
            WHERE q.is_active = 1 AND q.admin_user_id IS NULL AND q.updated_at < NOW() - make_interval(hours => :hours){$where}", $bind];
    }

    public function tiles(array $t, array $f, callable $totalsOf): array
    {
        return [
            ['label' => 'Carrinhos parados', 'value' => Format::show('int', $t['carts']), 'note' => 'há mais de ' . $f['hours'] . ' h', 'tone' => 'warn'],
            ['label' => 'Valor nos carrinhos', 'value' => Format::show('money', $t['value']), 'note' => 'pelo preço de hoje'],
            ['label' => 'Com e-mail', 'value' => Format::show('int', $t['with_email']), 'note' => 'dá para entrar em contato', 'tone' => 'ok'],
        ];
    }

    public function note(array $t, array $f): string
    {
        return 'A situação de agora, sem período: carrinhos da loja com itens, ainda sem pedido, sem alteração há mais de ' . $f['hours'] . ' h. O valor é pelo preço de hoje (com o preço promocional na validade), sem cupom e sem frete. O visitante só tem e-mail se chegou a informá-lo no checkout; os carrinhos de visitante são apagados depois dos dias de Configurações → Vendas. Dados pessoais: cada exportação fica no log de operações.';
    }
}
