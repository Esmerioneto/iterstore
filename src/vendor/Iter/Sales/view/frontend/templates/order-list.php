<?php
use Iter\Sales\Model\OrderService;
use Iter\Sales\View\OrderList;

$e = static fn ($v) => htmlspecialchars((string) $v);
$money = static fn ($v) => 'R$ ' . number_format((float) $v, 2, ',', '.');
// what every link and form of the list carries: filters, page size and sort (defaults left out)
$query = array_filter($filters, 'strlen')
    + ($limit === OrderList::PAGE_SIZES[0] ? [] : ['limit' => $limit])
    + ($sort === 'created_at' && $dir === 'desc' ? [] : ['sort' => $sort, 'dir' => $dir]);
// a title sorts by its column; clicked again, it flips the direction (back to page 1)
$sortHeader = static function (string $key, string $label) use ($query, $sort, $dir): string {
    $active = $key === $sort;
    $next = $active && $dir === 'asc' ? 'desc' : 'asc';
    $href = '/admin/order/index?' . http_build_query(array_diff_key($query, ['sort' => 1, 'dir' => 1]) + ['sort' => $key, 'dir' => $next]);
    return '<th class="admin-sortable' . ($active ? ' is-sorted' : '') . '"' . ($active ? ' aria-sort="' . ($dir === 'asc' ? 'ascending' : 'descending') . '"' : '') . '>'
        . '<a href="' . htmlspecialchars($href) . '">' . htmlspecialchars($label) . ($active ? ($dir === 'asc' ? ' ▲' : ' ▼') : '') . '</a></th>';
};
$pageUrl = static fn (int $p) => '/admin/order/index?' . htmlspecialchars(http_build_query($query + ['page' => $p]));
?>
<section class="mail-list sales-order-list">
    <h2>Pedidos</h2>
    <form method="get" action="/admin/order/index">
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
<?php if ($filters['customer_id'] !== ''): ?>
        <input type="hidden" name="customer_id" value="<?= (int) $filters['customer_id'] ?>">
<?php endif; ?>
        <input type="search" name="q" placeholder="Número, e-mail ou nome" value="<?= $e($filters['q']) ?>">
        <label>De <input type="date" name="from" value="<?= $e($filters['from']) ?>"></label>
        <label>até <input type="date" name="to" value="<?= $e($filters['to']) ?>"></label>
        <button type="submit">Filtrar</button>
    </form>
    <p><?= (int) $total ?> pedido(s) · total <?= $money($amount) ?> (sem os cancelados)</p>
    <table>
        <thead>
            <tr>
                <?= $sortHeader('number', 'Número') ?>
                <?= $sortHeader('created_at', 'Data') ?>
                <?= $sortHeader('customer', 'Cliente') ?>
                <?= $sortHeader('total', 'Total') ?>
                <?= $sortHeader('status', 'Situação') ?>
                <th></th>
            </tr>
        </thead>
        <tbody>
<?php if (!$orders): ?>
            <tr><td colspan="6">Nenhum pedido encontrado.</td></tr>
<?php endif; ?>
<?php foreach ($orders as $order): ?>
            <tr>
                <td><?= $e($order['increment_id']) ?></td>
                <td><?= $e(substr((string) $order['created_at'], 0, 16)) ?></td>
                <td><?= $e($order['firstname'] . ' ' . $order['lastname']) ?><br><small><?= $e($order['email']) ?><?= $order['customer_id'] === null ? ' (visitante)' : '' ?></small></td>
                <td><?= $money($order['grand_total']) ?></td>
                <td><?= $e(OrderService::STATUS_LABELS[$order['status']] ?? $order['status']) ?></td>
                <td><a href="/admin/order/view?id=<?= (int) $order['order_id'] ?>">Ver</a></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
    <form method="get" action="/admin/order/index" class="admin-pager">
<?php foreach (array_diff_key($query, ['limit' => 1]) as $name => $value): ?>
        <input type="hidden" name="<?= $e($name) ?>" value="<?= $e($value) ?>">
<?php endforeach; ?>
        <label>Ir para a página: <input type="number" name="page" value="<?= (int) $page ?>" min="1" max="<?= (int) $total_pages ?>"></label>
        <label>Mostrar linhas:
            <select name="limit" data-autosubmit>
<?php foreach (OrderList::PAGE_SIZES as $size): ?>
                <option value="<?= $size ?>" <?= $size === $limit ? 'selected' : '' ?>><?= $size ?></option>
<?php endforeach; ?>
            </select>
        </label>
        <span><?= $total ? (($page - 1) * $limit + 1) . '-' . min($page * $limit, $total) : 0 ?> de <?= (int) $total ?></span>
        <span class="admin-pager-arrows">
<?php if ($page > 1): ?>
            <a href="<?= $pageUrl($page - 1) ?>" title="Página anterior" aria-label="Página anterior">&#9664;</a>
<?php else: ?>
            <span aria-disabled="true">&#9664;</span>
<?php endif; ?>
<?php if ($page < $total_pages): ?>
            <a href="<?= $pageUrl($page + 1) ?>" title="Próxima página" aria-label="Próxima página">&#9654;</a>
<?php else: ?>
            <span aria-disabled="true">&#9654;</span>
<?php endif; ?>
        </span>
    </form>
</section>
