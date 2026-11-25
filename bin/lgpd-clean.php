<?php

declare(strict_types=1);

/**
 * LGPD retention (LGPD-PLAN §10): each enabled module applies Configurações → LGPD → Prazos de guarda (orders
 * anonymized after the tax period, newsletter sign-ups never confirmed, consent proof and requests after their
 * period). The totals go to the system log. Run it daily from cron:
 *
 *     40 0 * * * php /caminho/do/iterstore/bin/lgpd-clean.php
 */

require_once __DIR__ . '/../path.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once FUNCTIONS . 'bootstrap.php';

if (!\isModuleEnabled('iter_lgpd')) {
    exit(0);
}

$done = array_filter((new Iter\Lgpd\Model\PersonalDataService())->purge());
if ($done) {
    $summary = implode('; ', array_map(static fn ($label, $n) => "{$label}: {$n}", array_keys($done), $done));
    Iter\Core\Log::info('LGPD, prazos de guarda: ' . $summary);
    echo date('Y-m-d H:i:s') . " LGPD, prazos de guarda: {$summary}\n";
}
