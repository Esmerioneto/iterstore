<section class="admin-user-list">
    <h2>Usuários admin</h2>
    <p><a href="/admin/user/create">+ Novo usuário</a></p>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Usuário</th>
                <th>Nome</th>
                <th>E-mail</th>
                <th>Perfil</th>
                <th>Ativo</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
<?php if (empty($users)): ?>
            <tr><td colspan="7">Nenhum usuário cadastrado.</td></tr>
<?php else: ?>
    <?php foreach ($users as $user): ?>
            <tr>
                <td><?= htmlspecialchars((string) $user['user_id']) ?></td>
                <td><?= htmlspecialchars($user['username']) ?></td>
                <td><?= htmlspecialchars(trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? ''))) ?></td>
                <td><?= htmlspecialchars($user['email'] ?? '') ?></td>
                <td><?= htmlspecialchars($user['role_name'] ?? '—') ?></td>
                <td><?= $user['is_active'] ? 'Sim' : 'Não' ?></td>
                <td>
                    <a href="/admin/user/edit?id=<?= (int) $user['user_id'] ?>">Editar</a>
                    <form method="post" action="/admin/user/delete" style="display:inline" data-confirm="Remover este usuário?">
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
</section>
