<?php

declare(strict_types=1);

namespace Iter\Eav\Model;

use GraphQL\Error\ClientAware;
use GraphQL\Error\ProvidesExtensions;

/**
 * Business-rule failure; $errorCode is stable (e.g. INPUT_TYPE_INVALID), the message is for the admin.
 * In GraphQL the message is shown as is, with the code in extensions.code.
 */
class AttributeException extends \RuntimeException implements ClientAware, ProvidesExtensions
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
