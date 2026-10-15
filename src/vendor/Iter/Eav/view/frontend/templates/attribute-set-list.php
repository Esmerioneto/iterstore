<section class="eav-attribute-set-list">
    <div class="admin-page-head">
        <h2>Conjuntos de atributos</h2>
        <a class="admin-button admin-button-primary" href="/admin/attributeset/create<?= $entity_type !== '' ? '?entity_type=' . urlencode($entity_type) : '' ?>">+ Novo conjunto</a>
    </div>
    <form method="get" action="/admin/attributeset/index" class="admin-filters">
        <select name="entity_type" data-autosubmit>
            <option value="">Todas as entidades</option>
<?php foreach ($entity_types as $code => $type): ?>
            <option value="<?= htmlspecialchars($code) ?>" <?= $entity_type === $code ? 'selected' : '' ?>><?= htmlspecialchars($type['label']) ?></option>
<?php endforeach; ?>
        </select>
        <input type="search" name="q" placeholder="Código ou nome" value="<?= htmlspecialchars($q) ?>">
        <?= \Iter\Admin\View\Sort::hidden() ?>
        <button type="submit">Filtrar</button>
    </form>
    <table>
        <thead>
            <tr>
                <?= \Iter\Admin\View\Sort::th('entity', 'Entidade', $sort, $dir) ?>
                <?= \Iter\Admin\View\Sort::th('code', 'Código', $sort, $dir) ?>
                <?= \Iter\Admin\View\Sort::th('name', 'Nome', $sort, $dir) ?>
                <?= \Iter\Admin\View\Sort::th('attributes', 'Atributos', $sort, $dir) ?>
                <th>Padrão</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
<?php if (empty($sets)): ?>
            <tr><td colspan="6" class="admin-empty">Nenhum conjunto cadastrado.</td></tr>
<?php else: ?>
    <?php foreach ($sets as $set): ?>
            <tr>
                <td><?= htmlspecialchars((string) $set['entity_type_label']) ?></td>
                <td><?= htmlspecialchars((string) $set['code']) ?></td>
                <td><?= htmlspecialchars((string) $set['label']) ?></td>
                <td><?= (int) $set['attribute_count'] ?></td>
                <td><?= $set['is_default'] ? 'Sim' : '' ?></td>
                <td class="admin-row-action">
                    <a href="/admin/attributeset/edit?id=<?= (int) $set['attribute_set_id'] ?>">Editar</a>
                    <a href="/admin/attributeset/preview?id=<?= (int) $set['attribute_set_id'] ?>">Pré-visualizar</a>
        <?php if (!$set['is_default']): ?>
                    <form method="post" action="/admin/attributeset/delete" data-confirm="Remover este conjunto?">
                        <?= \Iter\Core\FormKey::field() ?>
                        <input type="hidden" name="attribute_set_id" value="<?= (int) $set['attribute_set_id'] ?>">
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
