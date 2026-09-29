<?php $extra = \Iter\Catalog\View\ProductList::columns($columns ?? [], $products); ?>
<section class="catalog-product-list">
    <h2>Produtos</h2>
    <p><a href="/admin/product/create">+ Novo produto</a></p>
    <form method="get" action="/admin/product/index">
<?php if (count($websites) > 1): ?>
        <select name="website_id">
            <option value="">Todos os websites</option>
    <?php foreach ($websites as $websiteId => $websiteName): ?>
            <option value="<?= (int) $websiteId ?>" <?= $filters['website_id'] === (string) $websiteId ? 'selected' : '' ?>><?= htmlspecialchars((string) $websiteName) ?></option>
    <?php endforeach; ?>
        </select>
<?php endif; ?>
        <select name="attribute_set_id">
            <option value="">Todos os conjuntos</option>
<?php foreach ($sets as $setId => $set): ?>
            <option value="<?= (int) $setId ?>" <?= $filters['attribute_set_id'] === (string) $setId ? 'selected' : '' ?>><?= htmlspecialchars((string) $set['label']) ?></option>
<?php endforeach; ?>
        </select>
        <select name="category_id">
            <option value="">Todas as categorias</option>
<?php foreach ($categories as $category): ?>
            <option value="<?= (int) $category['category_id'] ?>" <?= $filters['category_id'] === (string) $category['category_id'] ? 'selected' : '' ?>><?= str_repeat('— ', (int) $category['level']) . htmlspecialchars((string) $category['name']) ?></option>
<?php endforeach; ?>
        </select>
        <select name="is_active">
            <option value="">Ativos e inativos</option>
            <option value="1" <?= $filters['is_active'] === '1' ? 'selected' : '' ?>>Só ativos</option>
            <option value="0" <?= $filters['is_active'] === '0' ? 'selected' : '' ?>>Só inativos</option>
        </select>
        <input type="search" name="q" placeholder="SKU ou nome" value="<?= htmlspecialchars($filters['q']) ?>">
        <button type="submit">Filtrar</button>
    </form>
    <table>
        <thead>
            <tr>
                <th>SKU</th>
                <th>Nome</th>
                <th>Conjunto</th>
                <th>Preço</th>
                <th>Status</th>
<?php foreach ($extra as $column): ?>
                <th><?= htmlspecialchars($column['label']) ?></th>
<?php endforeach; ?>
                <th></th>
            </tr>
        </thead>
        <tbody>
<?php if (empty($products)): ?>
            <tr><td colspan="<?= 6 + count($extra) ?>">Nenhum produto encontrado.</td></tr>
<?php else: ?>
    <?php foreach ($products as $product): ?>
            <tr>
                <td><?= htmlspecialchars((string) $product['sku']) ?></td>
                <td><?= htmlspecialchars((string) $product['name']) ?></td>
                <td><?= htmlspecialchars((string) $product['set_label']) ?></td>
                <td><?= $product['price'] === null ? '' : 'R$ ' . number_format((float) $product['price'], 2, ',', '.') ?></td>
                <td><?= $product['is_active'] ? 'Ativo' : 'Inativo' ?></td>
        <?php foreach ($extra as $column): ?>
                <td><?= htmlspecialchars((string) ($column['values'][(int) $product['product_id']] ?? '')) ?></td>
        <?php endforeach; ?>
                <td>
                    <a href="/admin/product/edit?id=<?= (int) $product['product_id'] ?>">Editar</a>
                    <form method="post" action="/admin/product/delete" style="display:inline" data-confirm="Remover este produto?">
                        <?= \Iter\Core\FormKey::field() ?>
                        <input type="hidden" name="product_id" value="<?= (int) $product['product_id'] ?>">
                        <button type="submit">Remover</button>
                    </form>
                </td>
            </tr>
    <?php endforeach; ?>
<?php endif; ?>
        </tbody>
    </table>
<?php if ($total_pages > 1): ?>
    <p>
    <?php for ($p = 1; $p <= $total_pages; $p++): ?>
        <?php if ($p === $page): ?>
        <strong><?= $p ?></strong>
        <?php else: ?>
        <a href="/admin/product/index?<?= htmlspecialchars(http_build_query(array_filter($filters, 'strlen') + ['page' => $p])) ?>"><?= $p ?></a>
        <?php endif; ?>
    <?php endfor; ?>
        (<?= (int) $total ?> produtos)
    </p>
<?php endif; ?>
</section>
