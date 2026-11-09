<section class="store-group-list">
    <div class="admin-page-head">
        <h2>Grupos de loja</h2>
        <a class="admin-button admin-button-primary" href="/admin/group/create">+ Novo grupo</a>
    </div>
    <form method="get" action="/admin/group/index" class="admin-filters">
<?php if (count($websites) > 1): ?>
        <select name="website_id">
            <option value="">Todos os websites</option>
    <?php foreach ($websites as $id => $name): ?>
            <option value="<?= (int) $id ?>" <?= $website_id === (string) $id ? 'selected' : '' ?>><?= htmlspecialchars((string) $name) ?></option>
    <?php endforeach; ?>
        </select>
<?php endif; ?>
        <input type="search" name="q" placeholder="Código, nome ou website" value="<?= htmlspecialchars($q) ?>">
        <?= \Iter\Admin\View\Sort::hidden() ?>
        <button type="submit">Filtrar</button>
    </form>
    <table>
        <thead>
            <tr>
                <?= \Iter\Admin\View\Sort::th('id', 'ID', $sort, $dir) ?><?= $gridColumns->th('id') ?>
                <?= \Iter\Admin\View\Sort::th('website', 'Website', $sort, $dir) ?><?= $gridColumns->th('website') ?>
                <?= \Iter\Admin\View\Sort::th('code', 'Código', $sort, $dir) ?><?= $gridColumns->th('code') ?>
                <?= \Iter\Admin\View\Sort::th('name', 'Nome', $sort, $dir) ?><?= $gridColumns->th('name') ?>
                <th></th>
            </tr>
        </thead>
        <tbody>
<?php if (empty($groups)): ?>
            <tr><td colspan="<?= 5 + $gridColumns->count() ?>" class="admin-empty">Nenhum grupo cadastrado.</td></tr>
<?php else: ?>
    <?php foreach ($groups as $group): ?>
            <tr>
                <td><?= (int) $group['group_id'] ?></td><?= $gridColumns->td('id', $group['group_id']) ?>
                <td><?= htmlspecialchars((string) $group['website_name']) ?></td><?= $gridColumns->td('website', $group['group_id']) ?>
                <td><?= htmlspecialchars((string) $group['code']) ?></td><?= $gridColumns->td('code', $group['group_id']) ?>
                <td><?= htmlspecialchars((string) $group['name']) ?></td><?= $gridColumns->td('name', $group['group_id']) ?>
                <td class="admin-row-action">
                    <a href="/admin/group/edit?id=<?= (int) $group['group_id'] ?>">Editar</a>
                    <form method="post" action="/admin/group/delete" data-confirm="Remover este grupo?">
                        <?= \Iter\Core\FormKey::field() ?>
                        <input type="hidden" name="group_id" value="<?= (int) $group['group_id'] ?>">
                        <button type="submit">Remover</button>
                    </form>
                </td>
            </tr>
    <?php endforeach; ?>
<?php endif; ?>
        </tbody>
    </table>
    <?php \Iter\Admin\View\Pager::render($pager); ?>
</section>
