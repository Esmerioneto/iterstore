<section class="inventory-source-list">
    <div class="admin-page-head">
        <h2>Origens de estoque</h2>
        <a class="admin-button admin-button-primary" href="/admin/source/create">+ Nova origem</a>
    </div>
    <form method="get" action="/admin/source/index" class="admin-filters">
        <select name="is_enabled">
            <option value="">Ativas e inativas</option>
            <option value="1" <?= $is_enabled === '1' ? 'selected' : '' ?>>Só ativas</option>
            <option value="0" <?= $is_enabled === '0' ? 'selected' : '' ?>>Só inativas</option>
        </select>
        <input type="search" name="q" placeholder="Código, nome ou cidade" value="<?= htmlspecialchars($q) ?>">
        <?= \Iter\Admin\View\Sort::hidden() ?>
        <button type="submit">Filtrar</button>
    </form>
    <table>
        <thead>
            <tr>
<?php if (count($websites) > 1): ?>
                <?= \Iter\Admin\View\Sort::th('website', 'Website', $sort, $dir) ?>
<?php endif; ?>
                <?= \Iter\Admin\View\Sort::th('code', 'Código', $sort, $dir) ?>
                <?= \Iter\Admin\View\Sort::th('name', 'Nome', $sort, $dir) ?>
                <?= \Iter\Admin\View\Sort::th('city', 'Cidade', $sort, $dir) ?>
                <?= \Iter\Admin\View\Sort::th('is_enabled', 'Situação', $sort, $dir) ?>
                <?= \Iter\Admin\View\Sort::th('priority', 'Prioridade', $sort, $dir) ?>
                <?= \Iter\Admin\View\Sort::th('products', 'Produtos com estoque', $sort, $dir) ?>
                <th></th>
            </tr>
        </thead>
        <tbody>
<?php foreach ($sources as $source): ?>
            <tr>
<?php if (count($websites) > 1): ?>
                <td><?= htmlspecialchars((string) ($websites[$source['website_id']] ?? '')) ?></td>
<?php endif; ?>
                <td><?= htmlspecialchars((string) $source['code']) ?></td>
                <td><?= htmlspecialchars((string) $source['name']) ?></td>
                <td><?= htmlspecialchars(implode(' / ', array_filter([(string) $source['city'], (string) $source['region']]))) ?></td>
                <td><span class="admin-status admin-status-<?= $source['is_enabled'] ? 'on' : 'off' ?>"><?= $source['is_enabled'] ? 'Ativa' : 'Inativa' ?></span></td>
                <td><?= (int) $source['priority'] ?></td>
                <td><?= (int) $source['products'] ?></td>
                <td class="admin-row-action">
                    <a href="/admin/source/edit?id=<?= (int) $source['source_id'] ?>">Editar</a>
    <?php if ($source['code'] !== \Iter\Inventory\Model\Source::DEFAULT_CODE): ?>
                    <form method="post" action="/admin/source/delete" data-confirm="Remover esta origem?">
                        <?= \Iter\Core\FormKey::field() ?>
                        <input type="hidden" name="source_id" value="<?= (int) $source['source_id'] ?>">
                        <button type="submit">Remover</button>
                    </form>
    <?php endif; ?>
                </td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
    <?php \Iter\Admin\View\Pager::render($pager); ?>
</section>
