<?php

declare(strict_types=1);

/**
 * Deletes guest carts, and orders the admin left being built (Vendas → Novo pedido), unused for
 * Configurações → Vendas → "Dias que um carrinho de visitante sem uso é guardado" (the carts customers
 * build in the store stay). Run it daily from cron:
 *
 *     20 0 * * * php /caminho/do/iterstore/bin/quote-clean.php
 */

require_once __DIR__ . '/../path.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once FUNCTIONS . 'bootstrap.php';

$deleted = (new Iter\Sales\Model\CartService())->cleanGuests((int) Iter\Core\Config::get('sales/quote/guest_days'));
if ($deleted) {
    echo date('Y-m-d H:i:s') . " carrinhos de visitante e pedidos em montagem apagados: {$deleted}\n";
}
