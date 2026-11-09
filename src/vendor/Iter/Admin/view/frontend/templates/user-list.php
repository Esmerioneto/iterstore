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
                <?= \Iter\Admin\View\Sort::th('id', 'ID', $sort, $dir) ?><?= $gridColumns->th('id') ?>
                <?= \Iter\Admin\View\Sort::th('username', 'Usuário', $sort, $dir) ?><?= $gridColumns->th('username') ?>
                <?= \Iter\Admin\View\Sort::th('name', 'Nome', $sort, $dir) ?><?= $gridColumns->th('name') ?>
                <?= \Iter\Admin\View\Sort::th('email', 'E-mail', $sort, $dir) ?><?= $gridColumns->th('email') ?>
                <?= \Iter\Admin\View\Sort::th('role', 'Perfil', $sort, $dir) ?><?= $gridColumns->th('role') ?>
                <?= \Iter\Admin\View\Sort::th('is_active', 'Ativo', $sort, $dir) ?><?= $gridColumns->th('is_active') ?>
                <th></th>
            </tr>
        </thead>
        <tbody>
<?php if (empty($users)): ?>
            <tr><td colspan="<?= 7 + $gridColumns->count() ?>" class="admin-empty">Nenhum usuário cadastrado.</td></tr>
<?php else: ?>
    <?php foreach ($users as $user): ?>
            <tr>
                <td><?= htmlspecialchars((string) $user['user_id']) ?></td><?= $gridColumns->td('id', $user['user_id']) ?>
                <td><?= htmlspecialchars($user['username']) ?></td><?= $gridColumns->td('username', $user['user_id']) ?>
                <td><?= htmlspecialchars(trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? ''))) ?></td><?= $gridColumns->td('name', $user['user_id']) ?>
                <td><?= htmlspecialchars($user['email'] ?? '') ?></td><?= $gridColumns->td('email', $user['user_id']) ?>
                <td><?= htmlspecialchars($user['role_name'] ?? '—') ?></td><?= $gridColumns->td('role', $user['user_id']) ?>
                <td><span class="admin-status admin-status-<?= $user['is_active'] ? 'on' : 'off' ?>"><?= $user['is_active'] ? 'Sim' : 'Não' ?></span></td><?= $gridColumns->td('is_active', $user['user_id']) ?>
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
