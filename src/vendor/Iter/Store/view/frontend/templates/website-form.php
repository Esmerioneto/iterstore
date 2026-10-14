<section class="store-website-form">
    <h2><?= $is_edit ? 'Editar website' : 'Novo website' ?></h2>
    <form method="post" action="/admin/website/save">
        <?= \Iter\Core\FormKey::field() ?>
<?php if ($website_id): ?>
        <input type="hidden" name="website_id" value="<?= (int) $website_id ?>">
<?php endif; ?>
        <p>
            <label>Código<br>
                <input type="text" name="code" maxlength="32" pattern="[a-z][a-z0-9_]*" value="<?= htmlspecialchars((string) $values['code']) ?>" required>
            </label>
        </p>
        <p>
            <label>Nome<br>
                <input type="text" name="name" maxlength="64" value="<?= htmlspecialchars((string) $values['name']) ?>" required>
            </label>
        </p>
        <p>
            <label>Ordem<br>
                <input type="text" inputmode="numeric" name="sort_order" value="<?= (int) $values['sort_order'] ?>">
            </label>
        </p>
        <p>
            <label>
                <input type="checkbox" name="is_default" value="1" <?= $values['is_default'] ? 'checked' : '' ?>>
                Website padrão
            </label>
        </p>
        <p>
            <button type="submit" class="admin-button-primary">Salvar</button>
            <a href="/admin/website/index">Cancelar</a>
        </p>
    </form>
</section>
