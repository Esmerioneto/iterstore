<section class="inventory-source-list">
    <h2>Origens de estoque</h2>
    <p><a href="/admin/source/create">+ Nova origem</a></p>
    <table>
        <thead>
            <tr>
                <th>Código</th>
                <th>Nome</th>
                <th>Cidade</th>
                <th>Situação</th>
                <th>Prioridade</th>
                <th>Produtos com estoque</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
<?php foreach ($sources as $source): ?>
            <tr>
                <td><?= htmlspecialchars((string) $source['code']) ?></td>
                <td><?= htmlspecialchars((string) $source['name']) ?></td>
                <td><?= htmlspecialchars(implode(' / ', array_filter([(string) $source['city'], (string) $source['region']]))) ?></td>
                <td><?= $source['is_enabled'] ? 'Ativa' : 'Inativa' ?></td>
                <td><?= (int) $source['priority'] ?></td>
                <td><?= (int) $source['products'] ?></td>
                <td>
                    <a href="/admin/source/edit?id=<?= (int) $source['source_id'] ?>">Editar</a>
    <?php if ($source['code'] !== \Iter\Inventory\Model\Source::DEFAULT_CODE): ?>
                    <form method="post" action="/admin/source/delete" style="display:inline" data-confirm="Remover esta origem?">
                        <?= \Iter\Core\FormKey::field() ?>
                        <input type="hidden" name="source_id" value="<?= (int) $source['source_id'] ?>">
                        <button type="submit">Remover</button>
                    </form>
    <?php endif; ?>
                </td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
</section>
