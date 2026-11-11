<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use OddsApiIo\OddsApiClient;

$client = new OddsApiClient((string) getenv('ODDS_API_KEY'));

$sports = $client->getSports();
printf("%d sports\n", count($sports));

$events = $client->getEvents(sport: 'football', league: 'england-premier-league', status: 'pending', limit: 5);
if ($events === []) {
    exit("No upcoming Premier League events\n");
}

$event = $events[0];
printf("%s vs %s, %s\n", $event['home'], $event['away'], $event['date']);

$odds = $client->getEventOdds($event['id'], ['Bet365', 'SingBet'], markets: ['ML', 'Totals']);
foreach ($odds['bookmakers'] ?? [] as $bookmaker => $markets) {
    foreach ($markets as $market) {
        if ($market['name'] === 'ML') {
            $price = $market['odds'][0];
            printf("%-10s home %s | draw %s | away %s\n", $bookmaker, $price['home'] ?? '-', $price['draw'] ?? '-', $price['away'] ?? '-');
        }
    }
}

$rateLimit = $client->getLastRateLimit();
if ($rateLimit !== null) {
    printf("Requests left this window: %s of %s\n", $rateLimit->remaining ?? '?', $rateLimit->limit ?? '?');
}
