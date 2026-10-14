<?php

declare(strict_types=1);

/**
 * Deletes the var/log/iter-YYYY-MM-DD.log files older than Configurações →
 * "Manter os arquivos de log por", and the operation log's lines older than
 * "Guardar o log de operações por" (0 or empty keeps them all). Run it daily from cron:
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
if (isModuleEnabled('iter_log')) {
    $lines = Iter\Log\Model\OperationLog::clean((int) Iter\Core\Config::get(Iter\Log\Model\OperationLog::RETENTION));
    if ($lines) {
        echo date('Y-m-d H:i:s') . " log de operações: {$lines} linha(s) apagada(s)\n";
    }
}
