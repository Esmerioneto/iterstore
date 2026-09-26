<section class="store-website-list">
    <h2>Websites</h2>
    <p><a href="/admin/website/create">+ Novo website</a></p>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Código</th>
                <th>Nome</th>
                <th>Ordem</th>
                <th>Padrão</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
<?php if (empty($websites)): ?>
            <tr><td colspan="6">Nenhum website cadastrado.</td></tr>
<?php else: ?>
    <?php foreach ($websites as $website): ?>
            <tr>
                <td><?= (int) $website['website_id'] ?></td>
                <td><?= htmlspecialchars((string) $website['code']) ?></td>
                <td><?= htmlspecialchars((string) $website['name']) ?></td>
                <td><?= (int) $website['sort_order'] ?></td>
                <td><?= $website['is_default'] ? 'Sim' : '' ?></td>
                <td>
                    <a href="/admin/website/edit?id=<?= (int) $website['website_id'] ?>">Editar</a>
                    <form method="post" action="/admin/website/delete" style="display:inline" data-confirm="Remover este website e seus grupos?">
                        <?= \Iter\Core\FormKey::field() ?>
                        <input type="hidden" name="website_id" value="<?= (int) $website['website_id'] ?>">
                        <button type="submit">Remover</button>
                    </form>
                </td>
            </tr>
    <?php endforeach; ?>
<?php endif; ?>
        </tbody>
    </table>
</section>
