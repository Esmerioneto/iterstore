<?php
$flags = [
    'is_required' => 'Obrigatório',
    'is_visible' => 'Visível',
    'is_searchable' => 'Pesquisável',
    'is_filterable' => 'Usar nos filtros da loja (só Seleção, Opções, Múltipla seleção e Sim/Não)',
    'is_sortable' => 'Ordenável',
    'is_unique' => 'Valor único (não se repete entre registros; só texto, e-mail, CPF/CNPJ e parecidos, e só antes de ter valores)',
];
// on system attributes only AttributeService::editable() can change (enforced server-side too)
$locked = static fn (string $field) => $is_system && !in_array($field, $editable, true) ? 'disabled' : '';
?>
<section class="eav-attribute-form">
    <h2><?= $is_edit ? 'Editar atributo' : 'Novo atributo' ?></h2>
<?php if ($is_fixed): ?>
    <p>Campo de cadastro do sistema: só nome e posição podem ser alterados. Ele tem lugar fixo no formulário e não entra em conjuntos.</p>
<?php elseif ($is_system): ?>
    <p>Atributo do sistema: só nome, posição, visibilidade<?= in_array('is_required', $editable, true) ? ' e obrigatório' : '' ?> podem ser alterados.</p>
<?php endif; ?>
    <form method="post" action="/admin/attribute/save">
        <?= \Iter\Core\FormKey::field() ?>
<?php if ($attribute_id): ?>
        <input type="hidden" name="attribute_id" value="<?= (int) $attribute_id ?>">
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
<?php if (!empty($values['similar'])): ?>
            <br><label><input type="checkbox" name="confirm_similar" value="1"> Salvar mesmo assim (o nome é parecido com outro atributo)</label>
<?php endif; ?>
        </p>
        <p>
            <label>Tipo de campo<br>
                <select name="input_type" required data-options-box="attribute-options" <?= $locked('input_type') ?>>
<?php foreach ($input_types as $code => $type): ?>
                    <option value="<?= htmlspecialchars($code) ?>" data-supports-options="<?= $type['supports_options'] ? 1 : 0 ?>" <?= (string) $values['input_type'] === $code ? 'selected' : '' ?>><?= htmlspecialchars($type['label']) ?></option>
<?php endforeach; ?>
                </select>
            </label>
<?php if ($is_edit): ?>
            <small>Armazenamento: <?= htmlspecialchars((string) $values['storage_type']) ?></small>
<?php endif; ?>
        </p>
        <p>
            <label>Valor padrão<br>
                <input type="text" name="default_value" value="<?= htmlspecialchars((string) $values['default_value']) ?>" <?= $locked('default_value') ?>>
            </label>
        </p>
<?php foreach ($flags as $flag => $label): ?>
        <p>
            <label>
                <input type="checkbox" name="<?= $flag ?>" value="1" <?= $values[$flag] ? 'checked' : '' ?> <?= $locked($flag) ?>>
                <?= $label ?>
            </label>
        </p>
<?php endforeach; ?>
        <fieldset id="attribute-options">
            <legend>Opções</legend>
            <p><small>O valor é o que fica gravado e não deve mudar depois de usado; o nome pode ser alterado à vontade. Linhas em branco são ignoradas.</small></p>
            <table>
                <thead>
                    <tr><th>Valor</th><th>Nome</th><th>Posição</th><th>Padrão</th></tr>
                </thead>
                <tbody id="attribute-option-rows">
<?php foreach (array_values($options) as $i => $option): ?>
                    <tr>
                        <td><input type="text" name="options[<?= $i ?>][value]" maxlength="255" value="<?= htmlspecialchars((string) $option['value']) ?>" aria-label="Valor"></td>
                        <td><input type="text" name="options[<?= $i ?>][label]" maxlength="255" value="<?= htmlspecialchars((string) $option['label']) ?>" aria-label="Nome"></td>
                        <td><input type="text" inputmode="numeric" name="options[<?= $i ?>][sort_order]" size="4" value="<?= htmlspecialchars((string) $option['sort_order']) ?>" aria-label="Posição"></td>
                        <td><input type="checkbox" name="options[<?= $i ?>][is_default]" value="1" <?= !empty($option['is_default']) ? 'checked' : '' ?> aria-label="Padrão"></td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
            <button type="button" data-add-row="attribute-option-rows">+ Adicionar opção</button>
            <p>
                <label><input type="checkbox" name="is_variant" value="1" <?= $values['is_variant'] ? 'checked' : '' ?> <?= $locked('is_variant') ?>> Usar na grade</label><br>
                <small>Só no tipo Seleção: o atributo pode formar as variações de um produto com grade (ex.: Cor, Tamanho, Voltagem).</small>
            </p>
        </fieldset>
        <p>
            <label>Posição<br>
                <input type="text" inputmode="numeric" name="sort_order" value="<?= (int) $values['sort_order'] ?>">
            </label>
        </p>
        <p>
            <button type="submit" class="admin-button-primary">Salvar</button>
            <a href="/admin/attribute/index">Cancelar</a>
        </p>
    </form>
</section>
