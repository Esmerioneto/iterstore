<?php
use Iter\Sales\Model\OrderService;
use Iter\Sales\View\OrderList;

$e = static fn ($v) => htmlspecialchars((string) $v);
$money = static fn ($v) => 'R$ ' . number_format((float) $v, 2, ',', '.');
// what every link and form of the list carries: filters, page size and sort (defaults left out)
$query = array_filter($filters, 'strlen')
    + ($limit === OrderList::PAGE_SIZES[0] ? [] : ['limit' => $limit])
    + ($sort === 'created_at' && $dir === 'desc' ? [] : ['sort' => $sort, 'dir' => $dir]);
$sortHeader = static fn (string $key, string $label): string => \Iter\Admin\View\Sort::th($key, $label, $sort, $dir, $query, '/admin/order/index');
?>
<section class="mail-list sales-order-list">
    <h2>Pedidos</h2>
    <form method="get" action="/admin/order/index" class="admin-filters">
        <select name="status">
            <option value="">Todas as situações</option>
<?php foreach (OrderService::STATUS_LABELS as $value => $label): ?>
            <option value="<?= $e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= $e($label) ?></option>
<?php endforeach; ?>
        </select>
<?php if (count($websites) > 1): ?>
        <select name="website_id">
            <option value="">Todos os websites</option>
    <?php foreach ($websites as $id => $name): ?>
            <option value="<?= (int) $id ?>" <?= $filters['website_id'] === (string) $id ? 'selected' : '' ?>><?= $e($name) ?></option>
    <?php endforeach; ?>
        </select>
<?php endif; ?>
        <select name="cancel_reason">
            <option value="">Todos os motivos de cancelamento</option>
<?php foreach (OrderService::CANCEL_REASONS as $value => $label): ?>
            <option value="<?= $e($value) ?>" <?= $filters['cancel_reason'] === $value ? 'selected' : '' ?>><?= $e($label) ?></option>
<?php endforeach; ?>
        </select>
<?php if (count($channels) > 1): ?>
        <select name="sales_channel">
            <option value="">Todos os canais</option>
    <?php foreach ($channels as $channel): ?>
            <option value="<?= $e($channel) ?>" <?= $filters['sales_channel'] === $channel ? 'selected' : '' ?>><?= $e($channel) ?></option>
    <?php endforeach; ?>
        </select>
<?php endif; ?>
<?php if ($payments): ?>
        <select name="payment_method">
            <option value="">Todas as formas de pagamento</option>
    <?php foreach ($payments as $code => $label): ?>
            <option value="<?= $e($code) ?>" <?= $filters['payment_method'] === (string) $code ? 'selected' : '' ?>><?= $e($label) ?></option>
    <?php endforeach; ?>
        </select>
<?php endif; ?>
<?php if ($filters['customer_id'] !== ''): ?>
        <input type="hidden" name="customer_id" value="<?= (int) $filters['customer_id'] ?>">
<?php endif; ?>
        <input type="search" name="q" placeholder="Número, e-mail ou nome" value="<?= $e($filters['q']) ?>">
        <label>De <input type="date" name="from" value="<?= $e($filters['from']) ?>"></label>
        <label>até <input type="date" name="to" value="<?= $e($filters['to']) ?>"></label>
        <button type="submit">Filtrar</button>
    </form>
    <p class="admin-list-summary"><?= (int) $total ?> pedido(s) · total <?= $money($amount) ?> (sem os cancelados)</p>
    <table>
        <thead>
            <tr>
                <?= $sortHeader('number', 'Número') ?>
                <?= $sortHeader('created_at', 'Data') ?>
                <?= $sortHeader('customer', 'Cliente') ?>
                <th>Loja</th>
                <th>Canal</th>
                <?= $sortHeader('payment', 'Pagamento') ?>
                <?= $sortHeader('total', 'Total') ?>
                <?= $sortHeader('status', 'Situação') ?>
                <th></th>
            </tr>
        </thead>
        <tbody>
<?php if (!$orders): ?>
            <tr><td colspan="9" class="admin-empty">Nenhum pedido encontrado.</td></tr>
<?php endif; ?>
<?php foreach ($orders as $order): ?>
            <tr>
                <td><?= $e($order['increment_id']) ?></td>
                <td><?= $e(substr((string) $order['created_at'], 0, 16)) ?></td>
                <td><?= $e($order['firstname'] . ' ' . $order['lastname']) ?><br><small><?= $e($order['email']) ?><?= $order['customer_id'] === null ? ' (visitante)' : '' ?></small></td>
                <td><?= $e($websites[$order['website_id']] ?? '—') ?></td>
                <td><?= $e($order['sales_channel']) ?></td>
                <td><?= $e($order['payment_label'] ?? '—') ?></td>
                <td><?= $money($order['grand_total']) ?></td>
                <td><span class="admin-status admin-status-<?= $e($order['status']) ?>"><?= $e(OrderService::STATUS_LABELS[$order['status']] ?? $order['status']) ?></span><?= $order['status'] === 'canceled' ? '<br><small>' . $e(OrderService::CANCEL_REASONS[$order['cancel_reason'] ?? ''] ?? 'Não informado') . '</small>' : '' ?></td>
                <td class="admin-row-action"><a href="/admin/order/view?id=<?= (int) $order['order_id'] ?>">Ver</a></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
    <?php \Iter\Admin\View\Pager::render($pager); ?>
</section>
