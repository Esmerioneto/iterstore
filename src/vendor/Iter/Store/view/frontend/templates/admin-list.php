<section class="store-admin-list">
    <h2>Lojas</h2>
    <p><a href="/admin/store/create">+ Nova loja</a></p>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Nome</th>
                <th>Website / Grupo</th>
                <th>Webview</th>
                <th>Tema</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
<?php if (empty($stores)): ?>
            <tr><td colspan="6">Nenhuma loja cadastrada.</td></tr>
<?php else: ?>
    <?php foreach ($stores as $store): ?>
            <tr>
                <td><?= htmlspecialchars((string) $store['id']) ?></td>
                <td><?= htmlspecialchars($store['store_name']) ?></td>
                <td><?= $store['group_name'] !== null ? htmlspecialchars($store['website_name'] . ' / ' . $store['group_name']) : '—' ?></td>
                <td><?= htmlspecialchars($store['webview']) ?></td>
                <td><?= htmlspecialchars($store['theme']) ?></td>
                <td>
                    <a href="/admin/store/edit?id=<?= (int) $store['id'] ?>">Editar</a>
                    <form method="post" action="/admin/store/delete" style="display:inline" data-confirm="Remover esta loja?">
                        <?= \Iter\Core\FormKey::field() ?>
                        <input type="hidden" name="id" value="<?= (int) $store['id'] ?>">
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
