<?php

declare(strict_types=1);

namespace Iter\Mail\Model;

/** Business-rule failure; $errorCode is stable (e.g. TEMPLATE_UNKNOWN), the message is for the admin */
class MailException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
