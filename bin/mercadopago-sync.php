<?php

declare(strict_types=1);

/**
 * Reads back from the Mercado Pago the payments still open (Pix not paid yet, card under analysis), in
 * case a notification got lost or the store has no public address for the webhook. Run it from cron:
 *
 *     0,10,20,30,40,50 * * * * php /caminho/do/iterstore/bin/mercadopago-sync.php
 */

require_once __DIR__ . '/../path.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once FUNCTIONS . 'bootstrap.php';

if (!isModuleEnabled('iter_mercadopago')) {
    exit(0);
}
$read = (new Iter\Mercadopago\Model\MercadopagoService())->syncOpen();
if ($read) {
    echo date('Y-m-d H:i:s') . ' pagamentos do Mercado Pago conferidos: ' . implode(', ', $read) . "\n";
}
