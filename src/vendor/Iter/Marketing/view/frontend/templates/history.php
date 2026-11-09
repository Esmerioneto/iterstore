<?php
use Iter\Marketing\Model\MarketingService;

$e = static fn ($v) => htmlspecialchars((string) $v);
?>
<section class="marketing-list marketing-history">
    <div class="admin-page-head">
        <h2>Histórico de marketing</h2>
    </div>
    <div class="report-tiles">
<?php foreach ($stats as $stat): ?>
        <div class="report-tile<?= $stat['returned'] ? ' report-tile-ok' : '' ?>">
            <small><?= $e($stat['label']) ?></small>
            <strong><?= (int) $stat['sent'] ?></strong>
            <span><?= (int) $stat['sent'] === 1 ? 'e-mail enviado' : 'e-mails enviados' ?>; <?= (int) $stat['returned'] ?> comprou em até 7 dias<?= $stat['customers'] ? ' (' . round(100 * $stat['returned'] / $stat['customers']) . '%)' : '' ?></span>
        </div>
<?php endforeach; ?>
    </div>
    <p class="admin-list-summary">Os números contam os e-mails enviados no período escolhido. "Comprou" é um cliente cadastrado com pedido pago em até 7 dias depois do e-mail. Não dá para saber se foi por causa dele, e visitantes não entram na conta.</p>
    <form method="get" action="/admin/marketinghistory/index" class="admin-filters">
        <select name="status">
            <option value="">Todas as situações</option>
<?php foreach (MarketingService::STATUS_LABELS as $value => $label): ?>
            <option value="<?= $e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= $e($label) ?></option>
<?php endforeach; ?>
        </select>
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
        <label>De <input type="date" name="from" value="<?= $e($filters['from']) ?>"></label>
        <label>até <input type="date" name="to" value="<?= $e($filters['to']) ?>"></label>
        <?= \Iter\Admin\View\Sort::hidden() ?>
        <button type="submit">Filtrar</button>
    </form>
    <table>
        <thead>
            <tr>
                <?= \Iter\Admin\View\Sort::th('date', 'Criada em', $sort, $dir) ?><?= $gridColumns->th('date') ?>
                <?= \Iter\Admin\View\Sort::th('email', 'Cliente', $sort, $dir) ?><?= $gridColumns->th('email') ?>
                <?= \Iter\Admin\View\Sort::th('rule', 'Regra', $sort, $dir) ?><?= $gridColumns->th('rule') ?>
                <th>Motivo</th><?= $gridColumns->th('reason') ?>
                <?= \Iter\Admin\View\Sort::th('status', 'Situação', $sort, $dir) ?><?= $gridColumns->th('status') ?>
                <?= \Iter\Admin\View\Sort::th('sent', 'Enviada em', $sort, $dir) ?><?= $gridColumns->th('sent') ?>
                <th></th>
            </tr>
        </thead>
        <tbody>
<?php if (!$suggestions): ?>
            <tr><td colspan="<?= 7 + $gridColumns->count() ?>" class="admin-empty">Nenhuma sugestão encontrada.</td></tr>
<?php endif; ?>
<?php foreach ($suggestions as $s): $id = (int) $s['suggestion_id']; $status = (string) $s['status']; ?>
            <tr>
                <td><?= $e(substr((string) $s['created_at'], 0, 16)) ?></td><?= $gridColumns->td('date', $id) ?>
                <td><?= $s['customer_id'] ? '<a href="/admin/customer/edit?id=' . (int) $s['customer_id'] . '">' . $e($s['customer_name'] ?? $s['email']) . '</a>' : 'Visitante' ?><br><small><?= $e($s['email']) ?></small></td><?= $gridColumns->td('email', $id) ?>
                <td><?= $e($rules[$s['rule']] ?? $s['rule']) ?></td><?= $gridColumns->td('rule', $id) ?>
                <td><?= $e($s['reason']) ?></td><?= $gridColumns->td('reason', $id) ?>
                <td class="marketing-status-<?= $e($status) ?>"<?= $s['note'] ? ' title="' . $e($s['note']) . '"' : '' ?>><?= $e(MarketingService::STATUS_LABELS[$status] ?? $status) ?><?= $s['note'] ? '<br><small>' . $e($s['note']) . '</small>' : '' ?></td><?= $gridColumns->td('status', $id) ?>
                <td><?= $e(substr((string) $s['sent_at'], 0, 16)) ?></td><?= $gridColumns->td('sent', $id) ?>
                <td class="admin-row-action"><a href="/admin/marketing/view?id=<?= $id ?>">Ver</a></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
    <?php \Iter\Admin\View\Pager::render($pager); ?>
</section>
