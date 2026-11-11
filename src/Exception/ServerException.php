<?php

declare(strict_types=1);

namespace OddsApiIo\Exception;

use OddsApiIo\RateLimit;

/**
 * 5xx: the server failed or is temporarily busy.
 */
class ServerException extends ApiException
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
