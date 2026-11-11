<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use OddsApiIo\OddsApiClient;

$client = new OddsApiClient((string) getenv('ODDS_API_KEY'));

// Prices move and bookmakers limit stakes, so check every leg before acting.
$arbs = $client->getArbitrageBets(['Bet365', 'SingBet'], sport: 'football', markets: ['ML', 'Totals'], minProfit: 1);

foreach ($arbs as $arb) {
    $legs = array_map(
        static fn (array $leg): string => sprintf('%s %s @ %s', $leg['bookmaker'], $leg['side'], $leg['odds']),
        $arb['legs'] ?? [],
    );
    printf("%.2f%% | %s\n", $arb['profitMargin'], implode(' / ', $legs));
}
