<?php

declare(strict_types=1);

namespace Iter\Promotion\Model;

use Iter\Core\Db\Adapter;
use Iter\Core\Db\Connection;
use Iter\Core\Db\Expr;
use Iter\Sales\Model\SalesException;

/** Server-side campaign engine. All money is rounded to cents and capped at eligible item values. */
final class PromotionService
{
    public function applyCoupon(int $quoteId, string $rawCode): void
    {
        $code = self::normalizeCode($rawCode);
        $db = Connection::get()->adapter();
        $row = $db->fetchRow("SELECT c.coupon_id, c.is_active AS coupon_active, c.usage_limit, c.usage_per_customer, p.promotion_id, p.is_active AS promotion_active, p.application_type, p.starts_at, p.ends_at
            FROM promotion_coupon c JOIN promotion p ON p.promotion_id = c.promotion_id WHERE c.code = :code", ['code' => $code]);
        if (!$row) throw new SalesException('COUPON_NOT_FOUND', 'Cupom não encontrado.');
        if (!(int) $row['coupon_active'] || !(int) $row['promotion_active']) throw new SalesException('COUPON_INACTIVE', 'Este cupom está inativo.');
        if ($row['application_type'] !== 'coupon') throw new SalesException('COUPON_NOT_APPLICABLE', 'Este código não é um cupom válido.');
        if (($row['starts_at'] !== null && strtotime($row['starts_at']) > time()) || ($row['ends_at'] !== null && strtotime($row['ends_at']) < time())) throw new SalesException('COUPON_EXPIRED', 'Este cupom está fora do período de validade.');
        $quote = $db->fetchRow('SELECT website_id, customer_id FROM sales_quote WHERE quote_id = :id AND is_active = 1', ['id' => $quoteId]);
        if (!$quote) throw new SalesException('CART_NOT_FOUND', 'Carrinho não encontrado ou já fechado.');
        if (!$this->onWebsite($db, (int) $row['promotion_id'], (int) $quote['website_id'])) throw new SalesException('COUPON_NOT_APPLICABLE', 'Este cupom não é válido para esta loja.');
        if ($row['usage_per_customer'] !== null && $quote['customer_id'] === null) throw new SalesException('COUPON_LOGIN_REQUIRED', 'Entre na sua conta para usar este cupom.');
        if ($row['usage_limit'] !== null && $this->uses($db, (int) $row['coupon_id']) >= (int) $row['usage_limit']) throw new SalesException('COUPON_USED_UP', 'Este cupom atingiu o limite de usos.');
        if ($row['usage_per_customer'] !== null && $this->uses($db, (int) $row['coupon_id'], (int) $quote['customer_id']) >= (int) $row['usage_per_customer']) throw new SalesException('COUPON_USED_UP', 'Você já atingiu o limite de uso deste cupom.');
        if (!$db->update('sales_quote', ['coupon_code' => $code], ['quote_id = ?' => $quoteId, 'is_active = ?' => 1])) throw new SalesException('CART_NOT_FOUND', 'Carrinho não encontrado ou já fechado.');
    }

    public function removeCoupon(int $quoteId): void
    {
        Connection::get()->adapter()->update('sales_quote', ['coupon_code' => null], ['quote_id = ?' => $quoteId, 'is_active = ?' => 1]);
    }

    /** @return array{items: array, discount_amount: float, coupon_code: ?string, coupon_message: ?string, discounts: array} */
    public function calculate(array $quote, array $items, float $subtotal): array
    {
        $db = Connection::get()->adapter();
        $code = isset($quote['coupon_code']) && $quote['coupon_code'] !== '' ? self::normalizeCode((string) $quote['coupon_code']) : null;
        $customerId = $quote['customer_id'] === null ? null : (int) $quote['customer_id'];
        $promotions = $db->fetchAll("SELECT p.* FROM promotion p JOIN promotion_website pw ON pw.promotion_id = p.promotion_id
            WHERE p.is_active = 1 AND pw.website_id = :website
              AND (p.starts_at IS NULL OR p.starts_at <= NOW()) AND (p.ends_at IS NULL OR p.ends_at >= NOW())
            ORDER BY p.priority ASC, p.promotion_id ASC", ['website' => (int) $quote['website_id']]);
        $coupon = null;
        if ($code !== null) {
            $coupon = $db->fetchRow("SELECT c.coupon_id, c.code, c.is_active AS coupon_active, c.usage_limit, c.usage_per_customer,
                    p.promotion_id, p.name, p.is_active AS promotion_active, p.application_type, p.discount_type, p.discount_amount,
                    p.minimum_subtotal, p.maximum_subtotal, p.starts_at, p.ends_at, p.is_accumulative, p.priority,
                    p.applies_to_sale_price
                FROM promotion_coupon c JOIN promotion p ON p.promotion_id = c.promotion_id WHERE c.code = :code", ['code' => $code]);
            if (!$coupon) throw new SalesException('COUPON_NOT_FOUND', 'Cupom não encontrado.');
            if (!(int) $coupon['coupon_active'] || !(int) $coupon['promotion_active']) throw new SalesException('COUPON_INACTIVE', 'Este cupom está inativo.');
            if ($coupon['application_type'] !== 'coupon') throw new SalesException('COUPON_NOT_APPLICABLE', 'Este código não é um cupom válido.');
            if (!$this->onWebsite($db, (int) $coupon['promotion_id'], (int) $quote['website_id'])) throw new SalesException('COUPON_NOT_APPLICABLE', 'Este cupom não é válido para esta loja.');
            if (($coupon['starts_at'] !== null && strtotime($coupon['starts_at']) > time()) || ($coupon['ends_at'] !== null && strtotime($coupon['ends_at']) < time())) throw new SalesException('COUPON_EXPIRED', 'Este cupom está fora do período de validade.');
            if ($coupon['usage_per_customer'] !== null && $customerId === null) throw new SalesException('COUPON_LOGIN_REQUIRED', 'Entre na sua conta para usar este cupom.');
            if ($coupon['usage_limit'] !== null && $this->uses($db, (int) $coupon['coupon_id']) >= (int) $coupon['usage_limit']) throw new SalesException('COUPON_USED_UP', 'Este cupom atingiu o limite de usos.');
            if ($coupon['usage_per_customer'] !== null && $this->uses($db, (int) $coupon['coupon_id'], $customerId) >= (int) $coupon['usage_per_customer']) throw new SalesException('COUPON_USED_UP', 'Você já atingiu o limite de uso deste cupom.');
            $promotions = array_values(array_filter($promotions, static fn ($p) => $p['application_type'] === 'automatic'));
            $promotions[] = $coupon;
        }

        $productIds = array_values(array_unique(array_map(static fn ($i) => (int) $i['product_id'], $items)));
        $productInfo = [];
        if ($productIds) {
            $products = $db->select()
                ->from(['p' => 'catalog_product'], ['product_id', 'attribute_set_id', 'rule_product_id' => new Expr('COALESCE(p.parent_id, p.product_id)')])
                ->where('p.product_id IN (?)', $productIds);
            foreach ($db->fetchAll($products) as $p) {
                $categories = $db->select()->from('catalog_category_product', ['category_id'])->where('product_id IN (?)', [(int) $p['product_id'], (int) $p['rule_product_id']]);
                $productInfo[(int) $p['product_id']] = ['rule_product_id' => (int) $p['rule_product_id'], 'set_id' => (int) $p['attribute_set_id'], 'categories' => array_map('intval', $db->fetchCol($categories))];
            }
        }

        $candidates = [];
        foreach ($promotions as $promotion) {
            if ($promotion['application_type'] === 'coupon' && ($coupon === null || (int) $coupon['promotion_id'] !== (int) $promotion['promotion_id'])) continue;
            if ($promotion['minimum_subtotal'] !== null && $subtotal + 0.0001 < (float) $promotion['minimum_subtotal']) continue;
            if ($promotion['maximum_subtotal'] !== null && $subtotal - 0.0001 > (float) $promotion['maximum_subtotal']) continue;
            $id = (int) $promotion['promotion_id'];
            $products = $this->ids($db, 'promotion_product', 'product_id', $id);
            $categories = $this->ids($db, 'promotion_category', 'category_id', $id);
            $sets = $this->ids($db, 'promotion_attribute_set', 'attribute_set_id', $id);
            $eligible = [];
            $base = 0.0;
            foreach ($items as $index => $item) {
                if ($item['price'] === null || (float) $item['row_total'] <= 0) continue;
                if (!empty($item['regular_price']) && (float) $item['regular_price'] > (float) $item['price'] && !(int) $promotion['applies_to_sale_price']) continue;
                $info = $productInfo[(int) $item['product_id']] ?? null;
                if (!$info) continue;
                $specified = $products || $categories || $sets;
                $matches = !$specified
                    || in_array((int) $item['product_id'], $products, true) || in_array((int) $info['rule_product_id'], $products, true)
                    || array_intersect($categories, $info['categories']) || in_array((int) $info['set_id'], $sets, true);
                if ($matches) { $eligible[] = $index; $base += (float) $item['row_total']; }
            }
            $base = round($base, 2);
            if ($base <= 0) continue;
            $amount = $promotion['discount_type'] === 'percent'
                ? round($base * min(100.0, (float) $promotion['discount_amount']) / 100, 2)
                : round((float) $promotion['discount_amount'], 2);
            $amount = min($base, max(0.0, $amount));
            if ($amount > 0) $candidates[] = ['promotion' => $promotion, 'eligible' => $eligible, 'amount' => $amount];
        }

        // Compare the combined set of accumulative campaigns with each non-accumulative campaign on its own.
        $scenarios = [[]];
        $accum = array_values(array_filter($candidates, static fn ($c) => (int) $c['promotion']['is_accumulative'] === 1));
        if ($accum) $scenarios[] = $accum;
        foreach ($candidates as $candidate) if (!(int) $candidate['promotion']['is_accumulative']) $scenarios[] = [$candidate];
        $best = [];
        $bestAmount = 0.0;
        foreach ($scenarios as $scenario) {
            $allocated = array_fill(0, count($items), 0.0);
            $total = 0.0;
            foreach ($scenario as $candidate) {
                $part = $this->allocate($candidate['amount'], $candidate['eligible'], $items, $allocated);
                $allocated = $part['allocated'];
                $total += $part['amount'];
            }
            $total = round($total, 2);
            if ($total > $bestAmount) { $bestAmount = $total; $best = $scenario; }
        }
        $allocated = array_fill(0, count($items), 0.0);
        $discounts = [];
        $chosenCoupon = null;
        foreach ($best as $candidate) {
            $part = $this->allocate($candidate['amount'], $candidate['eligible'], $items, $allocated);
            $allocated = $part['allocated'];
            if ($part['amount'] <= 0) continue;
            $p = $candidate['promotion'];
            $isCoupon = $coupon !== null && (int) $coupon['promotion_id'] === (int) $p['promotion_id'];
            if ($isCoupon) $chosenCoupon = $code;
            $discounts[] = ['promotion_id' => (int) $p['promotion_id'], 'name' => (string) $p['name'], 'coupon_code' => $isCoupon ? $code : null, 'amount' => round($part['amount'], 2)];
        }
        $decorated = [];
        foreach ($items as $index => $item) {
            $item['discount_amount'] = round($allocated[$index] ?? 0.0, 2);
            $item['row_total_after_discount'] = round(max(0.0, (float) $item['row_total'] - $item['discount_amount']), 2);
            $decorated[] = $item;
        }
        $discountTotal = round(min($subtotal, array_sum(array_column($decorated, 'discount_amount'))), 2);
        $message = $code !== null && $chosenCoupon === null ? 'O cupom ' . $code . ' não foi usado: outra promoção oferece desconto maior ou ele não se aplica a estes itens.' : null;
        return ['items' => $decorated, 'discount_amount' => $discountTotal, 'coupon_code' => $code, 'coupon_message' => $message, 'discounts' => $discounts];
    }

    private function ids(Adapter $db, string $table, string $column, int $promotionId): array
    {
        return array_map('intval', $db->fetchCol($db->select()->from($table, [$column])->where('promotion_id = ?', $promotionId)));
    }

    private function onWebsite(Adapter $db, int $promotionId, int $websiteId): bool
    {
        return (bool) $db->fetchOne($db->select()->from('promotion_website', [new Expr('1')])->where('promotion_id = ?', $promotionId)->where('website_id = ?', $websiteId));
    }

    /** Coupon uses already consumed: in total, or by one customer */
    private function uses(Adapter $db, int $couponId, ?int $customerId = null): int
    {
        $select = $db->select()->from('promotion_coupon_usage', [new Expr('COUNT(*)')])->where('coupon_id = ?', $couponId)->where('status = ?', 'used');
        if ($customerId !== null) $select->where('customer_id = ?', $customerId);
        return (int) $db->fetchOne($select);
    }

    /** Allocate cents proportionally, putting rounding remainder on the largest eligible line and capping each line. */
    private function allocate(float $amount, array $eligible, array $items, array $already): array
    {
        $eligible = array_values(array_filter($eligible, static fn ($i) => (float) $items[$i]['row_total'] - (float) ($already[$i] ?? 0) > 0));
        if (!$eligible || $amount <= 0) return ['allocated' => $already, 'amount' => 0.0];
        $base = array_sum(array_map(static fn ($i) => max(0.0, (float) $items[$i]['row_total'] - (float) ($already[$i] ?? 0)), $eligible));
        $cents = (int) round(min($amount, $base) * 100);
        usort($eligible, static fn ($a, $b) => (float) $items[$b]['row_total'] <=> (float) $items[$a]['row_total']);
        $remaining = $cents;
        foreach ($eligible as $n => $i) {
            $available = (int) round(max(0.0, (float) $items[$i]['row_total'] - (float) ($already[$i] ?? 0)) * 100);
            $lineBase = max(0.0, (float) $items[$i]['row_total'] - (float) ($already[$i] ?? 0));
            $part = $n === count($eligible) - 1 ? min($available, max(0, $remaining)) : min($available, (int) floor($cents * $lineBase / $base));
            $already[$i] = round((float) ($already[$i] ?? 0) + $part / 100, 2);
            $remaining -= $part;
        }
        // In rare cap/rounding cases, assign any remaining cents to the first line with capacity.
        foreach ($eligible as $i) {
            if ($remaining <= 0) break;
            $available = (int) round(max(0.0, (float) $items[$i]['row_total'] - (float) ($already[$i] ?? 0)) * 100);
            $part = min($available, $remaining);
            $already[$i] = round((float) ($already[$i] ?? 0) + $part / 100, 2);
            $remaining -= $part;
        }
        return ['allocated' => $already, 'amount' => round(($cents - $remaining) / 100, 2)];
    }

    public static function normalizeCode(string $code): string
    {
        $code = strtoupper(trim($code));
        if ($code === '' || strlen($code) > 32 || !preg_match('/^[A-Z0-9_-]+$/', $code)) throw new SalesException('COUPON_INVALID', 'Use até 32 letras, números, hífen ou sublinhado no cupom.');
        return $code;
    }
}
