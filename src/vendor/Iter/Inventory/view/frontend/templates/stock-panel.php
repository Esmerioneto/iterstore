<?php if ($product_id && !empty($grid)): $e = static fn ($v) => htmlspecialchars((string) $v); $sum = static fn (array $cells) => str_replace('.', ',', (string) round(array_sum(array_map(static fn ($c) => (float) $c['quantity'], $cells)), 4)); ?>
<section class="inventory-stock-panel">
    <h2>Estoque por grade</h2>
    <p>Saldo vendável (soma das variações ativas): <strong><?= $e($salable) ?></strong></p>
    <?php if (!$sources): ?>
    <p>Os websites do produto não têm origens de estoque: cadastre uma em Estoque → Origens.</p>
    <?php elseif (!$rows): ?>
    <p>Gere as variações no bloco "Grade" para lançar o estoque.</p>
    <?php else: ?>
    <form method="get" action="/admin/product/edit">
        <input type="hidden" name="id" value="<?= (int) $product_id ?>">
        <label>Origem
            <select name="source" data-autosubmit>
        <?php foreach ($sources as $code => $name): ?>
                <option value="<?= $e($code) ?>" <?= $code === $source ? 'selected' : '' ?>><?= $e($name) ?></option>
        <?php endforeach; ?>
            </select>
        </label>
        <noscript><button type="submit">Trocar</button></noscript>
    </form>
    <form method="post" action="/admin/stock/grid">
        <?= \Iter\Core\FormKey::field() ?>
        <input type="hidden" name="product_id" value="<?= (int) $product_id ?>">
        <input type="hidden" name="source" value="<?= $e($source) ?>">
        <table>
            <thead>
                <tr><th></th><?php foreach ($columns as $column): ?><th><?= $e($column) ?></th><?php endforeach; ?><th>Total</th></tr>
            </thead>
            <tbody>
        <?php foreach ($rows as $label => $cells): ?>
                <tr>
                    <th><?= $e($label) ?></th>
            <?php foreach ($columns as $column): $cell = $cells[$column] ?? null; ?>
                    <td><?php if ($cell): ?><input type="text" inputmode="decimal" size="6" name="grid[<?= (int) $cell['product_id'] ?>]" value="<?= $e($cell['quantity']) ?>" pattern="\d+([.,]\d{1,4})?" title="<?= $e($cell['sku']) ?>"><?= $cell['is_active'] ? '' : ' <small>(inativa)</small>' ?><?php else: ?>–<?php endif; ?></td>
            <?php endforeach; ?>
                    <td><?= $e($sum($cells)) ?></td>
                </tr>
        <?php endforeach; ?>
                <tr>
                    <th>Total</th>
        <?php foreach ($columns as $column): ?>
                    <td><?= $e($sum(array_filter(array_column($rows, $column)))) ?></td>
        <?php endforeach; ?>
                    <td><?= $e($sum(array_merge(...array_values(array_map('array_values', $rows))))) ?></td>
                </tr>
            </tbody>
        </table>
        <p><button type="submit">Salvar estoque</button> <small>Quantidades na origem escolhida; os totais são de antes de salvar.</small></p>
    </form>
    <?php endif; ?>
</section>
<?php elseif ($product_id): ?>
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
