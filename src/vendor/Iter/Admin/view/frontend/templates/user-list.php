<section class="admin-user-list">
    <div class="admin-page-head">
        <h2>Usuários admin</h2>
        <a class="admin-button admin-button-primary" href="/admin/user/create">+ Novo usuário</a>
    </div>
    <form method="get" action="/admin/user/index" class="admin-filters">
        <select name="is_active">
            <option value="">Ativos e inativos</option>
            <option value="1" <?= $is_active === '1' ? 'selected' : '' ?>>Só ativos</option>
            <option value="0" <?= $is_active === '0' ? 'selected' : '' ?>>Só inativos</option>
        </select>
        <input type="search" name="q" placeholder="Usuário, nome ou e-mail" value="<?= htmlspecialchars($q) ?>">
        <?= \Iter\Admin\View\Sort::hidden() ?>
        <button type="submit">Filtrar</button>
    </form>
    <table>
        <thead>
            <tr>
                <?= \Iter\Admin\View\Sort::th('id', 'ID', $sort, $dir) ?>
                <?= \Iter\Admin\View\Sort::th('username', 'Usuário', $sort, $dir) ?>
                <?= \Iter\Admin\View\Sort::th('name', 'Nome', $sort, $dir) ?>
                <?= \Iter\Admin\View\Sort::th('email', 'E-mail', $sort, $dir) ?>
                <?= \Iter\Admin\View\Sort::th('role', 'Perfil', $sort, $dir) ?>
                <?= \Iter\Admin\View\Sort::th('is_active', 'Ativo', $sort, $dir) ?>
                <th></th>
            </tr>
        </thead>
        <tbody>
<?php if (empty($users)): ?>
            <tr><td colspan="7" class="admin-empty">Nenhum usuário cadastrado.</td></tr>
<?php else: ?>
    <?php foreach ($users as $user): ?>
            <tr>
                <td><?= htmlspecialchars((string) $user['user_id']) ?></td>
                <td><?= htmlspecialchars($user['username']) ?></td>
                <td><?= htmlspecialchars(trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? ''))) ?></td>
                <td><?= htmlspecialchars($user['email'] ?? '') ?></td>
                <td><?= htmlspecialchars($user['role_name'] ?? '—') ?></td>
                <td><span class="admin-status admin-status-<?= $user['is_active'] ? 'on' : 'off' ?>"><?= $user['is_active'] ? 'Sim' : 'Não' ?></span></td>
                <td class="admin-row-action">
                    <a href="/admin/user/edit?id=<?= (int) $user['user_id'] ?>">Editar</a>
                    <form method="post" action="/admin/user/delete" data-confirm="Remover este usuário?">
                        <?= \Iter\Core\FormKey::field() ?>
                        <input type="hidden" name="user_id" value="<?= (int) $user['user_id'] ?>">
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
