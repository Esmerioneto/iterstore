<?php

declare(strict_types=1);

namespace Iter\Backup\Model;

use Iter\Core\Config;
use Iter\Core\Crypt;
use Iter\Core\Db\Adapter;
use Iter\Core\Db\Connection;
use Iter\Core\Db\Expr;
use Iter\Core\Log;
use Iter\Mail\Model\MailService;

/**
 * Backups with restic: the DB as plain SQL (pg_dump), public/media and src/etc, compressed (zstd max),
 * deduplicated and encrypted, to a folder on this server or to a bucket (Backblaze B2 or S3-compatible).
 * bin/backup.php runs it from cron; the panel only reads var/backup/status.json and drops var/backup/run-now.
 */
class Backup
{
    /** restic's password, generated on first use, encrypted like the other secrets; not a form field */
    private const PASSWORD = 'system/backup/repo_password';
    /** when the kit was saved and what it held (sha256), so a changed key brings the warning back */
    public const KIT_SAVED_AT = 'system/backup/kit_saved_at';
    public const KIT_FINGERPRINT = 'system/backup/kit_fingerprint';
    /** the tables whose rows the monthly restore test compares */
    private const COUNTED = ['sales_order', 'customer_entity', 'catalog_product'];
    private const VERIFY_DAYS = 30;
    /** a failed run waits this long before the cron tries again (the run-now button doesn't) */
    private const RETRY_SECONDS = 900;
    private const HOST = 'iterstore';

    public static function dir(): string
    {
        return dirname(SRC) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR;
    }

    public static function status(): array
    {
        $file = self::dir() . 'status.json';

        return is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
    }

    private static function saveStatus(array $changes): array
    {
        $status = array_replace(self::status(), $changes);
        is_dir(self::dir()) || mkdir(self::dir(), 0775, true);
        file_put_contents(self::dir() . 'status.json.tmp', json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        rename(self::dir() . 'status.json.tmp', self::dir() . 'status.json');

        return $status;
    }

    /** the panel's "Fazer backup agora": the cron (each minute) does it, never the web request */
    public static function requestRun(): void
    {
        is_dir(self::dir()) || mkdir(self::dir(), 0775, true);
        touch(self::dir() . 'run-now');
    }

    public static function runRequested(): bool
    {
        return is_file(self::dir() . 'run-now');
    }

    public static function frequencyHours(): int
    {
        return max(1, (int) Config::get('system/backup/frequency'));
    }

    /** cron tick: is a copy due? hourly ones by the time since the last; daily and weekly ones at the chosen hour (and weekday) */
    public static function due(array $status, int $now): bool
    {
        if (!Config::get('system/backup/active')) {
            return false;
        }
        if (isset($status['last_error_at']) && $now - strtotime($status['last_error_at']) < self::RETRY_SECONDS
            && strtotime($status['last_error_at']) > strtotime($status['last_ok_at'] ?? '@0')) {
            return false;
        }
        $last = isset($status['last_ok_at']) ? (int) strtotime($status['last_ok_at']) : 0;
        $hours = self::frequencyHours();
        if ($hours < 24) {
            return $now - $last >= $hours * 3600 - 120; // the cron minute drifts a little
        }
        $at = mktime((int) Config::get('system/backup/hour'), 0, 0, (int) date('n', $now), (int) date('j', $now), (int) date('Y', $now));
        if ($hours >= 168) {
            $at -= (((int) date('w', $at) - (int) Config::get('system/backup/weekday') + 7) % 7) * 86400;
        }
        if ($at > $now) {
            $at -= ($hours >= 168 ? 7 : 1) * 86400;
        }

        return $last < $at;
    }

    /**
     * Configurações → Backup checked as a whole (config_groups.json "validator").
     *
     * @param callable(string): mixed $get the value each path will have after saving
     * @return list<string>
     */
    public static function validateConfig(callable $get): array
    {
        $errors = [];
        if (!array_filter(['hourly', 'daily', 'weekly', 'monthly'], static fn ($k) => (int) $get("system/backup/keep_{$k}") > 0)) {
            $errors[] = 'Backup: guarde ao menos um nível de cópias (por hora, dia, semana ou mês). Com todos em 0, a limpeza apagaria todas.';
        }
        $destination = (string) $get('system/backup/destination');
        if ($destination === 'local' && self::localPath((string) $get('system/backup/local_path')) === null) {
            $errors[] = 'Backup: a pasta neste servidor não pode ficar dentro de public/ nem ter "..".';
        }
        if ($destination !== 'local') {
            $missing = array_filter(['bucket' => 'Bucket', 'key_id' => 'ID da chave', 'key_secret' => 'Chave secreta'] + ($destination === 's3' ? ['endpoint' => 'Endpoint'] : []),
                static fn ($label, $k) => trim((string) $get("system/backup/{$k}")) === '', ARRAY_FILTER_USE_BOTH);
            if ($missing) {
                $errors[] = 'Backup: para guardar fora do servidor, preencha ' . implode(', ', $missing) . '.';
            }
        }

        return $errors;
    }

    /** absolute folder of a local repository; null when it's under public/ or climbs with ".." */
    public static function localPath(string $path): ?string
    {
        $path = trim($path) === '' ? 'var/backup/repo' : trim($path);
        if (in_array('..', explode('/', $path), true)) {
            return null;
        }
        $abs = rtrim(str_starts_with($path, '/') ? $path : dirname(SRC) . '/' . $path, '/');

        return str_starts_with($abs . '/', dirname(SRC) . '/public/') ? null : $abs;
    }

    /** where the copies go, for the screens (no secret) */
    public static function destinationLabel(): string
    {
        return match ((string) Config::get('system/backup/destination')) {
            'b2' => 'Backblaze B2, bucket ' . Config::get('system/backup/bucket'),
            's3' => 'S3 compatível, ' . Config::get('system/backup/endpoint') . ', bucket ' . Config::get('system/backup/bucket'),
            default => 'Só neste servidor, ' . (self::localPath((string) Config::get('system/backup/local_path')) ?? 'pasta inválida'),
        };
    }

    public static function isLocal(): bool
    {
        return !in_array(Config::get('system/backup/destination'), ['b2', 's3'], true);
    }

    /** restic's password; created the first time. One that can't be read (another env.ini) is an error, never replaced */
    public static function repoPassword(): string
    {
        $saved = (string) Config::get(self::PASSWORD);
        if ($saved === '') {
            $password = bin2hex(random_bytes(32));
            Config::save([self::PASSWORD => Crypt::encrypt($password)]);

            return $password;
        }
        $password = Crypt::decrypt($saved);
        if ($password === '') {
            throw new \RuntimeException('A senha do backup não pôde ser lida (a chave de src/etc/env.ini mudou?). Volte o env.ini do kit de recuperação.');
        }

        return $password;
    }

    /** what the recovery kit holds; its sha256 tells whether the saved kit is still the current one */
    public static function kit(): array
    {
        return ['password' => self::repoPassword(), 'crypt_key' => Crypt::exportKey()];
    }

    public static function kitFingerprint(): string
    {
        return hash('sha256', implode("\n", self::kit()));
    }

    public static function kitSaved(): bool
    {
        return (string) Config::get(self::PASSWORD) !== '' && (string) Config::get(self::KIT_SAVED_AT) !== ''
            && hash_equals((string) Config::get(self::KIT_FINGERPRINT), self::kitFingerprint());
    }

    /**
     * What the Dashboard and Sistema → Backup warn about, worst first.
     *
     * @return list<array{id: string, level: 'error'|'warning', text: string}>
     */
    public static function warnings(?array $status = null, ?int $now = null): array
    {
        $status ??= self::status();
        $now ??= time();
        $lastOk = isset($status['last_ok_at']) ? (int) strtotime($status['last_ok_at']) : null;
        $warnings = [];
        if (!empty($status['last_error']) && strtotime((string) $status['last_error_at']) > ($lastOk ?? 0)) {
            $warnings[] = ['id' => 'failed', 'level' => 'error', 'text' => 'O último backup falhou (' . self::ago($now - (int) strtotime($status['last_error_at'])) . '): ' . $status['last_error']];
        }
        if (Config::get('system/backup/active')) {
            if ($lastOk === null) {
                $warnings[] = ['id' => 'none', 'level' => 'warning', 'text' => 'Nenhum backup feito ainda. Confira se o cron do bin/backup.php roda a cada minuto, ou use "Fazer backup agora".'];
            } elseif ($now - $lastOk > 3 * self::frequencyHours() * 3600) {
                $warnings[] = ['id' => 'late', 'level' => 'error', 'text' => 'Último backup há ' . self::ago($now - $lastOk) . ', mais de 3 vezes o intervalo escolhido. Confira o cron do bin/backup.php.'];
            }
        } else {
            $warnings[] = ['id' => 'off', 'level' => 'warning', 'text' => 'O backup automático está desligado (Configurações → Backup).'];
        }
        if (!empty($status['verify_error'])) {
            $warnings[] = ['id' => 'verify', 'level' => 'error', 'text' => 'O teste de restauração falhou: ' . $status['verify_error']];
        }
        try {
            if (!self::kitSaved()) {
                $warnings[] = ['id' => 'kit', 'level' => 'warning', 'text' => 'O kit de recuperação não está guardado: sem a senha do backup e a chave do env.ini fora do servidor, as cópias não restauram. O perfil Administrador abre o kit em Sistema → Backup.'];
            }
        } catch (\RuntimeException $e) {
            $warnings[] = ['id' => 'kit', 'level' => 'error', 'text' => $e->getMessage()];
        }
        if (self::isLocal()) {
            $warnings[] = ['id' => 'local', 'level' => 'warning', 'text' => 'As cópias ficam só neste servidor: protegem contra erros, não contra perder o servidor. Escolha um destino externo em Configurações → Backup.'];
        }

        return $warnings;
    }

    public static function ago(int $seconds): string
    {
        return match (true) {
            $seconds < 3600 => max(1, intdiv($seconds, 60)) . ' min',
            $seconds < 2 * 86400 => intdiv($seconds, 3600) . ' h',
            default => intdiv($seconds, 86400) . ' dias',
        };
    }

    public static function markKitSaved(): void
    {
        Config::save([self::KIT_SAVED_AT => date('Y-m-d H:i:s'), self::KIT_FINGERPRINT => self::kitFingerprint()]);
    }

    /** restic's repository and the environment it runs with (secrets go in the environment, never in the arguments) */
    private static function env(): array
    {
        $bucket = trim((string) Config::get('system/backup/bucket'));
        $keyId = trim((string) Config::get('system/backup/key_id'));
        $secret = Crypt::decrypt((string) Config::get('system/backup/key_secret'));
        $destination = (string) Config::get('system/backup/destination');
        if ($destination !== 'local' && ($bucket === '' || $keyId === '' || $secret === '')) {
            throw new \RuntimeException('Preencha o bucket e a chave em Configurações → Backup (ou a chave secreta não pôde ser lida).');
        }
        $endpoint = rtrim(trim((string) Config::get('system/backup/endpoint')), '/');
        $endpoint = preg_match('#^https?://#', $endpoint) ? $endpoint : 'https://' . $endpoint;
        [$repository, $credentials] = match ($destination) {
            'b2' => ["b2:{$bucket}:iterstore", ['B2_ACCOUNT_ID' => $keyId, 'B2_ACCOUNT_KEY' => $secret]],
            's3' => ["s3:{$endpoint}/{$bucket}/iterstore", ['AWS_ACCESS_KEY_ID' => $keyId, 'AWS_SECRET_ACCESS_KEY' => $secret]],
            default => [self::localPath((string) Config::get('system/backup/local_path')) ?? throw new \RuntimeException('Pasta do backup inválida em Configurações → Backup.'), []],
        };

        return [
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'HOME' => getenv('HOME') ?: sys_get_temp_dir(),
            'RESTIC_REPOSITORY' => $repository,
            'RESTIC_PASSWORD' => self::repoPassword(),
            'RESTIC_CACHE_DIR' => dirname(SRC) . '/var/cache/restic',
        ] + $credentials;
    }

    /** the DB's connection from src/etc/database.ini; the password goes as PGPASSWORD */
    private static function db(): array
    {
        $ini = parse_ini_file(ETC . 'database.ini');

        return [$ini, ['PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin', 'PGPASSWORD' => (string) $ini['password']]];
    }

    /**
     * Runs a command without a shell. [exit code, stdout, stderr]; with $stdoutFile, stdout goes to that file.
     *
     * @param list<string> $cmd
     */
    private static function exec(array $cmd, array $env, ?string $stdoutFile = null): array
    {
        $process = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => $stdoutFile !== null ? ['file', $stdoutFile, 'w'] : ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(SRC), $env);
        if (!is_resource($process)) {
            throw new \RuntimeException('Não foi possível rodar ' . $cmd[0] . '.');
        }
        $out = $stdoutFile !== null ? '' : (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        array_map('fclose', $pipes);

        return [proc_close($process), $out, trim($err)];
    }

    /** @param list<string> $args */
    private static function restic(array $args, ?array $env = null, ?string $stdoutFile = null): string
    {
        [$code, $out, $err] = self::exec(['restic', ...$args], $env ?? self::env(), $stdoutFile);
        if ($code !== 0) {
            throw new \RuntimeException('restic ' . $args[0] . ' falhou: ' . ($err ?: "código {$code}"));
        }

        return $out;
    }

    /** the pg_dump of the server's version (Debian/Ubuntu keep one per version), else the one in PATH if not older */
    private static function pgBin(string $tool, int $serverMajor): string
    {
        $own = "/usr/lib/postgresql/{$serverMajor}/bin/{$tool}";
        if (is_executable($own)) {
            return $own;
        }
        [$code, $out] = self::exec([$tool, '--version'], self::db()[1]);
        if ($code !== 0 || !preg_match('/(\d+)(?:\.\d+)?/', $out, $m)) {
            throw new \RuntimeException("{$tool} não encontrado. Instale o pacote postgresql-client-{$serverMajor} (ou mais novo).");
        }
        if ((int) $m[1] < $serverMajor) {
            throw new \RuntimeException("{$tool} {$m[1]} é mais velho que o PostgreSQL {$serverMajor} do banco. Instale o postgresql-client-{$serverMajor} (ou mais novo).");
        }

        return $tool;
    }

    private static function serverMajor(): int
    {
        return intdiv((int) Connection::get()->adapter()->fetchOne('SHOW server_version_num'), 10000);
    }

    /** @return array<string, int> */
    private static function counts(Adapter $db): array
    {
        $counts = ['tabelas' => (int) $db->fetchOne($db->select()->from('pg_tables', [new Expr('COUNT(*)')])->where('schemaname = ?', 'public'))];
        foreach (self::COUNTED as $table) {
            $counts[$table] = (int) $db->fetchOne($db->select()->from($table, [new Expr('COUNT(*)')]));
        }

        return $counts;
    }

    /** the file the dump is written to: the same path every time, so restic finds it in any snapshot */
    public static function dumpFile(): string
    {
        return self::dir() . 'dump' . DIRECTORY_SEPARATOR . 'banco.sql';
    }

    /**
     * One copy now: dump, restic backup, the daily clean-up (forget --prune) and, monthly, the restore test.
     * Throws on failure, after writing it to the status (and the first failure's e-mail).
     */
    public static function run(?string $tag = null): array
    {
        $started = microtime(true);
        try {
            $env = self::env();
            self::ensureRepository($env);

            [$ini, $pgEnv] = self::db();
            $major = self::serverMajor();
            $dumpDir = dirname(self::dumpFile());
            is_dir($dumpDir) || mkdir($dumpDir, 0700, true);
            $counts = self::counts(Connection::get()->adapter());
            [$code, , $err] = self::exec([self::pgBin('pg_dump', $major), '-h', (string) $ini['host'], '-p', (string) $ini['port'], '-U', (string) $ini['user'],
                '--no-owner', '--no-privileges', '-f', self::dumpFile(), (string) $ini['database']], $pgEnv);
            if ($code !== 0) {
                throw new \RuntimeException('pg_dump falhou: ' . ($err ?: "código {$code}"));
            }
            [, $commit] = self::exec(['git', 'rev-parse', 'HEAD'], ['PATH' => $pgEnv['PATH']]);
            file_put_contents($dumpDir . '/commit.txt', trim($commit) ?: 'desconhecido');

            try {
                self::restic(['backup', '-q', '--compression', 'max', '--host', self::HOST, '--tag', $tag ?? 'auto',
                    $dumpDir, dirname(SRC) . '/public/media', rtrim(ETC, '/')], $env);
            } finally {
                @unlink(self::dumpFile()); // the customers' data doesn't stay in var/ as plain text
            }

            $status = self::status();
            $changes = ['counts' => $counts];
            if (strtotime($status['last_forget_at'] ?? '@0') < time() - 86400 + 120) {
                self::forget($env);
                $changes['last_forget_at'] = date('Y-m-d H:i:s');
            }
            $snapshots = json_decode(self::restic(['snapshots', '--json', '--host', self::HOST], $env), true) ?: [];
            $stats = json_decode(self::restic(['stats', '--json', '--mode', 'raw-data'], $env), true) ?: [];
            $status = self::saveStatus($changes + [
                'last_ok_at' => date('Y-m-d H:i:s'),
                'duration_s' => round(microtime(true) - $started, 1),
                'destination' => self::destinationLabel(),
                'snapshots' => count($snapshots),
                'latest' => array_map(static fn ($s) => ['id' => $s['short_id'] ?? substr((string) $s['id'], 0, 8), 'time' => date('Y-m-d H:i:s', strtotime($s['time'])), 'tags' => $s['tags'] ?? []], array_slice(array_reverse($snapshots), 0, 10)),
                'size_bytes' => (int) ($stats['total_size'] ?? 0),
                'last_error' => null,
                'last_error_at' => null,
                'error_mailed' => false,
            ]);
        } catch (\Throwable $e) {
            self::failed($e->getMessage());
            throw $e;
        }

        if (strtotime($status['last_verify_at'] ?? '@0') < time() - self::VERIFY_DAYS * 86400) {
            self::verifyAndRecord();
        }

        return self::status();
    }

    /** first run in a new destination: the repository is created (restic exits 10 when there's none) */
    private static function ensureRepository(array $env): void
    {
        $local = str_starts_with($env['RESTIC_REPOSITORY'], '/');
        if ($local && !is_dir($env['RESTIC_REPOSITORY'])) {
            mkdir($env['RESTIC_REPOSITORY'], 0700, true);
        }
        [$code, , $err] = self::exec(['restic', 'cat', 'config'], $env);
        if ($code === 10) {
            self::restic(['init', '-q'], $env);
        } elseif ($code !== 0) {
            throw new \RuntimeException('Não foi possível abrir o repositório do backup: ' . ($err ?: "código {$code}"));
        }
    }

    /** the retention of Configurações → Backup; a level at 0 isn't kept, and never all at 0 (validateConfig) */
    private static function forget(array $env): void
    {
        $keep = [];
        foreach (['hourly', 'daily', 'weekly', 'monthly'] as $level) {
            $n = (int) Config::get("system/backup/keep_{$level}");
            if ($n > 0) {
                array_push($keep, "--keep-{$level}", (string) $n);
            }
        }
        if ($keep) {
            self::restic(['forget', '-q', '--host', self::HOST, '--prune', ...$keep], $env);
        }
    }

    /** the failure in the status, the system log and, the first time, an e-mail (the next one only after a success) */
    private static function failed(string $message): void
    {
        $status = self::saveStatus(['last_error' => $message, 'last_error_at' => date('Y-m-d H:i:s')]);
        Log::error('Backup falhou: ' . $message);
        $to = trim((string) Config::get('system/backup/alert_email'));
        if (!empty($status['error_mailed']) || $to === '' || !\isModuleEnabled('iter_mail')) {
            return;
        }
        try {
            (new MailService())->send('backup_failed', $to, ['error' => $message, 'last_ok' => $status['last_ok_at'] ?? 'nunca', 'link' => Config::baseUrl() . '/admin/backup/index'], now: true);
            self::saveStatus(['error_mailed' => true]);
        } catch (\Throwable $e) {
            Log::error('Backup: aviso de falha por e-mail não enviado: ' . $e->getMessage());
        }
    }

    /** the restore test's result goes to the status: the panel shows it like a backup failure */
    public static function verifyAndRecord(): array
    {
        try {
            $result = self::verify();
            self::saveStatus(['last_verify_at' => date('Y-m-d H:i:s'), 'verify_error' => null, 'verify_result' => $result]);
        } catch (\Throwable $e) {
            self::saveStatus(['last_verify_at' => date('Y-m-d H:i:s'), 'verify_error' => $e->getMessage()]);
            Log::error('Backup: teste de restauração falhou: ' . $e->getMessage());
            throw $e;
        }

        return $result;
    }

    /**
     * Restores the latest copy's dump into a throwaway database (<db>_verify) and checks it has every table and
     * at least the rows counted just before that dump. The database is dropped at the end.
     */
    public static function verify(): array
    {
        $env = self::env();
        [$ini, $pgEnv] = self::db();
        $verifyDb = $ini['database'] . '_verify';
        $file = self::dir() . 'verify.sql';
        $raw = $file . '.raw';
        $psql = [self::pgBin('psql', self::serverMajor()), '-X', '-q', '-v', 'ON_ERROR_STOP=1', '-h', (string) $ini['host'], '-p', (string) $ini['port'], '-U', (string) $ini['user']];
        // CREATE/DROP DATABASE through psql: DDL outside the Adapter's reach, like the restore itself
        $dropTest = static fn () => self::exec([...$psql, '-d', 'postgres', '-c', "DROP DATABASE IF EXISTS \"{$verifyDb}\" WITH (FORCE)"], $pgEnv);
        try {
            self::restic(['dump', 'latest', self::dumpFile(), '--host', self::HOST], $env, $raw);
            // ponytail: pg_dump 17+ writes "SET transaction_timeout", unknown before PostgreSQL 17; the only line dropped
            $in = fopen($raw, 'rb');
            $out = fopen($file, 'wb');
            while (($line = fgets($in)) !== false) {
                str_starts_with($line, 'SET transaction_timeout') || fwrite($out, $line);
            }
            fclose($in);
            fclose($out);

            $dropTest();
            [$code, , $err] = self::exec([...$psql, '-d', 'postgres', '-c', "CREATE DATABASE \"{$verifyDb}\""], $pgEnv);
            if ($code !== 0) {
                throw new \RuntimeException("O usuário {$ini['user']} não pode criar o banco de teste {$verifyDb} (precisa de CREATEDB): {$err}");
            }
            [$code, , $err] = self::exec([...$psql, '-d', $verifyDb, '-f', $file], $pgEnv);
            if ($code !== 0) {
                throw new \RuntimeException('O dump não restaurou: ' . $err);
            }
            $restored = self::counts(new Adapter(new \PDO(sprintf('pgsql:host=%s;port=%d;dbname=%s', $ini['host'], $ini['port'], $verifyDb), (string) $ini['user'], (string) $ini['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION])));
            $expected = self::status()['counts'] ?? [];
            foreach ($expected as $what => $n) {
                if (($restored[$what] ?? 0) < $n) {
                    throw new \RuntimeException("Restaurado com menos {$what} que o banco tinha: {$restored[$what]} de {$n}.");
                }
            }

            return $restored;
        } finally {
            $dropTest();
            @unlink($raw);
            @unlink($file);
        }
    }

    /** a .zip of a copy (latest or a snapshot id), for opening anywhere; written to var/backup/ */
    public static function zip(string $snapshot = 'latest'): string
    {
        if (!preg_match('/^(latest|[0-9a-f]{8,64})$/', $snapshot)) {
            throw new \InvalidArgumentException('Cópia inválida: use latest ou o id dela.');
        }
        $file = self::dir() . 'backup-' . date('Ymd-His') . '.zip';
        self::restic(['dump', '--archive', 'zip', $snapshot, '/', '--host', self::HOST], null, $file);
        chmod($file, 0600);

        return $file;
    }
}
