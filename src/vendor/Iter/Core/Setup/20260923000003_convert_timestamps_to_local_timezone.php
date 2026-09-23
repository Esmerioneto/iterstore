<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * Until now PHP and the DB session ran in UTC, so every "timestamp without
 * time zone" holds UTC wall time. Rewrites them to the app zone set in
 * functions/bootstrap.php. Triggers are paused so "modified" columns keep
 * their value.
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $this->shift($pdo, 'UTC', date_default_timezone_get());
    }

    public function down(PDO $pdo): void
    {
        $this->shift($pdo, date_default_timezone_get(), 'UTC');
    }

    private function shift(PDO $pdo, string $from, string $to): void
    {
        $columns = $pdo->query(
            "SELECT table_name, string_agg(format('%1\$I = %1\$I AT TIME ZONE %2\$L AT TIME ZONE %3\$L', column_name, "
            . $pdo->quote($from) . ', ' . $pdo->quote($to) . "), ', ')
             FROM information_schema.columns
             WHERE table_schema = 'public' AND data_type = 'timestamp without time zone'
             GROUP BY table_name"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        foreach ($columns as $table => $assignments) {
            $table = '"' . str_replace('"', '""', $table) . '"';
            $pdo->exec("ALTER TABLE {$table} DISABLE TRIGGER USER");
            $pdo->exec("UPDATE {$table} SET {$assignments}");
            $pdo->exec("ALTER TABLE {$table} ENABLE TRIGGER USER");
        }
    }
};
