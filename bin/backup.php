<?php

declare(strict_types=1);

/**
 * Backup with restic (Iter\Backup\Model\Backup; plan/BACKUP-PLAN.md). Run it every minute from cron: it only
 * copies when Configurações → Backup says it's time, or when "Fazer backup agora" was pressed in the panel.
 *
 *     * * * * * php /caminho/do/iterstore/bin/backup.php >> /caminho/do/iterstore/var/log/backup.log 2>&1
 *
 *     php bin/backup.php             cron tick
 *     php bin/backup.php now [tag]   one copy now (e.g. "now antes-migracao" before a migration)
 *     php bin/backup.php verify      restore test now (latest copy into a throwaway database)
 *     php bin/backup.php zip [id]    .zip of a copy (latest by default) in var/backup/
 */

require_once __DIR__ . '/../path.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once FUNCTIONS . 'bootstrap.php';

use Iter\Backup\Model\Backup;

if (!\isModuleEnabled('iter_backup')) {
    exit(0);
}

$command = $argv[1] ?? 'tick';
is_dir(Backup::dir()) || mkdir(Backup::dir(), 0775, true);
// one at a time: a slow copy (big upload) and the next minute's tick don't overlap
$lock = fopen(Backup::dir() . 'backup.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    $command === 'tick' || fwrite(STDERR, "Outro backup está rodando.\n");
    exit($command === 'tick' ? 0 : 1);
}

$say = static fn (string $line) => print(date('Y-m-d H:i:s') . ' ' . $line . "\n");
try {
    switch ($command) {
        case 'tick':
        case 'now':
            $requested = Backup::runRequested();
            if ($command === 'tick' && !$requested && !Backup::due(Backup::status(), time())) {
                exit(0);
            }
            @unlink(Backup::dir() . 'run-now');
            $status = Backup::run($command === 'now' ? ($argv[2] ?? 'manual') : ($requested ? 'manual' : null));
            $say(sprintf('backup ok: %d cópia(s), %.1f MB, %.1f s', $status['snapshots'], $status['size_bytes'] / 1048576, $status['duration_s']));
            break;
        case 'verify':
            $say('teste de restauração ok: ' . json_encode(Backup::verifyAndRecord()));
            break;
        case 'zip':
            $say('zip: ' . Backup::zip($argv[2] ?? 'latest'));
            break;
        default:
            fwrite(STDERR, "Uso: php bin/backup.php [now [marcação] | verify | zip [cópia]]\n");
            exit(2);
    }
} catch (\Throwable $e) {
    $say('FALHOU: ' . $e->getMessage());
    exit(1);
}
