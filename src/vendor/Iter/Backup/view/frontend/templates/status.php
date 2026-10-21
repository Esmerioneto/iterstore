<?php
use Iter\Backup\Model\Backup;

$e = static fn ($v): string => htmlspecialchars((string) $v);
$date = static fn (?string $at): string => $at ? date('d/m/Y H:i', strtotime($at)) . ' <small>(há ' . Backup::ago(time() - strtotime($at)) . ')</small>' : 'Nunca';
$tags = ['auto' => 'Automática', 'manual' => 'Pedida no painel'];
?>
<section class="backup-status">
    <div class="admin-page-head">
        <h2>Backup</h2>
        <form method="post" action="/admin/backup/run">
            <?= \Iter\Core\FormKey::field() ?>
            <button type="submit" class="admin-button-primary"<?= $pending ? ' disabled' : '' ?>><?= $pending ? 'Backup pedido…' : 'Fazer backup agora' ?></button>
        </form>
    </div>
<?php foreach ($warnings as $w): ?>
    <p class="backup-note backup-note-<?= $e($w['level']) ?>"><?= $e($w['text']) ?></p>
<?php endforeach; ?>
<?php if ($pending): ?>
    <p class="backup-note">O backup começa em até 1 minuto (cron do bin/backup.php). Atualize esta tela para ver o resultado.</p>
<?php endif; ?>
    <table class="backup-info">
        <tr><th>Backup automático</th><td><span class="admin-status admin-status-<?= $active ? 'on' : 'off' ?>"><?= $active ? 'Ligado' : 'Desligado' ?></span> <?= $active ? 'a cada ' . $e($frequency) : '' ?></td></tr>
        <tr><th>Destino</th><td><?= $e($destination) ?></td></tr>
        <tr><th>Último backup</th><td><?= $date($status['last_ok_at'] ?? null) ?><?= isset($status['duration_s']) ? ' · levou ' . $e(number_format((float) $status['duration_s'], 1, ',', '')) . ' s' : '' ?></td></tr>
        <tr><th>Cópias guardadas</th><td><?= isset($status['snapshots']) ? (int) $status['snapshots'] : '—' ?></td></tr>
        <tr><th>Espaço ocupado</th><td><?= isset($status['size_bytes']) ? $e(number_format($status['size_bytes'] / 1048576, 1, ',', '.')) . ' MB <small>(compactado, sem repetir o que não mudou)</small>' : '—' ?></td></tr>
        <tr><th>Teste de restauração</th><td><?= $date($status['last_verify_at'] ?? null) ?><?= isset($status['last_verify_at']) ? (empty($status['verify_error']) ? ' · <span class="admin-status admin-status-on">ok</span>' : ' · <span class="admin-status admin-status-off">falhou</span>') : '' ?> <small>Mensal: o último backup é restaurado num banco de teste, conferido e apagado.</small></td></tr>
        <tr><th>Kit de recuperação</th><td><?= $kit_saved_at !== '' ? 'Guardado em ' . $e(date('d/m/Y H:i', strtotime($kit_saved_at))) : 'Nunca guardado' ?><?php if ($root): ?> · <a href="/admin/backup/kit">Abrir o kit</a><?php endif; ?></td></tr>
    </table>
<?php if (!empty($status['latest'])): ?>
    <h3>Últimas cópias</h3>
    <table class="backup-snapshots">
        <thead><tr><th>Data</th><th>Cópia</th><th>Origem</th></tr></thead>
        <tbody>
    <?php foreach ($status['latest'] as $s): ?>
            <tr>
                <td><?= $e(date('d/m/Y H:i', strtotime($s['time']))) ?></td>
                <td><code><?= $e($s['id']) ?></code></td>
                <td><?= $e(implode(', ', array_map(static fn ($t) => $tags[$t] ?? $t, $s['tags']))) ?></td>
            </tr>
    <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
    <p class="admin-list-summary">Frequência, quantas cópias guardar e destino em <a href="/admin/config/index#config-group-backup">Configurações → Backup</a>. Um <code>.zip</code> de qualquer cópia: <code>php bin/backup.php zip [cópia]</code> no servidor.</p>
</section>
