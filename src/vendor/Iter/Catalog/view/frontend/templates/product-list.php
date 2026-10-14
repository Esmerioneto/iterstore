<?php
$extra = \Iter\Catalog\View\ProductList::columns($columns ?? [], $products);
usort($extra, static fn ($a, $b) => $b['sortable'] <=> $a['sortable']); // header order: sortable ones next to the list's own
// what every link and form of the list carries: filters, page size and sort (defaults left out)
$query = array_filter($filters, 'strlen')
    + ($limit === \Iter\Catalog\View\ProductList::PAGE_SIZES[0] ? [] : ['limit' => $limit])
    + ($sort === 'name' && $dir === 'asc' ? [] : ['sort' => $sort, 'dir' => $dir]);
$sortHeader = static fn (string $key, string $label): string => \Iter\Admin\View\Sort::th($key, $label, $sort, $dir, $query, '/admin/product/index');
?>
<section class="catalog-product-list">
    <div class="admin-page-head">
        <h2>Produtos</h2>
        <a class="admin-button admin-button-primary" href="/admin/product/create">+ Novo produto</a>
    </div>
    <form method="get" action="/admin/product/index" class="admin-filters">
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
<?php foreach (array_diff_key($query, $filters) as $name => $value): ?>
        <input type="hidden" name="<?= htmlspecialchars($name) ?>" value="<?= htmlspecialchars((string) $value) ?>">
<?php endforeach; ?>
        <button type="submit">Filtrar</button>
    </form>
    <table>
        <thead>
            <tr>
<?php foreach (['sku' => 'SKU', 'name' => 'Nome', 'set' => 'Conjunto', 'categories' => 'Categorias', 'price' => 'Preço', 'is_active' => 'Status'] + array_column(array_filter($extra, static fn ($c) => $c['sortable']), 'label', 'key') as $key => $label): ?>
                <?= $sortHeader($key, $label) ?>
<?php endforeach; ?>
<?php foreach (array_filter($extra, static fn ($c) => !$c['sortable']) as $column): ?>
                <th><?= htmlspecialchars($column['label']) ?></th>
<?php endforeach; ?>
                <th></th>
            </tr>
        </thead>
        <tbody>
<?php if (empty($products)): ?>
            <tr><td colspan="<?= 7 + count($extra) ?>" class="admin-empty">Nenhum produto encontrado.</td></tr>
<?php else: ?>
    <?php foreach ($products as $product): ?>
            <tr>
                <td><?= htmlspecialchars((string) $product['sku']) ?></td>
                <td><?= htmlspecialchars((string) $product['name']) ?><?= $product['type'] === 'configurable' ? ' <small>(' . (int) $product['variant_count'] . ' variações)</small>' : '' ?></td>
                <td><?= htmlspecialchars((string) $product['set_label']) ?></td>
                <td><?= htmlspecialchars(implode(', ', $product_categories[(int) $product['product_id']] ?? [])) ?></td>
                <td><?= implode(' – ', array_map(static fn ($p) => 'R$ ' . number_format((float) $p, 2, ',', '.'), array_unique(array_filter($product['type'] === 'configurable' ? [$product['price_min'], $product['price_max']] : [$product['price']], static fn ($p) => $p !== null)))) ?></td>
                <td><span class="admin-status admin-status-<?= $product['is_active'] ? 'on' : 'off' ?>"><?= $product['is_active'] ? 'Ativo' : 'Inativo' ?></span></td>
        <?php foreach ($extra as $column): ?>
                <td><?= htmlspecialchars((string) ($column['values'][(int) $product['product_id']] ?? '')) ?></td>
        <?php endforeach; ?>
                <td class="admin-row-action">
                    <a href="/admin/product/edit?id=<?= (int) $product['product_id'] ?>">Editar</a>
                    <form method="post" action="/admin/product/delete" data-confirm="Remover este produto?">
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
<?php
?>
    <?php \Iter\Admin\View\Pager::render($pager); ?>
</section>
