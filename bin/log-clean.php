<?php

declare(strict_types=1);

/**
 * Deletes the var/log/iter-YYYY-MM-DD.log files older than Configurações →
 * "Dias para manter os arquivos de log" (0 or empty keeps them all). Run it daily from cron:
 *
 *     10 0 * * * php /caminho/do/iterstore/bin/log-clean.php
 */

require_once __DIR__ . '/../path.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once FUNCTIONS . 'bootstrap.php';

$deleted = Iter\Core\Log::clean((int) Iter\Core\Config::get('system/log/retention_days'));
if ($deleted) {
    echo date('Y-m-d H:i:s') . ' apagados: ' . implode(', ', $deleted) . "\n";
}
