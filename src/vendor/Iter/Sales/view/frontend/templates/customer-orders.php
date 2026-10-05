<?php
if ($orders === null) return;
use Iter\Sales\Model\OrderService;

$e = static fn ($v) => htmlspecialchars((string) $v);
?>
<section class="mail-list sales-customer-orders">
    <h2>Pedidos</h2>
<?php if (!$orders): ?>
    <p>Nenhum pedido deste cliente.</p>
<?php else: ?>
    <table>
        <thead><tr><th>Número</th><th>Data</th><th>Total</th><th>Situação</th></tr></thead>
        <tbody>
    <?php foreach ($orders as $order): ?>
            <tr>
                <td><a href="/admin/order/view?id=<?= (int) $order['order_id'] ?>"><?= $e($order['increment_id']) ?></a></td>
                <td><?= $e(substr((string) $order['created_at'], 0, 16)) ?></td>
                <td>R$ <?= number_format((float) $order['grand_total'], 2, ',', '.') ?></td>
                <td><?= $e(OrderService::STATUS_LABELS[$order['status']] ?? $order['status']) ?></td>
            </tr>
    <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($total > count($orders)): ?>
    <p><a href="/admin/order/index?customer_id=<?= (int) $customer_id ?>">Ver todos os <?= (int) $total ?> pedidos</a></p>
    <?php endif; ?>
<?php endif; ?>
</section>
