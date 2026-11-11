<?php

declare(strict_types=1);

namespace OddsApiIo;

/**
 * Rate-limit state from the X-RateLimit-* response headers.
 */
final class RateLimit
{
    public function __construct(
        public readonly ?int $limit,
        public readonly ?int $remaining,
        public readonly ?\DateTimeImmutable $reset,
    ) {
    }

    /**
     * @param array<string, string> $headers lower-cased header names
     */
    public static function fromHeaders(array $headers): ?self
    {
        $limit = $headers['x-ratelimit-limit'] ?? null;
        $remaining = $headers['x-ratelimit-remaining'] ?? null;
        $reset = $headers['x-ratelimit-reset'] ?? null;
        if ($limit === null && $remaining === null && $reset === null) {
            return null;
        }

        return new self(self::toInt($limit), self::toInt($remaining), self::toTime($reset));
    }

    private static function toInt(?string $value): ?int
    {
        return $value !== null && is_numeric(trim($value)) ? (int) trim($value) : null;
    }

    private static function toTime(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (ctype_digit($value)) {
            return (new \DateTimeImmutable('@' . $value))->setTimezone(new \DateTimeZone('UTC'));
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
