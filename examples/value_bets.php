<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use OddsApiIo\OddsApiClient;

$client = new OddsApiClient((string) getenv('ODDS_API_KEY'));

$bets = $client->getValueBets('Bet365', sport: 'football', includeEventDetails: true);

// expectedValue is bookmaker odds / fair odds x 100, so 103.42 is a 3.42% edge
foreach ($bets as $bet) {
    $edge = $bet['expectedValue'] - 100;
    if ($edge < 3) {
        continue;
    }
    printf(
        "%s vs %s | %s %s | %.2f%% edge\n",
        $bet['event']['home'] ?? '?',
        $bet['event']['away'] ?? '?',
        $bet['market']['name'] ?? '?',
        $bet['betSide'] ?? '?',
        $edge,
    );
}
