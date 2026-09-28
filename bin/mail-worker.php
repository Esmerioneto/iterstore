<?php

declare(strict_types=1);

/**
 * Sends the queued e-mails. Run it every minute from cron:
 *
 *     * * * * * php /caminho/do/iterstore/bin/mail-worker.php
 */

require_once __DIR__ . '/../path.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once FUNCTIONS . 'bootstrap.php';

$result = (new Iter\Mail\Model\MailService())->processQueue();
if ($result['sent'] || $result['failed']) {
    echo date('Y-m-d H:i:s') . " enviados: {$result['sent']}, falhas: {$result['failed']}\n";
}
