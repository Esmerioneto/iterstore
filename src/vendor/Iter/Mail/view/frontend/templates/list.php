<?php
use Iter\Mail\Model\MailService;

$e = static fn ($v) => htmlspecialchars((string) $v);
?>
<section class="mail-list mail-sent-list">
    <div class="admin-page-head">
        <h2>E-mails enviados</h2>
    </div>
<?php if ($stalled): ?>
    <p class="mail-warning"><?= (int) $stalled ?> e-mail(s) esperando há mais de 15 minutos. Confira se o cron roda <code>php bin/mail-worker.php</code> a cada minuto.</p>
<?php endif; ?>
    <form method="get" action="/admin/mail/index" class="admin-filters">
        <select name="status">
            <option value="">Todas as situações</option>
<?php foreach (MailService::STATUS_LABELS as $value => $label): ?>
            <option value="<?= $value ?>" <?= $filters['status'] === (string) $value ? 'selected' : '' ?>><?= $e($label) ?></option>
<?php endforeach; ?>
        </select>
        <input type="search" name="q" placeholder="Destinatário" value="<?= $e($filters['q']) ?>">
        <label>De <input type="date" name="from" value="<?= $e($filters['from']) ?>"></label>
        <label>até <input type="date" name="to" value="<?= $e($filters['to']) ?>"></label>
        <?= \Iter\Admin\View\Sort::hidden() ?>
        <button type="submit">Filtrar</button>
    </form>
    <table>
        <thead>
            <tr>
                <?= \Iter\Admin\View\Sort::th('date', 'Data', $sort, $dir) ?><?= $gridColumns->th('date') ?>
                <?= \Iter\Admin\View\Sort::th('to', 'Destinatário', $sort, $dir) ?><?= $gridColumns->th('to') ?>
                <?= \Iter\Admin\View\Sort::th('subject', 'Assunto', $sort, $dir) ?><?= $gridColumns->th('subject') ?>
                <?= \Iter\Admin\View\Sort::th('template', 'Modelo', $sort, $dir) ?><?= $gridColumns->th('template') ?>
                <?= \Iter\Admin\View\Sort::th('status', 'Situação', $sort, $dir) ?><?= $gridColumns->th('status') ?>
                <?= \Iter\Admin\View\Sort::th('attempts', 'Tentativas', $sort, $dir) ?><?= $gridColumns->th('attempts') ?>
                <th></th>
            </tr>
        </thead>
        <tbody>
<?php if (!$messages): ?>
            <tr><td colspan="<?= 7 + $gridColumns->count() ?>" class="admin-empty">Nenhum e-mail encontrado.</td></tr>
<?php endif; ?>
<?php foreach ($messages as $message): ?>
            <tr>
                <td><?= $e(substr((string) $message['created_at'], 0, 16)) ?></td><?= $gridColumns->td('date', $message['message_id']) ?>
                <td><?= $e($message['to_email']) ?></td><?= $gridColumns->td('to', $message['message_id']) ?>
                <td><?= $e($message['subject']) ?></td><?= $gridColumns->td('subject', $message['message_id']) ?>
                <td><code><?= $e($message['template_code']) ?></code></td><?= $gridColumns->td('template', $message['message_id']) ?>
                <td class="mail-status-<?= (int) $message['status'] ?>"><?= $e(MailService::STATUS_LABELS[(int) $message['status']]) ?></td><?= $gridColumns->td('status', $message['message_id']) ?>
                <td><?= (int) $message['attempts'] ?></td><?= $gridColumns->td('attempts', $message['message_id']) ?>
                <td class="admin-row-action"><a href="/admin/mail/view?id=<?= (int) $message['message_id'] ?>">Ver</a></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
    <?php \Iter\Admin\View\Pager::render($pager); ?>
</section>
