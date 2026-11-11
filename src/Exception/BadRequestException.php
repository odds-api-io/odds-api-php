<?php

declare(strict_types=1);

namespace OddsApiIo\Exception;

/**
 * 400: a parameter is missing or invalid. The message says which one.
 */
class BadRequestException extends ApiException
{
}
