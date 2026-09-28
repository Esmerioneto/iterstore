<?php if ($product_id): ?>
<section class="inventory-stock-panel">
    <h2>Estoque</h2>
    <p>Saldo vendável: <strong><?= htmlspecialchars($salable) ?></strong></p>
    <form method="post" action="/admin/stock/save">
        <?= \Iter\Core\FormKey::field() ?>
        <input type="hidden" name="product_id" value="<?= (int) $product_id ?>">
        <table>
            <thead>
                <tr>
                    <th>Origem</th>
                    <th>Quantidade</th>
                    <th>Situação</th>
                </tr>
            </thead>
            <tbody>
    <?php foreach ($items as $item): $name = 'stock[' . htmlspecialchars((string) $item['code']) . ']'; ?>
                <tr>
                    <td>
                        <?= htmlspecialchars((string) $item['name']) ?>
                        <?= $item['is_enabled'] ? '' : '<small>(origem inativa, fora do saldo)</small>' ?>
                    </td>
                    <td><input type="text" inputmode="decimal" name="<?= $name ?>[quantity]" value="<?= htmlspecialchars($item['quantity']) ?>" pattern="\d+([.,]\d{1,4})?"></td>
                    <td>
                        <select name="<?= $name ?>[status]">
                            <option value="1" <?= $item['status'] ? 'selected' : '' ?>>Em estoque</option>
                            <option value="0" <?= $item['status'] ? '' : 'selected' ?>>Fora de estoque</option>
                        </select>
                    </td>
                </tr>
    <?php endforeach; ?>
            </tbody>
        </table>
        <p><button type="submit">Salvar estoque</button></p>
    </form>
</section>
<?php endif; ?>
