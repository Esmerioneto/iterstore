<?php
if ($items === null) return;

$e = static fn ($v) => htmlspecialchars((string) $v);
?>
<section class="mail-list wishlist-customer-items">
    <h2>Favoritos<?= $items ? ' (' . count($items) . ')' : '' ?></h2>
<?php if (!$items): ?>
    <p>Nenhum produto favoritado por este cliente.</p>
<?php else: ?>
    <table>
        <thead><tr><th>Produto</th><th>SKU</th><th>Favoritado em</th><th>Situação</th></tr></thead>
        <tbody>
    <?php foreach ($items as $item): ?>
            <tr>
                <td><?php if ($can_edit_product): ?><a href="/admin/product/edit?id=<?= (int) $item['product_id'] ?>"><?= $e($item['name']) ?></a><?php else: ?><?= $e($item['name']) ?><?php endif; ?></td>
                <td><?= $e($item['sku']) ?></td>
                <td><?= $e(date('d/m/Y H:i', strtotime((string) $item['created_at']))) ?></td>
                <td><?= $item['visible'] ? '<span class="admin-status admin-status-on">Na lista</span>' : '<span class="admin-status admin-status-off" title="Produto inativo ou fora do website do cliente. Volta para a lista se voltar.">Oculto para o cliente</span>' ?></td>
            </tr>
    <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</section>
