<?php

declare(strict_types=1);

namespace Iter\Sales\Model;

use GraphQL\Error\ClientAware;
use GraphQL\Error\ProvidesExtensions;

/**
 * Business-rule failure of a cart or an order; $errorCode is stable (e.g. CART_NOT_FOUND), the message
 * is for the customer. In GraphQL the message is shown as is, with the code in extensions.code.
 */
class SalesException extends \RuntimeException implements ClientAware, ProvidesExtensions
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public function isClientSafe(): bool
    {
        return true;
    }

    public function getExtensions(): array
    {
        return ['code' => $this->errorCode];
    }
}
