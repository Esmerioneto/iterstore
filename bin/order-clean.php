<?php

declare(strict_types=1);

/**
 * Cancels the orders still waiting for payment after the hours set for their payment method in
 * Configurações → Pagamento (0 = never), giving the reserved stock back and telling the customer.
 * Run it every hour from cron:
 *
 *     15 * * * * php /caminho/do/iterstore/bin/order-clean.php
 */

require_once __DIR__ . '/../path.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once FUNCTIONS . 'bootstrap.php';

$canceled = (new Iter\Sales\Model\OrderService())->cancelUnpaid();
if ($canceled) {
    echo date('Y-m-d H:i:s') . ' pedidos não pagos cancelados: ' . implode(', ', $canceled) . "\n";
}
