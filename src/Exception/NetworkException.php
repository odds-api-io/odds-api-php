<?php

declare(strict_types=1);

namespace OddsApiIo\Exception;

/**
 * The request never got a response: DNS, connection, TLS or timeout failure.
 */
class NetworkException extends OddsApiException
{
}
