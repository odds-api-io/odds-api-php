<?php

declare(strict_types=1);

namespace OddsApiIo\Exception;

use OddsApiIo\RateLimit;

/**
 * 429: the rate limit was exceeded. getRetryAfter() is the wait in seconds,
 * from Retry-After or X-RateLimit-Reset, or null when the server sent neither.
 */
class RateLimitException extends ApiException
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        string $message,
        int $statusCode,
        mixed $body = null,
        array $headers = [],
        ?RateLimit $rateLimit = null,
        private readonly ?float $retryAfter = null,
    ) {
        parent::__construct($message, $statusCode, $body, $headers, $rateLimit);
    }

    public function getRetryAfter(): ?float
    {
        return $this->retryAfter;
    }
}
