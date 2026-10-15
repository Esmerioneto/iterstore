<section class="store-website-list">
    <div class="admin-page-head">
        <h2>Websites</h2>
        <a class="admin-button admin-button-primary" href="/admin/website/create">+ Novo website</a>
    </div>
    <form method="get" action="/admin/website/index" class="admin-filters">
        <input type="search" name="q" placeholder="Código ou nome" value="<?= htmlspecialchars($q) ?>">
        <?= \Iter\Admin\View\Sort::hidden() ?>
        <button type="submit">Filtrar</button>
    </form>
    <table>
        <thead>
            <tr>
                <?= \Iter\Admin\View\Sort::th('id', 'ID', $sort, $dir) ?>
                <?= \Iter\Admin\View\Sort::th('code', 'Código', $sort, $dir) ?>
                <?= \Iter\Admin\View\Sort::th('name', 'Nome', $sort, $dir) ?>
                <?= \Iter\Admin\View\Sort::th('sort_order', 'Ordem', $sort, $dir) ?>
                <th>Padrão</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
<?php if (empty($websites)): ?>
            <tr><td colspan="6" class="admin-empty">Nenhum website cadastrado.</td></tr>
<?php else: ?>
    <?php foreach ($websites as $website): ?>
            <tr>
                <td><?= (int) $website['website_id'] ?></td>
                <td><?= htmlspecialchars((string) $website['code']) ?></td>
                <td><?= htmlspecialchars((string) $website['name']) ?></td>
                <td><?= (int) $website['sort_order'] ?></td>
                <td><?= $website['is_default'] ? 'Sim' : '' ?></td>
                <td class="admin-row-action">
                    <a href="/admin/website/edit?id=<?= (int) $website['website_id'] ?>">Editar</a>
                    <form method="post" action="/admin/website/delete" data-confirm="Remover este website e seus grupos?">
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
    <?php \Iter\Admin\View\Pager::render($pager); ?>
</section>
