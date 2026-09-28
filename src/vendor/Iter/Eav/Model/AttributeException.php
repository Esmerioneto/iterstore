<?php

declare(strict_types=1);

namespace Iter\Eav\Model;

/** Business-rule failure; $errorCode is stable (e.g. INPUT_TYPE_INVALID), the message is for the admin. */
class AttributeException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
