<section class="store-group-list">
    <div class="admin-page-head">
        <h2>Grupos de loja</h2>
        <a class="admin-button admin-button-primary" href="/admin/group/create">+ Novo grupo</a>
    </div>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Website</th>
                <th>Código</th>
                <th>Nome</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
<?php if (empty($groups)): ?>
            <tr><td colspan="5" class="admin-empty">Nenhum grupo cadastrado.</td></tr>
<?php else: ?>
    <?php foreach ($groups as $group): ?>
            <tr>
                <td><?= (int) $group['group_id'] ?></td>
                <td><?= htmlspecialchars((string) $group['website_name']) ?></td>
                <td><?= htmlspecialchars((string) $group['code']) ?></td>
                <td><?= htmlspecialchars((string) $group['name']) ?></td>
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
