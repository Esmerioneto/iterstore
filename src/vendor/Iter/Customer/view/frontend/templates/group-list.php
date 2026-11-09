<section class="customer-group-list">
    <div class="admin-page-head">
        <h2>Grupos de clientes</h2>
        <a class="admin-button admin-button-primary" href="/admin/customergroup/create">+ Novo grupo</a>
    </div>
    <form method="get" action="/admin/customergroup/index" class="admin-filters">
        <input type="search" name="q" placeholder="Código ou nome" value="<?= htmlspecialchars($q) ?>">
        <?= \Iter\Admin\View\Sort::hidden() ?>
        <button type="submit">Filtrar</button>
    </form>
    <table>
        <thead>
            <tr>
                <?= \Iter\Admin\View\Sort::th('id', 'ID', $sort, $dir) ?><?= $gridColumns->th('id') ?>
                <?= \Iter\Admin\View\Sort::th('code', 'Código', $sort, $dir) ?><?= $gridColumns->th('code') ?>
                <?= \Iter\Admin\View\Sort::th('name', 'Nome', $sort, $dir) ?><?= $gridColumns->th('name') ?>
                <?= \Iter\Admin\View\Sort::th('customers', 'Clientes', $sort, $dir) ?><?= $gridColumns->th('customers') ?>
                <th></th>
            </tr>
        </thead>
        <tbody>
<?php if (empty($groups)): ?>
            <tr><td colspan="<?= 5 + $gridColumns->count() ?>" class="admin-empty">Nenhum grupo cadastrado.</td></tr>
<?php else: ?>
    <?php foreach ($groups as $group): ?>
            <tr>
                <td><?= (int) $group['customer_group_id'] ?></td><?= $gridColumns->td('id', $group['customer_group_id']) ?>
                <td><?= htmlspecialchars((string) $group['code']) ?></td><?= $gridColumns->td('code', $group['customer_group_id']) ?>
                <td><?= htmlspecialchars((string) $group['name']) ?></td><?= $gridColumns->td('name', $group['customer_group_id']) ?>
                <td><?= (int) $group['customer_count'] ?></td><?= $gridColumns->td('customers', $group['customer_group_id']) ?>
                <td class="admin-row-action">
                    <a href="/admin/customergroup/edit?id=<?= (int) $group['customer_group_id'] ?>">Editar</a>
        <?php if (!$group['customer_count']): ?>
                    <form method="post" action="/admin/customergroup/delete" data-confirm="Remover este grupo?">
                        <?= \Iter\Core\FormKey::field() ?>
                        <input type="hidden" name="customer_group_id" value="<?= (int) $group['customer_group_id'] ?>">
                        <button type="submit">Remover</button>
                    </form>
        <?php endif; ?>
                </td>
            </tr>
    <?php endforeach; ?>
<?php endif; ?>
        </tbody>
    </table>
    <?php \Iter\Admin\View\Pager::render($pager); ?>
</section>
