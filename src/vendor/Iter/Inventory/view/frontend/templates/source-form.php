<section class="inventory-source-form">
    <h2><?= $is_edit ? 'Editar origem' : 'Nova origem' ?></h2>
    <form method="post" action="/admin/source/save">
        <?= \Iter\Core\FormKey::field() ?>
<?php if ($source_id): ?>
        <input type="hidden" name="source_id" value="<?= (int) $source_id ?>">
<?php endif; ?>
        <p>
            <label>Website *<br>
<?php if ($is_edit): ?>
                <input type="text" value="<?= htmlspecialchars((string) ($websites[$values['website_id']] ?? '')) ?>" readonly>
<?php else: ?>
                <select name="website_id" required>
    <?php foreach ($websites as $websiteId => $websiteName): ?>
                    <option value="<?= (int) $websiteId ?>" <?= (string) $values['website_id'] === (string) $websiteId ? 'selected' : '' ?>><?= htmlspecialchars((string) $websiteName) ?></option>
    <?php endforeach; ?>
                </select>
<?php endif; ?>
            </label>
            <small><?= $is_edit ? 'O website não muda depois de criada.' : 'A origem só guarda estoque dos produtos deste website.' ?></small>
        </p>
        <p>
            <label>Código *<br>
<?php if ($is_edit): ?>
                <input type="text" value="<?= htmlspecialchars((string) $values['code']) ?>" readonly>
<?php else: ?>
                <input type="text" name="code" maxlength="64" pattern="[a-z][a-z0-9_]*" value="<?= htmlspecialchars((string) $values['code']) ?>" required>
<?php endif; ?>
            </label>
<?php if ($is_edit): ?>
            <small>O código não muda depois de criado.</small>
<?php endif; ?>
        </p>
        <p>
            <label>Nome *<br>
                <input type="text" name="name" maxlength="255" value="<?= htmlspecialchars((string) $values['name']) ?>" required>
            </label>
        </p>
        <p>
            <label>Prioridade<br>
                <input type="number" name="priority" step="1" value="<?= (int) $values['priority'] ?>">
            </label>
            <small>Menor número = usada primeiro na separação.</small>
        </p>
        <p class="inventory-source-full">
<?php if ($is_default): ?>
            <input type="hidden" name="is_enabled" value="1">
            <label><input type="checkbox" checked disabled> Ativa</label>
            <small>A origem padrão está sempre ativa.</small>
<?php else: ?>
            <label><input type="checkbox" name="is_enabled" value="1" <?= $values['is_enabled'] ? 'checked' : '' ?>> Ativa</label>
            <small>Inativa, a origem sai do saldo vendável, mas as quantidades ficam guardadas.</small>
<?php endif; ?>
        </p>
        <p class="inventory-source-full">
            <label>Descrição<br>
                <textarea name="description" rows="3"><?= htmlspecialchars((string) $values['description']) ?></textarea>
            </label>
        </p>
        <fieldset>
            <legend>Endereço</legend>
            <p>
                <label>CEP<br>
                    <input type="text" name="postcode" maxlength="16" value="<?= htmlspecialchars((string) $values['postcode']) ?>">
                </label>
            </p>
            <p>
                <label>Cidade<br>
                    <input type="text" name="city" maxlength="128" value="<?= htmlspecialchars((string) $values['city']) ?>">
                </label>
            </p>
            <p>
                <label>Estado<br>
                    <input type="text" name="region" maxlength="128" value="<?= htmlspecialchars((string) $values['region']) ?>">
                </label>
            </p>
            <p>
                <label>País<br>
                    <input type="text" name="country_code" maxlength="2" pattern="[A-Za-z]{2}" value="<?= htmlspecialchars((string) $values['country_code']) ?>">
                </label>
            </p>
        </fieldset>
        <p class="inventory-source-full inventory-source-actions">
            <button type="submit">Salvar</button>
            <a href="/admin/source/index">Cancelar</a>
        </p>
    </form>
</section>
