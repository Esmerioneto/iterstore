<?php

declare(strict_types=1);

namespace Iter\Lgpd\Model;

use GraphQL\Error\ClientAware;
use GraphQL\Error\ProvidesExtensions;

/** A refused LGPD action; $errorCode is stable (LGPD_ERASE_BLOCKED…), the message is for the person */
final class LgpdException extends \RuntimeException implements ClientAware, ProvidesExtensions
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
