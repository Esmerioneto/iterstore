<section class="customer-group-list">
    <h2>Grupos de clientes</h2>
    <p><a href="/admin/customergroup/create">+ Novo grupo</a></p>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Código</th>
                <th>Nome</th>
                <th>Clientes</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
<?php if (empty($groups)): ?>
            <tr><td colspan="5">Nenhum grupo cadastrado.</td></tr>
<?php else: ?>
    <?php foreach ($groups as $group): ?>
            <tr>
                <td><?= (int) $group['customer_group_id'] ?></td>
                <td><?= htmlspecialchars((string) $group['code']) ?></td>
                <td><?= htmlspecialchars((string) $group['name']) ?></td>
                <td><?= (int) $group['customer_count'] ?></td>
                <td>
                    <a href="/admin/customergroup/edit?id=<?= (int) $group['customer_group_id'] ?>">Editar</a>
        <?php if (!$group['customer_count']): ?>
                    <form method="post" action="/admin/customergroup/delete" style="display:inline" data-confirm="Remover este grupo?">
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
