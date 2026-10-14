<section class="inventory-source-list">
    <div class="admin-page-head">
        <h2>Origens de estoque</h2>
        <a class="admin-button admin-button-primary" href="/admin/source/create">+ Nova origem</a>
    </div>
    <table>
        <thead>
            <tr>
<?php if (count($websites) > 1): ?>
                <th>Website</th>
<?php endif; ?>
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
<?php if (count($websites) > 1): ?>
                <td><?= htmlspecialchars((string) ($websites[$source['website_id']] ?? '')) ?></td>
<?php endif; ?>
                <td><?= htmlspecialchars((string) $source['code']) ?></td>
                <td><?= htmlspecialchars((string) $source['name']) ?></td>
                <td><?= htmlspecialchars(implode(' / ', array_filter([(string) $source['city'], (string) $source['region']]))) ?></td>
                <td><span class="admin-status admin-status-<?= $source['is_enabled'] ? 'on' : 'off' ?>"><?= $source['is_enabled'] ? 'Ativa' : 'Inativa' ?></span></td>
                <td><?= (int) $source['priority'] ?></td>
                <td><?= (int) $source['products'] ?></td>
                <td class="admin-row-action">
                    <a href="/admin/source/edit?id=<?= (int) $source['source_id'] ?>">Editar</a>
    <?php if ($source['code'] !== \Iter\Inventory\Model\Source::DEFAULT_CODE): ?>
                    <form method="post" action="/admin/source/delete" data-confirm="Remover esta origem?">
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
    <?php \Iter\Admin\View\Pager::render($pager); ?>
</section>
