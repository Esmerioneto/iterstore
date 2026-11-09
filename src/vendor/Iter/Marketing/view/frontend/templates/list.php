<?php
$e = static fn ($v) => htmlspecialchars((string) $v);
?>
<section class="marketing-list">
    <div class="admin-page-head">
        <h2>Sugestões de e-mail</h2>
    </div>
    <p class="admin-list-summary">E-mails que as regras de <a href="/admin/config/index#config-group-marketing">Configurações → Marketing</a> sugeriram e esperam aprovação. Na hora do envio, o consentimento, o limite por cliente e o motivo são conferidos de novo. Não decididas em alguns dias, expiram.</p>
    <form method="get" action="/admin/marketing/index" class="admin-filters">
        <select name="rule">
            <option value="">Todas as regras</option>
<?php foreach ($rules as $code => $label): ?>
            <option value="<?= $e($code) ?>" <?= $filters['rule'] === $code ? 'selected' : '' ?>><?= $e($label) ?></option>
<?php endforeach; ?>
        </select>
<?php if (count($websites) > 1): ?>
        <select name="website_id">
            <option value="">Todos os websites</option>
    <?php foreach ($websites as $value => $label): ?>
            <option value="<?= (int) $value ?>" <?= $filters['website_id'] === (string) $value ? 'selected' : '' ?>><?= $e($label) ?></option>
    <?php endforeach; ?>
        </select>
<?php endif; ?>
        <input type="search" name="q" placeholder="Nome ou e-mail" value="<?= $e($filters['q']) ?>">
        <?= \Iter\Admin\View\Sort::hidden() ?>
        <button type="submit">Filtrar</button>
    </form>
    <form method="post" action="/admin/marketing/decide" class="marketing-decide">
        <?= \Iter\Core\FormKey::field() ?>
        <input type="hidden" name="back" value="<?= $e($back) ?>">
        <table>
            <thead>
                <tr>
                    <th class="marketing-check"><input type="checkbox" data-check-all="ids[]" aria-label="Marcar todas"></th>
                    <?= \Iter\Admin\View\Sort::th('date', 'Criada em', $sort, $dir) ?><?= $gridColumns->th('date') ?>
                    <?= \Iter\Admin\View\Sort::th('email', 'Cliente', $sort, $dir) ?><?= $gridColumns->th('email') ?>
                    <?= \Iter\Admin\View\Sort::th('rule', 'Regra', $sort, $dir) ?><?= $gridColumns->th('rule') ?>
                    <th>Motivo</th><?= $gridColumns->th('reason') ?>
                    <th></th>
                </tr>
            </thead>
            <tbody>
<?php if (!$suggestions): ?>
                <tr><td colspan="<?= 6 + $gridColumns->count() ?>" class="admin-empty">Nenhuma sugestão esperando aprovação.</td></tr>
<?php endif; ?>
<?php foreach ($suggestions as $s): $id = (int) $s['suggestion_id']; ?>
                <tr>
                    <td class="marketing-check"><input type="checkbox" name="ids[]" value="<?= $id ?>" aria-label="Escolher"></td>
                    <td><?= $e(substr((string) $s['created_at'], 0, 16)) ?></td><?= $gridColumns->td('date', $id) ?>
                    <td>
<?php if ($s['customer_id']): ?>
                        <a href="/admin/customer/edit?id=<?= (int) $s['customer_id'] ?>"><?= $e($s['customer_name'] ?? $s['email']) ?></a>
<?php else: ?>
                        Visitante
<?php endif; ?>
                        <br><small><?= $e($s['email']) ?></small>
                    </td><?= $gridColumns->td('email', $id) ?>
                    <td><?= $e($rules[$s['rule']] ?? $s['rule']) ?></td><?= $gridColumns->td('rule', $id) ?>
                    <td><?= $e($s['reason']) ?></td><?= $gridColumns->td('reason', $id) ?>
                    <td class="admin-row-action"><a href="/admin/marketing/view?id=<?= $id ?>">Ver e-mail</a></td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
<?php if ($suggestions): ?>
        <div class="marketing-decide-actions">
            <button type="submit" name="action" value="send">Enviar as marcadas</button>
            <button type="submit" name="action" value="discard">Descartar as marcadas</button>
        </div>
<?php endif; ?>
    </form>
    <?php \Iter\Admin\View\Pager::render($pager); ?>
</section>
