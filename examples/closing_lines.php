<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use OddsApiIo\OddsApiClient;

$client = new OddsApiClient((string) getenv('ODDS_API_KEY'));

// Paid plans. Up to 10 leagues and 366 days per call; iterateClosingLines() pages for you.
$rows = $client->iterateClosingLines(
    sport: 'football',
    leagues: ['england-premier-league'],
    from: new DateTimeImmutable('-30 days'),
    to: new DateTimeImmutable('now'),
    markets: ['ML'],
    bookmakers: ['Bet365', 'SingBet'],
);

foreach ($rows as $event) {
    $ml = $event['bookmakers']['SingBet'][0]['odds'][0] ?? null;
    printf("%s vs %s: %s\n", $event['home'] ?? '?', $event['away'] ?? '?', $ml === null ? 'n/a' : json_encode($ml));
}
