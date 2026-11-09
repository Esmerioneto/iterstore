<section class="catalog-category-list">
    <div class="admin-page-head">
        <h2>Categorias</h2>
        <a class="admin-button admin-button-primary" href="/admin/category/create">+ Nova categoria</a>
    </div>
    <form method="get" action="/admin/category/index" class="admin-filters">
        <input type="search" name="q" placeholder="Nome ou chave de URL" value="<?= htmlspecialchars($q) ?>">
        <?= \Iter\Admin\View\Sort::hidden() ?>
        <button type="submit">Filtrar</button>
    </form>
    <table>
        <thead>
            <tr>
                <th>Nome</th><?= $gridColumns->th('name') ?>
<?php if (count($websites) > 1): ?>
                <th>Website</th><?= $gridColumns->th('website') ?>
<?php endif; ?>
                <th>Chave de URL</th><?= $gridColumns->th('url_key') ?>
                <th>Posição</th><?= $gridColumns->th('position') ?>
                <th>Status</th><?= $gridColumns->th('status') ?>
                <th>Produtos</th><?= $gridColumns->th('products') ?>
                <th></th>
            </tr>
        </thead>
        <tbody>
<?php if (empty($categories)): ?>
            <tr><td colspan="<?= (count($websites) > 1 ? 7 : 6) + $gridColumns->count() ?>" class="admin-empty">Nenhuma categoria cadastrada.</td></tr>
<?php else: ?>
    <?php foreach ($categories as $category): ?>
            <tr>
                <td style="padding-left: <?= (int) $category['level'] * 1.5 ?>em"><?= $category['level'] ? '└ ' : '' ?><?= htmlspecialchars((string) $category['name']) ?></td><?= $gridColumns->td('name', $category['category_id']) ?>
<?php if (count($websites) > 1): ?>
                <td><?= htmlspecialchars((string) ($websites[$category['website_id']] ?? '')) ?></td><?= $gridColumns->td('website', $category['category_id']) ?>
<?php endif; ?>
                <td><?= htmlspecialchars((string) $category['url_key']) ?></td><?= $gridColumns->td('url_key', $category['category_id']) ?>
                <td><?= (int) $category['position'] ?></td><?= $gridColumns->td('position', $category['category_id']) ?>
                <td><span class="admin-status admin-status-<?= $category['is_active'] ? 'on' : 'off' ?>"><?= $category['is_active'] ? 'Ativa' : 'Inativa' ?></span></td><?= $gridColumns->td('status', $category['category_id']) ?>
                <td><a href="/admin/product/index?category_id=<?= (int) $category['category_id'] ?>"><?= (int) $category['products'] ?></a></td><?= $gridColumns->td('products', $category['category_id']) ?>
                <td class="admin-row-action">
                    <a href="/admin/category/edit?id=<?= (int) $category['category_id'] ?>">Editar</a>
                    <a href="/admin/category/create?parent=<?= (int) $category['category_id'] ?>">+ Subcategoria</a>
                    <form method="post" action="/admin/category/delete" data-confirm="Remover esta categoria?">
                        <?= \Iter\Core\FormKey::field() ?>
                        <input type="hidden" name="category_id" value="<?= (int) $category['category_id'] ?>">
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
