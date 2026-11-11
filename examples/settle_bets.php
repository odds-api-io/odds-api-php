<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use OddsApiIo\OddsApiClient;

$client = new OddsApiClient((string) getenv('ODDS_API_KEY'));

// Paid plans. Up to 50 selections per call; re-send any that are not final, at most once a minute.
$result = $client->gradeBets([
    ['ref' => 'bet-1', 'eventId' => 74919888, 'market' => 'ML', 'side' => 'home'],
    ['ref' => 'bet-2', 'eventId' => 74919888, 'market' => 'Spread', 'hdp' => -0.75, 'side' => 'home'],
    ['ref' => 'bet-3', 'eventId' => 74919888, 'market' => 'Correct Score', 'label' => '2-1'],
]);

foreach ($result['grades'] ?? [] as $grade) {
    printf("%s: %s%s\n", $grade['ref'], $grade['result'], ($grade['final'] ?? false) ? '' : ' (not final yet)');
}
