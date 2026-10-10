<?php
use Iter\Mail\Model\MailService;

$e = static fn ($v) => htmlspecialchars((string) $v);
?>
<section class="mail-list mail-sent-list">
    <h2>E-mails enviados</h2>
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
        <button type="submit">Filtrar</button>
    </form>
    <table>
        <thead>
            <tr>
                <th>Data</th>
                <th>Destinatário</th>
                <th>Assunto</th>
                <th>Modelo</th>
                <th>Situação</th>
                <th>Tentativas</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
<?php if (!$messages): ?>
            <tr><td colspan="7">Nenhum e-mail encontrado.</td></tr>
<?php endif; ?>
<?php foreach ($messages as $message): ?>
            <tr>
                <td><?= $e(substr((string) $message['created_at'], 0, 16)) ?></td>
                <td><?= $e($message['to_email']) ?></td>
                <td><?= $e($message['subject']) ?></td>
                <td><code><?= $e($message['template_code']) ?></code></td>
                <td class="mail-status-<?= (int) $message['status'] ?>"><?= $e(MailService::STATUS_LABELS[(int) $message['status']]) ?></td>
                <td><?= (int) $message['attempts'] ?></td>
                <td class="admin-row-action"><a href="/admin/mail/view?id=<?= (int) $message['message_id'] ?>">Ver</a></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
    <?php \Iter\Admin\View\Pager::render($pager); ?>
</section>
