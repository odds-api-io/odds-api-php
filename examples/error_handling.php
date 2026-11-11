<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use OddsApiIo\Exception\ApiException;
use OddsApiIo\Exception\ForbiddenException;
use OddsApiIo\Exception\NetworkException;
use OddsApiIo\Exception\RateLimitException;
use OddsApiIo\OddsApiClient;

$client = new OddsApiClient((string) getenv('ODDS_API_KEY'), maxRetries: 2);

try {
    $tournaments = $client->getOutrights(sport: 'golf', league: 'pga-tour');
    printf("%d tournaments\n", count($tournaments));
} catch (RateLimitException $e) {
    printf("Rate limited, retry in %s seconds\n", $e->getRetryAfter() ?? '?');
} catch (ForbiddenException $e) {
    echo $e->isAddOnRequired() ? "Outrights add-on needed: {$e->getMessage()}\n" : "{$e->getMessage()}\n";
} catch (ApiException $e) {
    printf("HTTP %d: %s\n", $e->getStatusCode(), $e->getMessage());
} catch (NetworkException $e) {
    echo "Network error: {$e->getMessage()}\n";
}
