<section class="customer-group-form">
    <h2><?= $is_edit ? 'Editar grupo de clientes' : 'Novo grupo de clientes' ?></h2>
    <form method="post" action="/admin/customergroup/save">
        <?= \Iter\Core\FormKey::field() ?>
<?php if ($customer_group_id): ?>
        <input type="hidden" name="customer_group_id" value="<?= (int) $customer_group_id ?>">
<?php endif; ?>
        <p>
            <label>Código<br>
                <input type="text" name="code" maxlength="32" pattern="[a-z][a-z0-9_]*" value="<?= htmlspecialchars((string) $values['code']) ?>" required>
            </label>
        </p>
        <p>
            <label>Nome<br>
                <input type="text" name="name" maxlength="255" value="<?= htmlspecialchars((string) $values['name']) ?>" required>
            </label>
        </p>
        <p>
            <button type="submit" class="admin-button-primary">Salvar</button>
            <a href="/admin/customergroup/index">Cancelar</a>
        </p>
    </form>
</section>
