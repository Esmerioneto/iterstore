<section class="admin-role-list">
    <div class="admin-page-head">
        <h2>Perfis</h2>
        <a class="admin-button admin-button-primary" href="/admin/role/create">+ Novo perfil</a>
    </div>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Nome</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
<?php if (empty($roles)): ?>
            <tr><td colspan="3" class="admin-empty">Nenhum perfil cadastrado.</td></tr>
<?php else: ?>
    <?php foreach ($roles as $role): ?>
            <tr>
                <td><?= htmlspecialchars((string) $role['role_id']) ?></td>
                <td><?= htmlspecialchars($role['role_name']) ?></td>
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
