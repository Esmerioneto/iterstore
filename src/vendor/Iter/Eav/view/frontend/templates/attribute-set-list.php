<section class="eav-attribute-set-list">
    <h2>Conjuntos de atributos</h2>
    <p><a href="/admin/attributeset/create<?= $entity_type !== '' ? '?entity_type=' . urlencode($entity_type) : '' ?>">+ Novo conjunto</a></p>
    <form method="get" action="/admin/attributeset/index">
        <select name="entity_type" data-autosubmit>
            <option value="">Todas as entidades</option>
<?php foreach ($entity_types as $code => $type): ?>
            <option value="<?= htmlspecialchars($code) ?>" <?= $entity_type === $code ? 'selected' : '' ?>><?= htmlspecialchars($type['label']) ?></option>
<?php endforeach; ?>
        </select>
        <noscript><button type="submit">Filtrar</button></noscript>
    </form>
    <table>
        <thead>
            <tr>
                <th>Entidade</th>
                <th>Código</th>
                <th>Nome</th>
                <th>Atributos</th>
                <th>Padrão</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
<?php if (empty($sets)): ?>
            <tr><td colspan="6">Nenhum conjunto cadastrado.</td></tr>
<?php else: ?>
    <?php foreach ($sets as $set): ?>
            <tr>
                <td><?= htmlspecialchars((string) $set['entity_type_label']) ?></td>
                <td><?= htmlspecialchars((string) $set['code']) ?></td>
                <td><?= htmlspecialchars((string) $set['label']) ?></td>
                <td><?= (int) $set['attribute_count'] ?></td>
                <td><?= $set['is_default'] ? 'Sim' : '' ?></td>
                <td>
                    <a href="/admin/attributeset/edit?id=<?= (int) $set['attribute_set_id'] ?>">Editar</a>
                    <a href="/admin/attributeset/preview?id=<?= (int) $set['attribute_set_id'] ?>">Pré-visualizar</a>
        <?php if (!$set['is_default']): ?>
                    <form method="post" action="/admin/attributeset/delete" style="display:inline" data-confirm="Remover este conjunto?">
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
</section>
