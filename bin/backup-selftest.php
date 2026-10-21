<?php

declare(strict_types=1);

/**
 * Backup (Iter\Backup\Model\Backup): when a copy is due, the settings' checks, the warnings, and one real run
 * (dump, restic, restore test, zip) into a throwaway local repository. The saved settings and
 * var/backup/status.json are put back at the end.
 *
 * Uso: php bin/backup-selftest.php
 */

require_once __DIR__ . '/../path.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once FUNCTIONS . 'bootstrap.php';

use Iter\Backup\Model\Backup;
use Iter\Core\Config;
use Iter\Core\Db\Connection;

function assertSame($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FALHOU: {$label}\nesperado: " . var_export($expected, true) . "\nobtido:   " . var_export($actual, true) . "\n");
        exit(1);
    }
    echo "OK: {$label}\n";
}

// the cron's lock: it reads the same settings and status.json, which this test changes, so it waits for the end
is_dir(Backup::dir()) || mkdir(Backup::dir(), 0775, true);
$lock = fopen(Backup::dir() . 'backup.lock', 'c');
flock($lock, LOCK_EX);

$pdo = Connection::get()->connect();
$savedConfig = $pdo->query("SELECT scope, scope_id, path, value FROM core_config_data WHERE path LIKE 'system/backup/%'")->fetchAll(PDO::FETCH_ASSOC);
$statusFile = Backup::dir() . 'status.json';
$savedStatus = is_file($statusFile) ? file_get_contents($statusFile) : null;
$repo = sys_get_temp_dir() . '/selftest_backup_' . bin2hex(random_bytes(4));
$zips = glob(Backup::dir() . 'backup-*.zip') ?: [];
register_shutdown_function(static function () use ($pdo, $savedConfig, $statusFile, $savedStatus, $repo, $zips): void {
    // the password and the kit stay only if they existed before (a real first run would make them anyway)
    $pdo->exec("DELETE FROM core_config_data WHERE path LIKE 'system/backup/%'");
    $restore = $pdo->prepare('INSERT INTO core_config_data (scope, scope_id, path, value) VALUES (:scope, :scope_id, :path, :value)');
    foreach ($savedConfig as $row) {
        $restore->execute($row);
    }
    $savedStatus === null ? @unlink($statusFile) : file_put_contents($statusFile, $savedStatus);
    array_map('unlink', array_diff(glob(Backup::dir() . 'backup-*.zip') ?: [], $zips));
    is_dir($repo) && exec('rm -rf ' . escapeshellarg($repo));
});

$set = static fn (array $values) => Config::save($values);

// --- when a copy is due ------------------------------------------------------------------------
$set(['system/backup/active' => '1', 'system/backup/frequency' => '1']);
$now = mktime(10, 30, 0, 10, 21, 2026); // a Wednesday
assertSame([true, false, true], [
    Backup::due([], $now),
    Backup::due(['last_ok_at' => date('Y-m-d H:i:s', $now - 1800)], $now),
    Backup::due(['last_ok_at' => date('Y-m-d H:i:s', $now - 3540)], $now), // the next minute's tick, a little early
], 'a cada hora: sem cópia faz; 30 min depois não; 59 min depois sim');
$set(['system/backup/frequency' => '24', 'system/backup/hour' => '3']);
assertSame([true, false], [
    Backup::due(['last_ok_at' => date('Y-m-d H:i:s', mktime(3, 0, 30, 10, 20, 2026))], $now), // yesterday 3h
    Backup::due(['last_ok_at' => date('Y-m-d H:i:s', mktime(3, 0, 30, 10, 21, 2026))], $now), // today 3h
], 'diário às 3h: a de ontem pede outra; a de hoje às 3h basta');
assertSame(false, Backup::due(['last_ok_at' => date('Y-m-d H:i:s', mktime(3, 0, 30, 10, 20, 2026))], mktime(2, 59, 0, 10, 21, 2026)), 'diário às 3h: às 2h59 ainda não');
$set(['system/backup/frequency' => '168', 'system/backup/weekday' => '1']); // Mondays at 3h
assertSame([true, false], [
    Backup::due(['last_ok_at' => '2026-10-12 03:01:00'], $now), // the Monday before last
    Backup::due(['last_ok_at' => '2026-10-19 03:01:00'], $now), // this Monday
], 'semanal na segunda às 3h: a de duas segundas atrás pede outra; a desta segunda basta');
$set(['system/backup/frequency' => '1']);
assertSame([false, true], [
    Backup::due(['last_ok_at' => date('Y-m-d H:i:s', $now - 7200), 'last_error_at' => date('Y-m-d H:i:s', $now - 300)], $now),
    Backup::due(['last_ok_at' => date('Y-m-d H:i:s', $now - 7200), 'last_error_at' => date('Y-m-d H:i:s', $now - 1000)], $now),
], 'depois de uma falha, espera 15 minutos para tentar de novo');
$set(['system/backup/active' => '0']);
assertSame(false, Backup::due([], $now), 'desligado: nunca');
$set(['system/backup/active' => '1']);

// --- the settings checked together (Configurações → Backup) -------------------------------------
$check = static fn (array $values) => Backup::validateConfig(static fn (string $p) => $values[$p] ?? Config::fields()[$p]['default'] ?? '');
assertSame([], $check([]), 'os padrões são válidos');
assertSame(1, count($check(['system/backup/keep_hourly' => '0', 'system/backup/keep_daily' => '0', 'system/backup/keep_weekly' => '0', 'system/backup/keep_monthly' => '0'])), 'todos os níveis em 0 é recusado');
assertSame([1, 1, 1], [
    count($check(['system/backup/local_path' => 'public/media/bk'])),
    count($check(['system/backup/local_path' => '/srv/../etc'])),
    count($check(['system/backup/destination' => 'b2', 'system/backup/bucket' => 'b'])),
], 'pasta em public/ ou com "..", e B2 sem chave, são recusados');
assertSame(true, str_contains($check(['system/backup/destination' => 's3', 'system/backup/bucket' => 'b', 'system/backup/key_id' => 'k', 'system/backup/key_secret' => 'enc:x'])[0] ?? '', 'Endpoint'), 'S3 sem endpoint é recusado');
assertSame([dirname(SRC) . '/var/backup/repo', '/mnt/bk'], [Backup::localPath(''), Backup::localPath('/mnt/bk/')], 'pasta: vazia = var/backup/repo; absoluta como está');

// --- warnings ------------------------------------------------------------------------------------
$levels = static fn (array $status, int $now) => array_column(Backup::warnings($status, $now), 'level');
$set(['system/backup/destination' => 'local']);
$texts = array_column(Backup::warnings(['last_ok_at' => date('Y-m-d H:i:s', time() - 4 * 3600)], time()), 'text');
assertSame([true, true], [
    (bool) preg_grep('/^Último backup há 4 h/', $texts),
    (bool) preg_grep('/só neste servidor/', $texts),
], 'avisos: cópia de 4 h com frequência de 1 h, e destino só local');
assertSame('error', $levels(['last_ok_at' => date('Y-m-d H:i:s', time() - 7200), 'last_error' => 'x', 'last_error_at' => date('Y-m-d H:i:s', time() - 60)], time())[0], 'avisos: a falha mais nova que o último sucesso vem primeiro');

// --- one real run into a throwaway repository ---------------------------------------------------
$set(['system/backup/local_path' => $repo, 'system/backup/keep_hourly' => '24']);
@unlink($statusFile);
$status = Backup::run('selftest');
$restored = $status['verify_result'] ?? [];
assertSame([1, true, null, true, $status['counts']['sales_order']], [
    $status['snapshots'], $status['size_bytes'] > 0, $status['verify_error'], ($restored['tabelas'] ?? 0) === $status['counts']['tabelas'], $restored['sales_order'] ?? null,
], 'backup de verdade: 1 cópia, teste de restauração com todas as tabelas e os pedidos');
assertSame(false, is_file(Backup::dumpFile()), 'o banco.sql não fica em var/ depois do backup');
$second = Backup::run('selftest');
assertSame([2, true], [$second['snapshots'], $second['size_bytes'] < $status['size_bytes'] * 1.5], 'a segunda cópia só guarda o que mudou');
$zip = Backup::zip();
$entries = [];
$archive = new ZipArchive();
$archive->open($zip);
for ($i = 0; $i < $archive->numFiles; $i++) {
    $entries[] = $archive->getNameIndex($i);
}
$archive->close();
assertSame([true, true, '600'], [
    (bool) preg_grep('#var/backup/dump/banco\.sql$#', $entries),
    (bool) preg_grep('#src/etc/env\.ini$#', $entries),
    substr(sprintf('%o', fileperms($zip)), -3),
], 'zip da última cópia: banco, env.ini, só o dono lê');
$fingerprint = Backup::kitFingerprint();
Backup::markKitSaved();
assertSame([true, $fingerprint], [Backup::kitSaved(), Backup::kitFingerprint()], 'kit: guardado vale enquanto a senha e a chave forem as mesmas');

echo "\nTodos os testes passaram.\n";
