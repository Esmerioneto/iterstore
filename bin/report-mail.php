<?php

declare(strict_types=1);

/**
 * Configurações → Relatórios → Envio automático por e-mail (Iter\Report\Model\ReportMail). Does nothing
 * while the option is off or on a day that doesn't close a period. Run it once a day from cron:
 *
 *     30 7 * * * php /caminho/do/iterstore/bin/report-mail.php
 *
 * --date=AAAA-MM-DD runs as if it were that day (to resend a period).
 */

require_once __DIR__ . '/../path.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once FUNCTIONS . 'bootstrap.php';

if (!\isModuleEnabled('iter_report')) {
    exit(0);
}
try {
    $sent = (new Iter\Report\Model\ReportMail())->run(strtotime((string) (getopt('', ['date:'])['date'] ?? date('Y-m-d'))));
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
if ($sent !== null) {
    echo date('Y-m-d H:i:s') . " relatórios de {$sent['from']} a {$sent['to']} enfileirados (mensagem {$sent['message_id']})\n";
}
