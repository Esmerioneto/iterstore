<section class="admin-role-list">
    <div class="admin-page-head">
        <h2>Perfis</h2>
        <a class="admin-button admin-button-primary" href="/admin/role/create">+ Novo perfil</a>
    </div>
    <form method="get" action="/admin/role/index" class="admin-filters">
        <input type="search" name="q" placeholder="Nome do perfil" value="<?= htmlspecialchars($q) ?>">
        <?= \Iter\Admin\View\Sort::hidden() ?>
        <button type="submit">Filtrar</button>
    </form>
    <table>
        <thead>
            <tr>
                <?= \Iter\Admin\View\Sort::th('id', 'ID', $sort, $dir) ?><?= $gridColumns->th('id') ?>
                <?= \Iter\Admin\View\Sort::th('name', 'Nome', $sort, $dir) ?><?= $gridColumns->th('name') ?>
                <th></th>
            </tr>
        </thead>
        <tbody>
<?php if (empty($roles)): ?>
            <tr><td colspan="<?= 3 + $gridColumns->count() ?>" class="admin-empty">Nenhum perfil cadastrado.</td></tr>
<?php else: ?>
    <?php foreach ($roles as $role): ?>
            <tr>
                <td><?= htmlspecialchars((string) $role['role_id']) ?></td><?= $gridColumns->td('id', $role['role_id']) ?>
                <td><?= htmlspecialchars($role['role_name']) ?></td><?= $gridColumns->td('name', $role['role_id']) ?>
                <td class="admin-row-action">
                    <a href="/admin/role/edit?id=<?= (int) $role['role_id'] ?>">Editar</a>
                    <form method="post" action="/admin/role/delete" data-confirm="Remover este perfil?">
                        <?= \Iter\Core\FormKey::field() ?>
                        <input type="hidden" name="role_id" value="<?= (int) $role['role_id'] ?>">
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
