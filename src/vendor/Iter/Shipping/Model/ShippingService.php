<?php

declare(strict_types=1);

namespace Iter\Shipping\Model;

use Iter\Core\Config;
use Iter\Core\Db\Connection;
use Iter\Sales\Model\SalesException;

/**
 * Shipping options for a cart (COMMERCE-PLAN §4), each switched on per website in Configurações → Frete:
 * - tablerate: the cheapest-fitting row of shipping_table_rate for the delivery postcode and cart weight;
 * - freeshipping: 0 when the subtotal reaches the configured value;
 * - pickup: 0, at the store's address.
 * The first two need the delivery address.
 * ponytail: three methods in one class; a ShippingMethodInterface when Melhor Envio (a 4th, external) arrives
 */
class ShippingService
{
    public const METHODS = ['tablerate', 'freeshipping', 'pickup'];

    /**
     * @param array $cart CartService::view() (website_id, subtotal, shipping_address, items with weight)
     * @return array<string, array{code: string, label: string, amount: float, days: int}> by code, cheapest first
     */
    public function rates(array $cart): array
    {
        $websiteId = (int) $cart['website_id'];
        $on = static fn (string $method) => (bool) Config::get("shipping/{$method}/active", $websiteId);
        $postcode = $cart['shipping_address']['postcode'] ?? null;
        $rates = [];
        if ($postcode !== null && $on('tablerate')) {
            $weight = array_sum(array_map(static fn ($i) => (float) ($i['weight'] ?? 0) * (float) $i['quantity'], $cart['items']));
            $row = $this->tableRate($websiteId, (string) $postcode, $weight);
            if ($row !== null) {
                $rates['tablerate'] = ['code' => 'tablerate', 'label' => (string) (Config::get('shipping/tablerate/label', $websiteId) ?: 'Entrega'), 'amount' => (float) $row['price'], 'days' => (int) $row['days']];
            }
        }
        if ($postcode !== null && $on('freeshipping') && (float) $cart['subtotal'] >= (float) Config::get('shipping/freeshipping/min_subtotal', $websiteId)) {
            $rates['freeshipping'] = ['code' => 'freeshipping', 'label' => 'Frete grátis', 'amount' => 0.0, 'days' => (int) Config::get('shipping/freeshipping/days', $websiteId)];
        }
        if ($on('pickup')) {
            $address = array_filter([Config::get('store/address/street', $websiteId), Config::get('store/address/number', $websiteId), Config::get('store/address/city', $websiteId)]);
            $rates['pickup'] = ['code' => 'pickup', 'label' => 'Retirar na loja' . ($address ? ' (' . implode(', ', $address) . ')' : ''), 'amount' => 0.0, 'days' => (int) Config::get('shipping/pickup/days', $websiteId)];
        }
        uasort($rates, static fn ($a, $b) => [$a['amount'], $a['days']] <=> [$b['amount'], $b['days']]);

        return $rates;
    }

    /** @return array<int, array{postcode_from: string, postcode_to: string, weight_to: ?float, price: float, days: int}> */
    public function getTableRates(int $websiteId): array
    {
        $db = Connection::get()->adapter();

        return array_map(static fn ($r) => [
            'postcode_from' => $r['postcode_from'],
            'postcode_to' => $r['postcode_to'],
            'weight_to' => $r['weight_to'] === null ? null : (float) $r['weight_to'],
            'price' => (float) $r['price'],
            'days' => (int) $r['days'],
        ], $db->fetchAll($db->select()->from('shipping_table_rate')->where('website_id = ?', $websiteId)->order(['postcode_from', 'weight_to NULLS LAST'])));
    }

    /**
     * The list sent is the website's whole table (rows left out are removed). Postcodes with or
     * without mask; weight_to empty = any weight. All validated before anything is written.
     *
     * @param array<int, array{postcode_from: string, postcode_to: string, weight_to?: ?float, price: float, days?: int}> $rows
     */
    public function saveTableRates(int $websiteId, array $rows): void
    {
        $clean = [];
        foreach (array_values($rows) as $i => $row) {
            $error = static fn (string $message) => throw new SalesException('SHIPPING_RATE_INVALID', 'Faixa ' . ($i + 1) . ": {$message}");
            [$from, $to] = [preg_replace('/\D/', '', (string) ($row['postcode_from'] ?? '')), preg_replace('/\D/', '', (string) ($row['postcode_to'] ?? ''))];
            if (strlen($from) !== 8 || strlen($to) !== 8 || $from > $to) {
                $error('informe os CEPs inicial e final com 8 dígitos, o inicial menor ou igual ao final.');
            }
            $weight = ($row['weight_to'] ?? null) === null ? null : (float) $row['weight_to'];
            if ($weight !== null && $weight <= 0) {
                $error('o peso até deve ser maior que zero (ou vazio para qualquer peso).');
            }
            $price = (float) ($row['price'] ?? -1);
            if ($price < 0 || $price > 9999999999) {
                $error('informe um valor de frete zero ou positivo.');
            }
            $days = (int) ($row['days'] ?? 0);
            if ($days < 0 || $days > 365) {
                $error('o prazo vai de 0 a 365 dias úteis.');
            }
            $clean[] = ['website_id' => $websiteId, 'postcode_from' => $from, 'postcode_to' => $to, 'weight_to' => $weight, 'price' => round($price, 2), 'days' => $days];
        }
        Connection::get()->adapter()->transaction(static function ($db) use ($websiteId, $clean): void {
            $db->delete('shipping_table_rate', ['website_id = ?' => $websiteId]);
            if ($clean) {
                $db->insertMultiple('shipping_table_rate', $clean);
            }
        });
    }

    /** the fitting rows of the postcode's range; with weights, the smallest "weight up to" that holds the cart; then the cheapest */
    private function tableRate(int $websiteId, string $postcode, float $weight): ?array
    {
        $db = Connection::get()->adapter();
        $row = $db->fetchRow($db->select()->from('shipping_table_rate', ['price', 'days'])
            ->where('website_id = ?', $websiteId)
            ->where('? BETWEEN postcode_from AND postcode_to', $postcode)
            ->where('(weight_to IS NULL OR weight_to >= ?)', $weight)
            ->order(['weight_to NULLS LAST', 'price'])
            ->limit(1));

        return $row ?: null;
    }
}
