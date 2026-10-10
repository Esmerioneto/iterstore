<?php $e = static fn ($v) => htmlspecialchars((string) $v); ?>
<section class="mail-template-list">
    <h2>Modelos de e-mail</h2>
    <p>Os modelos vêm dos módulos. Editar um modelo muda só o assunto e o corpo; "Restaurar padrão" volta ao original do módulo.</p>
    <table>
        <thead>
            <tr>
                <th>Modelo</th>
                <th>Código</th>
                <th>Assunto</th>
                <th>Situação</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
<?php foreach ($templates as $template): ?>
            <tr>
                <td><?= $e($template['label']) ?></td>
                <td><code><?= $e($template['code']) ?></code></td>
                <td><?= $e($template['subject']) ?></td>
                <td><?= $template['is_custom'] ? 'Personalizado' : 'Padrão do módulo' ?></td>
                <td><a href="/admin/mailtemplate/edit?code=<?= $e(rawurlencode($template['code'])) ?>">Editar</a></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
    <?php \Iter\Admin\View\Pager::render($pager); ?>
</section>
