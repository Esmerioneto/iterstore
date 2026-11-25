<?php

declare(strict_types=1);

namespace Iter\Sales\Model;

use Iter\Core\Db\Connection;
use Iter\Lgpd\Model\PersonalDataInterface;

/**
 * LGPD for orders and carts (Iter_Lgpd calls it, see etc/lgpd.json). Orders keep their copy of the buyer's data
 * (tax law; the retention routine anonymizes them later, LGPD-PLAN §10) and an order still running stops the
 * erase. Carts, of the customer or left by them as a visitor, are deleted: they repeat name, CPF and addresses.
 */
final class PersonalData implements PersonalDataInterface
{
    /** paid or awaiting payment and not delivered yet */
    public const OPEN = ['pending_payment', 'processing', 'shipped'];

    public function export(?int $customerId, string $email): array
    {
        $db = Connection::get()->adapter();
        $select = $db->select()->from('sales_order', ['order_id', 'increment_id', 'created_at', 'status', 'email', 'firstname', 'lastname', 'taxvat',
            'grand_total', 'shipping_label', 'payment_label'])->order('order_id');
        $customerId !== null
            ? $select->where('customer_id = ?', $customerId)
            : $select->where('customer_id IS NULL')->where('LOWER(email) = ?', mb_strtolower($email));
        $orders = [];
        foreach ($db->fetchAll($select) as $o) {
            $id = (int) $o['order_id'];
            $orders[] = [
                'Pedido' => $o['increment_id'],
                'Data' => substr((string) $o['created_at'], 0, 16),
                'Situação' => OrderService::STATUS_LABELS[$o['status']] ?? $o['status'],
                'Comprador' => trim($o['firstname'] . ' ' . $o['lastname']) . ' — ' . $o['email'] . ($o['taxvat'] ? ' — ' . $o['taxvat'] : ''),
                'Total' => $o['grand_total'],
                'Entrega' => $o['shipping_label'],
                'Pagamento' => $o['payment_label'],
                'Itens' => array_map(static fn ($i) => $i['quantity'] . ' × ' . $i['name'] . ' (' . $i['sku'] . ') = ' . $i['row_total'],
                    $db->fetchAll('SELECT name, sku, quantity, row_total FROM sales_order_item WHERE order_id = :id ORDER BY item_id', ['id' => $id])),
                'Endereços' => array_map(static fn ($a) => ($a['type'] === 'billing' ? 'Cobrança: ' : 'Entrega: ') . implode(', ', array_filter([$a['recipient'],
                    trim($a['street'] . ' ' . $a['number']), $a['complement'], $a['district'], $a['city'] . '/' . $a['region'], $a['postcode'], $a['telephone']])),
                    $db->fetchAll('SELECT * FROM sales_order_address WHERE order_id = :id ORDER BY type DESC', ['id' => $id])),
            ];
        }
        $cart = $customerId === null ? [] : $db->fetchCol("SELECT i.quantity || ' × ' || COALESCE(p.name || ' (' || p.sku || ')', i.product_id::text) FROM sales_quote q
            JOIN sales_quote_item i ON i.quote_id = q.quote_id LEFT JOIN catalog_product p ON p.product_id = i.product_id
            WHERE q.customer_id = :c AND q.is_active = 1 ORDER BY i.item_id", ['c' => $customerId]);

        return array_filter(['Pedidos' => $orders, 'Carrinho' => $cart]);
    }

    public function blockers(int $customerId): array
    {
        $open = Connection::get()->adapter()->fetchCol('SELECT increment_id FROM sales_order WHERE customer_id = :c AND status IN (\'' . implode("','", self::OPEN) . '\') ORDER BY order_id',
            ['c' => $customerId]);

        return $open ? ['Pedidos em andamento: ' . implode(', ', $open) . '. Conclua ou cancele antes.'] : [];
    }

    /**
     * Orders older than order_years lose the buyer's copy of their data (LGPD-PLAN §10), of customers and visitors:
     * name, CPF/CNPJ, e-mail, recipient, phone, street, postcode. Items, totals, city and state stay for the reports.
     * The anonymous e-mail marks the order as done, so it isn't touched again.
     */
    public function purge(array $retention): int
    {
        $years = (int) ($retention['order_years'] ?? 0);
        if ($years < 1) {
            return 0;
        }
        $db = Connection::get()->adapter();
        $ids = $db->fetchCol("UPDATE sales_order SET email = 'excluido-pedido-' || order_id || '@anonimo.invalid', firstname = :first, lastname = :last, taxvat = ''
            WHERE created_at < :cut AND status NOT IN ('" . implode("','", self::OPEN) . "') AND email NOT LIKE '%@anonimo.invalid' RETURNING order_id",
            ['first' => 'Cliente', 'last' => 'excluído', 'cut' => date('Y-m-d H:i:s', strtotime("-{$years} years"))]);
        if ($ids) {
            $db->update('sales_order_address', ['recipient' => null, 'telephone' => null, 'street' => null, 'number' => null, 'complement' => null, 'district' => null,
                'postcode' => null, 'attributes' => '{}'], ['order_id IN (?)' => array_map('intval', $ids)]);
        }

        return count($ids);
    }

    public function erase(int $customerId, string $email): void
    {
        $db = Connection::get()->adapter();
        $db->delete('sales_quote', ['customer_id = ?' => $customerId]); // the orders' quote_id becomes null
        $db->delete('sales_quote', ['customer_id IS NULL', 'LOWER(email) = ?' => mb_strtolower($email)]);
    }
}
