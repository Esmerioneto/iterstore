<?php
if ($status === null) return;
$e = static fn ($v): string => htmlspecialchars((string) $v);
?>
<section class="admin-dashboard-card backup-dashboard<?= $warnings ? ' has-warnings' : '' ?>">
    <h2>Backup</h2>
    <p>
        <a href="/admin/backup/index">Último backup: <?= isset($status['last_ok_at']) ? 'há ' . $e(\Iter\Backup\Model\Backup::ago(time() - strtotime($status['last_ok_at']))) : 'nunca' ?></a>
        <?= isset($status['snapshots']) ? ' · ' . (int) $status['snapshots'] . ' cópia(s)' : '' ?>
    </p>
<?php foreach ($warnings as $w): ?>
    <p class="backup-note backup-note-<?= $e($w['level']) ?>"><?= $e($w['text']) ?> <a href="/admin/backup/index">Ver o backup</a></p>
<?php endforeach; ?>
</section>
