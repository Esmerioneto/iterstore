<section class="eav-attribute-set-form">
    <h2><?= $is_edit ? 'Editar conjunto' : 'Novo conjunto' ?></h2>
    <form method="post" action="/admin/attributeset/save">
        <?= \Iter\Core\FormKey::field() ?>
<?php if ($attribute_set_id): ?>
        <input type="hidden" name="attribute_set_id" value="<?= (int) $attribute_set_id ?>">
<?php endif; ?>
        <p>
            <label>Entidade<br>
<?php if ($is_edit): ?>
                <input type="text" value="<?= htmlspecialchars((string) ($entity_types[$values['entity_type']]['label'] ?? $values['entity_type'])) ?>" readonly>
<?php else: ?>
                <select name="entity_type" required>
                    <option value="">Selecione</option>
    <?php foreach ($entity_types as $code => $type): ?>
                    <option value="<?= htmlspecialchars($code) ?>" <?= (string) $values['entity_type'] === $code ? 'selected' : '' ?>><?= htmlspecialchars($type['label']) ?></option>
    <?php endforeach; ?>
                </select>
<?php endif; ?>
            </label>
        </p>
        <p>
            <label>Código<br>
                <input type="text" name="code" maxlength="100" pattern="[a-z][a-z0-9_]*" value="<?= htmlspecialchars((string) $values['code']) ?>" <?= $is_edit ? 'readonly' : 'required' ?>>
            </label>
        </p>
        <p>
            <label>Nome<br>
                <input type="text" name="label" maxlength="255" value="<?= htmlspecialchars((string) $values['label']) ?>" required>
            </label>
        </p>
        <p>
            <label>
<?php if ($is_edit && $values['is_default']): ?>
                <input type="checkbox" checked disabled>
                Conjunto padrão (para trocar, marque outro conjunto como padrão)
<?php else: ?>
                <input type="checkbox" name="is_default" value="1" <?= $values['is_default'] ? 'checked' : '' ?>>
                Conjunto padrão da entidade
<?php endif; ?>
            </label>
        </p>
<?php if ($is_edit): ?>
        <fieldset>
            <legend>Atributos</legend>
    <?php if (empty($rows)): ?>
            <p>Esta entidade ainda não tem atributos. <a href="/admin/attribute/create">Criar atributo</a></p>
    <?php else: ?>
            <p><small>Marque os atributos do conjunto. Atributos com o mesmo grupo aparecem juntos; os grupos seguem a menor posição dos seus atributos e os atributos sem grupo vêm por último.</small></p>
            <datalist id="attribute-set-groups">
        <?php foreach ($groups as $group): ?>
                <option value="<?= htmlspecialchars($group) ?>">
        <?php endforeach; ?>
            </datalist>
            <table>
                <thead>
                    <tr><th>No conjunto</th><th>Atributo</th><th>Tipo</th><th>Grupo</th><th>Posição</th></tr>
                </thead>
                <tbody>
        <?php foreach ($rows as $attributeId => $row): ?>
                    <tr>
                        <td><input type="checkbox" name="items[<?= (int) $attributeId ?>][in]" value="1" <?= $row['in'] ? 'checked' : '' ?> aria-label="No conjunto"></td>
                        <td><?= htmlspecialchars((string) $row['label']) ?> <small>(<?= htmlspecialchars((string) $row['code']) ?>)</small></td>
                        <td><?= htmlspecialchars((string) ($input_types[$row['input_type']]['label'] ?? $row['input_type'])) ?></td>
                        <td><input type="text" name="items[<?= (int) $attributeId ?>][group]" maxlength="100" list="attribute-set-groups" value="<?= htmlspecialchars((string) $row['group']) ?>" aria-label="Grupo"></td>
                        <td><input type="text" inputmode="numeric" name="items[<?= (int) $attributeId ?>][sort_order]" size="4" value="<?= htmlspecialchars((string) $row['sort_order']) ?>" aria-label="Posição"></td>
                    </tr>
        <?php endforeach; ?>
                </tbody>
            </table>
    <?php endif; ?>
        </fieldset>
<?php endif; ?>
        <p>
            <button type="submit" class="admin-button-primary">Salvar</button>
            <a href="/admin/attributeset/index">Cancelar</a>
<?php if ($is_edit): ?>
            <a href="/admin/attributeset/preview?id=<?= (int) $attribute_set_id ?>">Pré-visualizar formulário</a>
<?php endif; ?>
        </p>
    </form>
</section>
