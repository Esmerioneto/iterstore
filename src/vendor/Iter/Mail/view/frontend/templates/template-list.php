<?php $e = static fn ($v) => htmlspecialchars((string) $v); ?>
<section class="mail-template-list">
    <div class="admin-page-head">
        <h2>Modelos de e-mail</h2>
    </div>
    <form method="get" action="/admin/mailtemplate/index" class="admin-filters">
        <input type="search" name="q" placeholder="Modelo, código ou assunto" value="<?= $e($q) ?>">
        <?= \Iter\Admin\View\Sort::hidden() ?>
        <button type="submit">Filtrar</button>
    </form>
    <p class="admin-list-summary">Os modelos vêm dos módulos. Editar um modelo muda só o assunto e o corpo; "Restaurar padrão" volta ao original do módulo.</p>
    <table>
        <thead>
            <tr>
                <?= \Iter\Admin\View\Sort::th('label', 'Modelo', $sort, $dir) ?>
                <?= \Iter\Admin\View\Sort::th('code', 'Código', $sort, $dir) ?>
                <?= \Iter\Admin\View\Sort::th('subject', 'Assunto', $sort, $dir) ?>
                <?= \Iter\Admin\View\Sort::th('is_custom', 'Situação', $sort, $dir) ?>
                <th></th>
            </tr>
        </thead>
        <tbody>
<?php foreach ($templates as $template): ?>
            <tr>
                <td><?= $e($template['label']) ?></td>
                <td><code><?= $e($template['code']) ?></code></td>
                <td><?= $e($template['subject']) ?></td>
                <td><span class="admin-status admin-status-<?= $template['is_custom'] ? 'on' : 'off' ?>"><?= $template['is_custom'] ? 'Personalizado' : 'Padrão do módulo' ?></span></td>
                <td class="admin-row-action"><a href="/admin/mailtemplate/edit?code=<?= $e(rawurlencode($template['code'])) ?>">Editar</a></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
    <?php \Iter\Admin\View\Pager::render($pager); ?>
</section>
