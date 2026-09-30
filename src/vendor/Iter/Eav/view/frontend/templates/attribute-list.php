<section class="eav-attribute-list">
    <h2>Atributos</h2>
<?php if ($can_manage): ?>
    <p><a href="/admin/attribute/create<?= $filters['entity_type'] !== '' ? '?entity_type=' . urlencode($filters['entity_type']) : '' ?>">+ Novo atributo</a></p>
<?php endif; ?>
    <form method="get" action="/admin/attribute/index">
        <select name="entity_type">
            <option value="">Todas as entidades</option>
<?php foreach ($entity_types as $code => $type): ?>
            <option value="<?= htmlspecialchars($code) ?>" <?= $filters['entity_type'] === $code ? 'selected' : '' ?>><?= htmlspecialchars($type['label']) ?></option>
<?php endforeach; ?>
        </select>
        <select name="input_type">
            <option value="">Todos os tipos</option>
<?php foreach ($input_types as $code => $type): ?>
            <option value="<?= htmlspecialchars($code) ?>" <?= $filters['input_type'] === $code ? 'selected' : '' ?>><?= htmlspecialchars($type['label']) ?></option>
<?php endforeach; ?>
        </select>
        <select name="is_system">
            <option value="">Sistema e personalizados</option>
            <option value="1" <?= $filters['is_system'] === '1' ? 'selected' : '' ?>>Só do sistema</option>
            <option value="0" <?= $filters['is_system'] === '0' ? 'selected' : '' ?>>Só personalizados</option>
        </select>
        <input type="search" name="q" placeholder="Código ou nome" value="<?= htmlspecialchars($filters['q']) ?>">
        <button type="submit">Filtrar</button>
    </form>
    <table>
        <thead>
            <tr>
                <th>Código</th>
                <th>Nome</th>
                <th>Entidade</th>
                <th>Tipo</th>
                <th>Armazenamento</th>
                <th>Sistema</th>
                <th>Posição</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
<?php if (empty($attributes)): ?>
            <tr><td colspan="8">Nenhum atributo encontrado.</td></tr>
<?php else: ?>
    <?php foreach ($attributes as $attribute): ?>
            <tr>
                <td><?= htmlspecialchars((string) $attribute['code']) ?></td>
                <td><?= htmlspecialchars((string) $attribute['label']) ?></td>
                <td><?= htmlspecialchars((string) $attribute['entity_type_label']) ?></td>
                <td><?= htmlspecialchars((string) ($input_types[$attribute['input_type']]['label'] ?? $attribute['input_type'])) ?></td>
                <td><?= htmlspecialchars((string) $attribute['storage_type']) ?></td>
                <td><?= $attribute['is_system'] ? 'Sim' : '' ?></td>
                <td><?= (int) $attribute['sort_order'] ?></td>
                <td>
                    <a href="/admin/attribute/edit?id=<?= (int) $attribute['attribute_id'] ?>">Editar</a>
        <?php if ($can_manage && !$attribute['is_system']): ?>
                    <form method="post" action="/admin/attribute/delete" style="display:inline" data-confirm="Remover este atributo?">
                        <?= \Iter\Core\FormKey::field() ?>
                        <input type="hidden" name="attribute_id" value="<?= (int) $attribute['attribute_id'] ?>">
                        <button type="submit">Remover</button>
                    </form>
        <?php endif; ?>
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
        <a href="/admin/attribute/index?<?= htmlspecialchars(http_build_query(array_filter($filters, 'strlen') + ['page' => $p])) ?>"><?= $p ?></a>
        <?php endif; ?>
    <?php endfor; ?>
        (<?= (int) $total ?> atributos)
    </p>
<?php endif; ?>
</section>
