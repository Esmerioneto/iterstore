<?php

declare(strict_types=1);

/**
 * Marketing e-mails (MARKETING-PLAN): finds who fits each rule that is on, creates the suggestions and, inside
 * the sending hours, queues the approved ones (bin/mail-worker.php sends them). Run it from cron:
 *
 *     0,15,30,45 * * * * php /caminho/do/iterstore/bin/marketing.php
 */

require_once __DIR__ . '/../path.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once FUNCTIONS . 'bootstrap.php';

if (!\isModuleEnabled('iter_marketing')) {
    exit(0);
}
$dir = dirname(SRC) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR;
is_dir($dir) || mkdir($dir, 0775, true);
$lock = fopen($dir . 'marketing.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // the previous run is still going
}
$totals = (new Iter\Marketing\Model\MarketingService())->run();
if (array_filter($totals)) {
    echo date('Y-m-d H:i:s') . " marketing: sugestões criadas {$totals['created']}, e-mails enfileirados {$totals['sent']}, pulados {$totals['skipped']}, expiradas {$totals['expired']}\n";
}
