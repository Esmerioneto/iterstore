<section class="store-group-list">
    <h2>Grupos de loja</h2>
    <p><a href="/admin/group/create">+ Novo grupo</a></p>
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
            <tr><td colspan="5">Nenhum grupo cadastrado.</td></tr>
<?php else: ?>
    <?php foreach ($groups as $group): ?>
            <tr>
                <td><?= (int) $group['group_id'] ?></td>
                <td><?= htmlspecialchars((string) $group['website_name']) ?></td>
                <td><?= htmlspecialchars((string) $group['code']) ?></td>
                <td><?= htmlspecialchars((string) $group['name']) ?></td>
                <td>
                    <a href="/admin/group/edit?id=<?= (int) $group['group_id'] ?>">Editar</a>
                    <form method="post" action="/admin/group/delete" style="display:inline" data-confirm="Remover este grupo?">
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
</section>
