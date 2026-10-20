<?php

declare(strict_types=1);

namespace Iter\Report\Model;

use Iter\Catalog\Model\Category;

/**
 * One report (REPORT-PLAN): its columns and the SQL of its lines. ReportService runs it for the screen
 * (sort, page, totals) and Export for the files; a report only says what it shows.
 *
 * $f, the filters (ReportService::filters): from, to, to_next (AAAA-MM-DD), website_id (?int),
 * sales_channel, payment_method ('' = all), the report's own filters() and cost (bool: the admin may see
 * the cost; columns with 'cost' are dropped without it).
 */
abstract class Report
{
    /** what counts as sold, the same as the sales dashboard (DashboardService::GROUPS) */
    public const PAID = ['processing', 'shipped', 'complete'];

    /** the filters every sales report has; a report without a period returns fewer from common() */
    public const COMMON = ['period', 'website_id', 'sales_channel', 'payment_method'];

    /** @param array{code: string, group: string, title: string, description: string, resource: string} $info from reports.json */
    public function __construct(public readonly array $info)
    {
    }

    /**
     * key (the SQL alias) => label, type (Format), sum (summed in the totals), cost (hidden without the
     * permission), hidden (only for the totals), sort (another alias to sort by), link ("/admin/...{alias}")
     *
     * @return array<string, array{label: string, type: string, sum?: bool, cost?: bool, hidden?: bool, sort?: string, link?: string}>
     */
    abstract public function columns(array $f): array;

    /** @return array{0: string, 1: array<string, mixed>} the lines' SQL and its named values */
    abstract public function lines(array $f): array;

    /** @return array{0: string, 1: string} the default sort: column key and asc/desc */
    abstract public function sort(): array;

    /** @return string[] which of COMMON it uses */
    public function common(): array
    {
        return self::COMMON;
    }

    /** the period a first visit shows (a key of ReportService::PRESETS) */
    public function preset(): string
    {
        return 'month';
    }

    /**
     * Its own filters: key => label, options (value => label; the first is the default) or
     * type 'search' / 'number' / 'checkbox' with default.
     */
    public function filters(): array
    {
        return [];
    }

    /** @return ?array{0: string, 1: array} the totals' SQL when summing the lines would count something twice */
    public function totalsLines(array $f): ?array
    {
        return null;
    }

    /** the totals after the sums: ratios (margin %, ticket) are worked out here */
    public function totals(array $t, array $f): array
    {
        return $t;
    }

    /**
     * The indicators above the table.
     *
     * @param callable(array): array $totalsOf the totals of other filters (e.g. the period before)
     * @return array<int, array{label: string, value: string, note?: string, tone?: string}>
     */
    public function tiles(array $t, array $f, callable $totalsOf): array
    {
        return [];
    }

    /** @return ?array{title: string, bars: array<int, array{label: string, value: float, part: ?float}>} */
    public function chart(array $rows, array $f): ?array
    {
        return null;
    }

    public function note(array $t, array $f): string
    {
        return '';
    }

    /** "o.status IN (...)": the paid orders */
    protected static function paid(string $alias = 'o'): string
    {
        return $alias . ".status IN ('" . implode("', '", self::PAID) . "')";
    }

    /**
     * The common filters on an order: period on $date (default the creation), website, channel and payment.
     *
     * @return array{0: string, 1: array<string, mixed>} "cond AND cond" and its values
     */
    protected static function orderWhere(array $f, string $alias = 'o', ?string $date = null, bool $period = true): array
    {
        $date ??= $alias . '.created_at';
        $where = $period ? ["{$date} >= :from", "{$date} < :to_next"] : ['TRUE'];
        $bind = $period ? ['from' => $f['from'], 'to_next' => $f['to_next']] : [];
        foreach (['website_id', 'sales_channel', 'payment_method'] as $key) {
            if (($f[$key] ?? null) !== null && $f[$key] !== '') {
                $where[] = "{$alias}.{$key} = :{$key}";
                $bind[$key] = $f[$key];
            }
        }

        return [implode(' AND ', $where), $bind];
    }

    /**
     * Per order: items' quantity, what they sold for after the discounts (net), the same only where the
     * item has a cost (net_cost) and the cost (cost × quantity). For "LEFT JOIN LATERAL (...) i ON TRUE".
     */
    protected static function itemTotals(string $alias = 'o'): string
    {
        return "SELECT SUM(it.quantity) AS qty, SUM(it.row_total - it.discount_amount) AS net,
                    SUM(it.row_total - it.discount_amount) FILTER (WHERE it.cost IS NOT NULL) AS net_cost,
                    SUM(it.cost * it.quantity) AS cost
                FROM sales_order_item it WHERE it.order_id = {$alias}.order_id";
    }

    /** the category filter's options: all, then the tree with its levels */
    protected static function categories(): array
    {
        $categories = ['' => 'Todas as categorias'];
        foreach ((new Category())->getResource()->getTree() as $c) {
            $categories[(string) $c['category_id']] = str_repeat('— ', (int) $c['level']) . $c['name'];
        }

        return $categories;
    }

    /** "LEFT JOIN eav_value_<storage> <alias> ...": the product attribute $code of $product as <alias>.value */
    protected static function attribute(string $alias, string $code, string $storage = 'decimal', string $product = 'p'): string
    {
        return "LEFT JOIN eav_value_{$storage} {$alias} ON {$alias}.entity_id = {$product}.product_id AND {$alias}.attribute_id = (SELECT a.attribute_id FROM eav_attribute a
            JOIN eav_entity_type t ON t.entity_type_id = a.entity_type_id WHERE t.code = 'product' AND a.code = '{$code}')";
    }

    /**
     * The stock of product p (a simple product or a variant), for "LEFT JOIN LATERAL (...) st ON TRUE":
     * physical (the enabled sources of its websites), in_stock (the part marked "em estoque"), reserved
     * (held by orders not shipped yet), salable (as StockService::getSalable) and sources ("CD Rio: 5 · Loja: 2").
     * With the website filter, only that website's sources and reservations.
     */
    protected static function stock(array $f): string
    {
        $website = $f['website_id'] ? ' AND s.website_id = ' . (int) $f['website_id'] : '';
        $reservations = $f['website_id'] ? ' AND r.website_id = ' . (int) $f['website_id'] : '';

        return "SELECT q.physical, q.in_stock, q.sources, -res.held AS reserved, q.in_stock + res.held AS salable
            FROM (SELECT COALESCE(SUM(i.quantity), 0) AS physical, COALESCE(SUM(i.quantity) FILTER (WHERE i.status = 1), 0) AS in_stock,
                    STRING_AGG(s.name || ': ' || REPLACE(TRIM_SCALE(i.quantity)::text, '.', ','), ' · ' ORDER BY s.priority, s.source_id) FILTER (WHERE i.quantity > 0) AS sources
                FROM inventory_source_item i
                JOIN inventory_source s ON s.source_id = i.source_id AND s.is_enabled = 1
                JOIN catalog_product_website pw ON pw.product_id = i.product_id AND pw.website_id = s.website_id
                WHERE i.product_id = p.product_id{$website}) q,
            (SELECT COALESCE(SUM(r.quantity), 0) AS held FROM inventory_reservation r WHERE r.product_id = p.product_id{$reservations}) res";
    }

    /** "AND ..." for catalog_product p: in the website filter (when set), and in the category (for "pp", its parent, too) */
    protected static function productWhere(array $f, array &$bind): string
    {
        $where = $f['website_id'] ? ' AND EXISTS (SELECT 1 FROM catalog_product_website w WHERE w.product_id = p.product_id AND w.website_id = ' . (int) $f['website_id'] . ')' : '';
        if (($f['category_id'] ?? '') !== '') {
            $where .= ' AND EXISTS (SELECT 1 FROM catalog_category_product cp WHERE cp.product_id = COALESCE(p.parent_id, p.product_id) AND cp.category_id = :category_id)';
            $bind['category_id'] = (int) $f['category_id'];
        }
        if (($f['q'] ?? '') !== '') {
            $where .= ' AND CONCAT_WS(\' \', p.sku, p.name) ILIKE :q';
            $bind['q'] = '%' . addcslashes($f['q'], '%_\\') . '%';
        }

        return $where;
    }

    /** margin and margin % of summed net_cost / cost, in the totals */
    protected static function margin(array $t): array
    {
        $t['margin'] = $t['net_cost'] !== null && $t['cost'] !== null ? (float) $t['net_cost'] - (float) $t['cost'] : null;
        $t['margin_pct'] = $t['margin'] !== null && (float) $t['net_cost'] > 0 ? $t['margin'] / (float) $t['net_cost'] : null;

        return $t;
    }

    /** "R$ x (y%)" of how much of the revenue had no cost, for the notes */
    protected static function withoutCost(array $t, string $revenue): string
    {
        $missing = (float) ($t[$revenue] ?? 0) - (float) ($t['net_cost'] ?? 0);

        return $missing > 0.004 && (float) $t[$revenue] > 0
            ? ' ' . Format::show('money', $missing) . ' (' . Format::show('pct', $missing / (float) $t[$revenue]) . ' do faturamento) foram de itens sem custo no pedido e ficaram fora da margem.'
            : '';
    }
}
