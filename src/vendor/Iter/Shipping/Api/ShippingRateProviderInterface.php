<?php

declare(strict_types=1);

namespace Iter\Shipping\Api;

/** A module can register a provider by declaring it in etc/shipping_provider.json. */
interface ShippingRateProviderInterface
{
    /**
     * @param array<string, mixed> $cart A cart view with website_id, items, subtotal and shipping_address.
     * @return array<string, array{code:string,label:string,amount:float,days:int}>
     */
    public function rates(array $cart): array;
}
