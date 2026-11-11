<?php

declare(strict_types=1);

namespace OddsApiIo\Exception;

/**
 * 403: the key is valid but the plan does not include this endpoint or bookmaker,
 * or the endpoint is a paid add-on (for example Outrights) the key does not have.
 */
class ForbiddenException extends ApiException
{
    public function isAddOnRequired(): bool
    {
        return stripos($this->getMessage(), 'add-on') !== false;
    }
}
