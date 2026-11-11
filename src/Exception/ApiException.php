<?php

declare(strict_types=1);

namespace OddsApiIo\Exception;

use OddsApiIo\RateLimit;

/**
 * The API answered with an error status. The message is the server's own reason.
 */
class ApiException extends OddsApiException
{
    /**
     * @param mixed $body decoded JSON body, or the raw text when it was not JSON
     * @param array<string, string> $headers lower-cased response headers
     */
    public function __construct(
        string $message,
        private readonly int $statusCode,
        private readonly mixed $body = null,
        private readonly array $headers = [],
        private readonly ?RateLimit $rateLimit = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getBody(): mixed
    {
        return $this->body;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getRateLimit(): ?RateLimit
    {
        return $this->rateLimit;
    }
}
