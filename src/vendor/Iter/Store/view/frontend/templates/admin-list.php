<section class="store-admin-list">
    <div class="admin-page-head">
        <h2>Lojas</h2>
        <a class="admin-button admin-button-primary" href="/admin/store/create">+ Nova loja</a>
    </div>
    <form method="get" action="/admin/store/index" class="admin-filters">
        <input type="search" name="q" placeholder="Nome, website, webview ou tema" value="<?= htmlspecialchars($q) ?>">
        <?= \Iter\Admin\View\Sort::hidden() ?>
        <button type="submit">Filtrar</button>
    </form>
    <table>
        <thead>
            <tr>
                <?= \Iter\Admin\View\Sort::th('id', 'ID', $sort, $dir) ?>
                <?= \Iter\Admin\View\Sort::th('name', 'Nome', $sort, $dir) ?>
                <?= \Iter\Admin\View\Sort::th('website', 'Website / Grupo', $sort, $dir) ?>
                <?= \Iter\Admin\View\Sort::th('webview', 'Webview', $sort, $dir) ?>
                <?= \Iter\Admin\View\Sort::th('theme', 'Tema', $sort, $dir) ?>
                <th></th>
            </tr>
        </thead>
        <tbody>
<?php if (empty($stores)): ?>
            <tr><td colspan="6" class="admin-empty">Nenhuma loja cadastrada.</td></tr>
<?php else: ?>
    <?php foreach ($stores as $store): ?>
            <tr>
                <td><?= htmlspecialchars((string) $store['id']) ?></td>
                <td><?= htmlspecialchars($store['store_name']) ?></td>
                <td><?= $store['group_name'] !== null ? htmlspecialchars($store['website_name'] . ' / ' . $store['group_name']) : '—' ?></td>
                <td><?= htmlspecialchars($store['webview']) ?></td>
                <td><?= htmlspecialchars($store['theme']) ?></td>
                <td class="admin-row-action">
                    <a href="/admin/store/edit?id=<?= (int) $store['id'] ?>">Editar</a>
                    <form method="post" action="/admin/store/delete" data-confirm="Remover esta loja?">
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
