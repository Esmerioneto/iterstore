<section class="catalog-category-list">
    <h2>Categorias</h2>
    <p><a href="/admin/category/create">+ Nova categoria</a></p>
    <table>
        <thead>
            <tr>
                <th>Nome</th>
<?php if (count($websites) > 1): ?>
                <th>Website</th>
<?php endif; ?>
                <th>Chave de URL</th>
                <th>Posição</th>
                <th>Status</th>
                <th>Produtos</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
<?php if (empty($categories)): ?>
            <tr><td colspan="<?= count($websites) > 1 ? 7 : 6 ?>">Nenhuma categoria cadastrada.</td></tr>
<?php else: ?>
    <?php foreach ($categories as $category): ?>
            <tr>
                <td style="padding-left: <?= (int) $category['level'] * 1.5 ?>em"><?= $category['level'] ? '└ ' : '' ?><?= htmlspecialchars((string) $category['name']) ?></td>
<?php if (count($websites) > 1): ?>
                <td><?= htmlspecialchars((string) ($websites[$category['website_id']] ?? '')) ?></td>
<?php endif; ?>
                <td><?= htmlspecialchars((string) $category['url_key']) ?></td>
                <td><?= (int) $category['position'] ?></td>
                <td><?= $category['is_active'] ? 'Ativa' : 'Inativa' ?></td>
                <td><a href="/admin/product/index?category_id=<?= (int) $category['category_id'] ?>"><?= (int) $category['products'] ?></a></td>
                <td>
                    <a href="/admin/category/edit?id=<?= (int) $category['category_id'] ?>">Editar</a>
                    <a href="/admin/category/create?parent=<?= (int) $category['category_id'] ?>">+ Subcategoria</a>
                    <form method="post" action="/admin/category/delete" style="display:inline" data-confirm="Remover esta categoria?">
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
</section>
