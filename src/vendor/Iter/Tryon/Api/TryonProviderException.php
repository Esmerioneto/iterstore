<?php

declare(strict_types=1);

namespace Iter\Tryon\Api;

/**
 * A provider's failure in the four words Iter_Tryon understands; the provider's own code stays in $providerCode
 * (kept in tryon_request.provider_code and in the log).
 */
class TryonProviderException extends \RuntimeException
{
    /** key refused, no balance, no permission: the store has to act */
    public const UNAVAILABLE = 'UNAVAILABLE';
    /** queue full, provider's rate limit: try again in a moment */
    public const BUSY = 'BUSY';
    /** provider error, network, parameter refused */
    public const FAILED = 'FAILED';
    /** improper content */
    public const REFUSED = 'REFUSED';

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?string $providerCode = null,
        public readonly array $response = [],
    ) {
        parent::__construct($message);
    }
}
