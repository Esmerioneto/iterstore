<?php
use Iter\Admin\View\Sort;
use Iter\Marketing\Model\SubscriberService;

$e = static fn ($v) => htmlspecialchars((string) $v);
$badge = ['pending' => 'admin-status-pending_payment', 'confirmed' => 'admin-status-on', 'unsubscribed' => 'admin-status-off'];
?>
<section class="marketing-list marketing-subscribers">
    <div class="admin-page-head">
        <div>
            <h2>Inscritos na newsletter</h2>
            <p class="admin-list-summary"><?= (int) $total ?> inscrito(s). Só os confirmados recebem: a inscrição pede a confirmação pelo link do e-mail. Os que não são clientes recebem as "Novidades" (Configurações → Marketing).</p>
        </div>
        <div class="admin-page-actions">
            <a class="admin-button" href="<?= $e($export_url) ?>">Exportar CSV</a>
        </div>
    </div>
    <form method="get" action="/admin/marketingsubscriber/index" class="admin-filters">
        <select name="status">
            <option value="">Todas as situações</option>
<?php foreach (SubscriberService::STATUS_LABELS as $value => $label): ?>
            <option value="<?= $e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= $e($label) ?></option>
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
        <input type="search" name="q" placeholder="E-mail" value="<?= $e($filters['q']) ?>">
        <?= Sort::hidden() ?>
        <button type="submit">Filtrar</button>
    </form>
    <table>
        <thead>
            <tr>
                <?= Sort::th('date', 'Inscrito em', $sort, $dir) ?><?= $gridColumns->th('date') ?>
                <?= Sort::th('email', 'E-mail', $sort, $dir) ?><?= $gridColumns->th('email') ?>
                <th>Website</th><?= $gridColumns->th('website') ?>
                <?= Sort::th('status', 'Situação', $sort, $dir) ?><?= $gridColumns->th('status') ?>
                <?= Sort::th('confirmed', 'Confirmado em', $sort, $dir) ?><?= $gridColumns->th('confirmed') ?>
            </tr>
        </thead>
        <tbody>
<?php if (!$subscribers): ?>
            <tr><td colspan="<?= 5 + $gridColumns->count() ?>" class="admin-empty">Nenhum inscrito encontrado.</td></tr>
<?php endif; ?>
<?php foreach ($subscribers as $s): $id = (int) $s['subscriber_id']; $status = (string) $s['status']; ?>
            <tr>
                <td><?= $e(substr((string) $s['created_at'], 0, 16)) ?></td><?= $gridColumns->td('date', $id) ?>
                <td><?= $e($s['email']) ?><?= $s['customer_id'] ? '<br><small><a href="/admin/customer/edit?id=' . (int) $s['customer_id'] . '">É cliente</a></small>' : '' ?></td><?= $gridColumns->td('email', $id) ?>
                <td><?= $e($s['website']) ?></td><?= $gridColumns->td('website', $id) ?>
                <td><span class="admin-status <?= $badge[$status] ?? '' ?>"><?= $e(SubscriberService::STATUS_LABELS[$status] ?? $status) ?></span><?= $status === 'unsubscribed' && $s['unsubscribed_at'] ? '<br><small>em ' . $e(substr((string) $s['unsubscribed_at'], 0, 16)) . '</small>' : '' ?></td><?= $gridColumns->td('status', $id) ?>
                <td><?= $e(substr((string) $s['confirmed_at'], 0, 16)) ?></td><?= $gridColumns->td('confirmed', $id) ?>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
    <?php \Iter\Admin\View\Pager::render($pager); ?>
</section>
