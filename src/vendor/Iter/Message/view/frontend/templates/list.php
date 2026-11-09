<?php
$e = static fn ($v) => htmlspecialchars((string) $v);
$name = static fn (array $c) => trim($c['firstname'] . ' ' . $c['lastname']);
?>
<section class="message-list">
    <div class="admin-page-head">
        <h2>Mensagens</h2>
    </div>
    <form method="get" action="/admin/message/index" class="admin-filters">
        <label class="mobile-switch"><input type="hidden" name="unread_only" value="0"><input type="checkbox" class="admin-switch" name="unread_only" value="1" <?= $filters['unread_only'] ? 'checked' : '' ?>> Só não lidas</label>
<?php if (count($websites) > 1): ?>
        <select name="website_id">
            <option value="">Todos os websites</option>
    <?php foreach ($websites as $value => $label): ?>
            <option value="<?= (int) $value ?>" <?= $filters['website_id'] === (int) $value ? 'selected' : '' ?>><?= $e($label) ?></option>
    <?php endforeach; ?>
        </select>
<?php endif; ?>
        <input type="search" name="q" placeholder="Nome ou e-mail" value="<?= $e($filters['q']) ?>">
        <?= \Iter\Admin\View\Sort::hidden() ?>
        <button type="submit">Filtrar</button>
    </form>
    <details class="message-new"<?= $find !== '' ? ' open' : '' ?>>
        <summary>+ Nova conversa</summary>
        <form method="get" action="/admin/message/index" class="admin-filters">
            <input type="search" name="find" placeholder="Nome, e-mail ou CPF/CNPJ do cliente" value="<?= $e($find) ?>">
            <button type="submit">Buscar cliente</button>
        </form>
<?php if ($find !== ''): ?>
        <table>
            <tbody>
    <?php if (!$found): ?>
                <tr><td>Nenhum cliente encontrado.</td></tr>
    <?php endif; ?>
    <?php foreach ($found as $c): ?>
                <tr>
                    <td><?= $e($c['customer_type'] === 'pj' && $c['company_name'] ? $c['company_name'] : $name($c)) ?> <small><?= $e($c['email']) ?></small></td>
                    <td class="admin-row-action"><a href="/admin/message/view?customer_id=<?= (int) $c['customer_id'] ?>">Abrir conversa</a></td>
                </tr>
    <?php endforeach; ?>
            </tbody>
        </table>
<?php endif; ?>
    </details>
    <table>
        <thead>
            <tr>
                <?= \Iter\Admin\View\Sort::th('customer', 'Cliente', $sort, $dir) ?><?= $gridColumns->th('customer') ?>
                <?= \Iter\Admin\View\Sort::th('website', 'Website', $sort, $dir) ?><?= $gridColumns->th('website') ?>
                <th>Última mensagem</th><?= $gridColumns->th('last_message') ?>
                <?= \Iter\Admin\View\Sort::th('date', 'Data', $sort, $dir) ?><?= $gridColumns->th('date') ?>
                <?= \Iter\Admin\View\Sort::th('unread', 'Não lidas', $sort, $dir) ?><?= $gridColumns->th('unread') ?>
                <th></th>
            </tr>
        </thead>
        <tbody>
<?php if (!$conversations): ?>
            <tr><td colspan="<?= 6 + $gridColumns->count() ?>" class="admin-empty">Nenhuma conversa encontrada.</td></tr>
<?php endif; ?>
<?php foreach ($conversations as $c): ?>
            <tr<?= $c['unread'] ? ' class="message-unread"' : '' ?>>
                <td><?= $e($name($c)) ?><br><small><?= $e($c['email']) ?></small></td><?= $gridColumns->td('customer', $c['customer_id']) ?>
                <td><?= $e($c['website_name']) ?></td><?= $gridColumns->td('website', $c['customer_id']) ?>
                <td class="message-last" title="<?= $e($c['last_body']) ?>"><?= $c['last_sender'] === 'admin' ? '<small>Loja:</small> ' : '' ?><?= $e($c['last_body']) ?></td><?= $gridColumns->td('last_message', $c['customer_id']) ?>
                <td><?= $e(date('d/m/Y H:i', strtotime((string) $c['last_at']))) ?></td><?= $gridColumns->td('date', $c['customer_id']) ?>
                <td><?php if ($c['unread']): ?><span class="admin-badge"><?= (int) $c['unread'] ?></span><?php endif; ?></td><?= $gridColumns->td('unread', $c['customer_id']) ?>
                <td class="admin-row-action"><a href="/admin/message/view?customer_id=<?= (int) $c['customer_id'] ?>">Abrir</a></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
    <?php \Iter\Admin\View\Pager::render($pager); ?>
</section>
