# Odds-API.io PHP SDK

[![CI](https://github.com/odds-api-io/odds-api-php/actions/workflows/ci.yml/badge.svg)](https://github.com/odds-api-io/odds-api-php/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/odds-api-io/odds-api-php.svg)](https://packagist.org/packages/odds-api-io/odds-api-php)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-777bb4.svg)](https://www.php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

Official PHP SDK for the [**Odds-API.io**](https://odds-api.io) sports betting odds API: real-time and pre-match odds from 450+ bookmakers across 34 sports.

Building with AI? See the [AI and vibe coding guide](https://docs.odds-api.io/ai-vibe-coding). Prefer another language? Check out the [Node.js SDK](https://github.com/odds-api-io/odds-api-node) and the [Python SDK](https://github.com/odds-api-io/odds-api-python).

## Quickstart

1. Install (PHP 8.1 or newer):

   ```bash
   composer require odds-api-io/odds-api-php
   ```

2. Set your API key ([get one here](https://odds-api.io/pricing?ref=packagist)):

   ```bash
   export ODDS_API_KEY="your-api-key"
   ```

3. Make your first request:

   ```php
   <?php

   require 'vendor/autoload.php';

   use OddsApiIo\OddsApiClient;

   $client = new OddsApiClient(getenv('ODDS_API_KEY'));

   $events = $client->getEvents(sport: 'football', league: 'england-premier-league');
   $odds = $client->getEventOdds($events[0]['id'], ['Bet365', 'Unibet']);
   print_r($odds);
   ```

## Get an API key

The odds API needs a key from a paid plan. Pick a plan at [**odds-api.io/pricing**](https://odds-api.io/pricing?ref=packagist). Rate limits, the number of bookmakers you can select and WebSocket access depend on the plan.

## Sports and bookmakers

| Sport | Example leagues |
|-------|-----------------|
| Football (soccer) | Premier League, La Liga, Champions League |
| Basketball | NBA, EuroLeague |
| American football | NFL, NCAAF |
| Baseball | MLB |
| Ice hockey | NHL |
| Tennis | ATP, WTA |
| Esports | CS2, League of Legends, Dota 2 |
| ...and 27 more | 34 sports in total |

Odds come from **450+ bookmakers**, including Bet365, DraftKings, FanDuel, Betfair, Unibet and SingBet. See the [full coverage list](https://odds-api.io/sportsbooks#coverage).

## Documentation

- [Quickstart: make your first request](https://docs.odds-api.io/quickstart)
- [SDKs and libraries](https://docs.odds-api.io/guides/sdks)
- [Fetching pre-match and live odds](https://docs.odds-api.io/guides/fetching-odds)
- [Stream live odds over WebSocket](https://docs.odds-api.io/guides/websockets)
- [Find value bets](https://docs.odds-api.io/guides/value-bets) and [arbitrage opportunities](https://docs.odds-api.io/guides/arbitrage-bets)
- [Full API reference](https://docs.odds-api.io/api-reference/introduction)

## Features

- One method per public REST v3 endpoint: events, odds, odds summary, movements, value bets, arbitrage, dropping odds, historical odds and closing lines, settlement and grading, golf outrights, participants and bookmaker mappings
- Named arguments for every filter, so calls read like the API docs
- Generators that page for you: `iterateEvents`, `iterateClosingLines`, `iterateMappings`
- Typed exceptions that keep the server's message, status, body and rate-limit headers
- Rate-limit tracking and optional retries with backoff
- Guzzle by default, or bring any PSR-18 HTTP client

## Usage

```php
use OddsApiIo\OddsApiClient;

$client = new OddsApiClient(getenv('ODDS_API_KEY'));

// Exact market names for a sport
$markets = $client->getMarkets('football')['markets'];

// Upcoming and live Premier League matches
$events = $client->getEvents(
    sport: 'football',
    league: 'england-premier-league',
    status: ['pending', 'live'],
);

// Odds for one match. Pass markets to keep responses small.
$odds = $client->getEventOdds($events[0]['id'], ['Bet365', 'SingBet'], markets: ['ML', 'Spread', 'Totals']);
foreach ($odds['bookmakers'] as $bookmaker => $bookMarkets) {
    foreach ($bookMarkets as $market) {
        if ($market['name'] === 'ML') {
            print_r($market['odds'][0]); // ['home' => '2.10', 'draw' => '3.40', 'away' => '3.20']
        }
    }
}

// Search, including in-play matches
$live = $client->searchEvents('Liverpool', status: 'live');
```

List parameters (`bookmakers`, `markets`, `eventIds`, `leagues`, `status`) accept a comma-separated string (`'Bet365,SingBet'`) or an array. Dates accept an RFC3339 string, a `DateTimeInterface` or Unix seconds. Responses are decoded JSON arrays with the same field names as the [API reference](https://docs.odds-api.io/api-reference/introduction).

Market names must match in full: there is no `Match Winner` (use `ML`) and no bare `Over/Under`. Call `getMarkets($sport)` to see the names a sport serves, or browse the [market reference](https://odds-api.io/markets) per sport (for example [football](https://odds-api.io/markets/football)). Bookmaker coverage by sport is at [odds-api.io/sportsbooks](https://odds-api.io/sportsbooks#coverage).

## Odds comparison and summary

```php
// Best, average, median, worst and fair price per line across bookmakers (Growth plans and above)
$summary = $client->getOddsSummary(123456, markets: ['ML', 'Totals'], includeExchanges: true);
$home = $summary['markets'][0]['lines'][0]['outcomes']['home'];
echo $home['best'], ' ', implode(',', $home['bestBookmakers']), ' ', $home['fair'], PHP_EOL;

// Price history for one market (pre-match)
$history = $client->getOddsMovement(123456, 'Bet365', 'Totals', marketLine: 2.5);
print_r([$history['opening'], $history['latest']]);
```

## Polling for changes

`/odds/updated` returns only the market groups that changed. Pass the `X-Next-Since` header back as the next `since`:

```php
$since = time() - 20;
while (true) {
    $changed = $client->getUpdatedOddsSince($since, 'Bet365', 'football', ['ML', 'Spread', 'Totals']);
    foreach ($changed as $event) {
        // merge by event, bookmaker and market name; an absent market has not changed
    }
    $since = (int) ($client->getLastResponseHeader('X-Next-Since') ?? time());
    sleep(10);
}
```

For live odds the WebSocket is cheaper than any poll interval.

## WebSocket

This release covers the REST API. For real-time odds, scores and status, connect to `wss://api.odds-api.io/v3/ws?apiKey=...` with a PHP WebSocket library such as [ratchet/pawl](https://github.com/ratchetphp/Pawl) or [amphp/websocket-client](https://github.com/amphp/websocket-client). The server requires **permessage-deflate** compression, so pick a library that negotiates it (both of these do). Take a REST snapshot with `getOddsForMultipleEvents(..., includeSeq: true)` (the sequence is in `getLastResponseHeader('X-OddsAPI-Seq')`) and resume the stream from it with `lastSeq`. Messages, channels and close codes are described in the [WebSocket guide](https://docs.odds-api.io/guides/websockets). WebSocket access is a plan add-on.

## Historical odds and closing lines

```php
// Finished events for one league (31 days per call)
$past = $client->getHistoricalEvents('football', 'england-premier-league', '2026-09-01T00:00:00Z', '2026-09-30T23:59:59Z');

// Odds for one finished event
$closing = $client->getHistoricalOdds($past[0]['id'], ['Bet365'], markets: ['ML']);

// Closing lines for every settled event in up to 10 leagues (paid plans; 366 days per call, 100 per page)
foreach ($client->iterateClosingLines(
    sport: 'football',
    leagues: ['england-premier-league', 'spain-laliga'],
    from: '2026-08-01T00:00:00Z',
    to: '2026-10-31T23:59:59Z',
    markets: ['ML', 'Totals'],
    bookmakers: ['Bet365', 'SingBet'],
) as $event) {
    echo $event['id'], PHP_EOL;
}

// Search finished events by team name
$arsenal = $client->searchHistoricalEvents('Arsenal', sport: 'football');
```

## Settlement and grading

Paid plans. `getSettlements` returns status, a `final` flag and final scores; `gradeBets` grades selections against the result. Up to 50 per call.

```php
$settlements = $client->getSettlements([74919888, 73101436]);

$result = $client->gradeBets([
    ['ref' => 'bet-1', 'eventId' => 74919888, 'market' => 'ML', 'side' => 'home'],
    ['ref' => 'bet-2', 'eventId' => 74919888, 'market' => 'Spread', 'hdp' => -0.75, 'side' => 'home'],
    ['ref' => 'bet-3', 'eventId' => 74919888, 'market' => 'Correct Score', 'label' => '2-1'],
]);
foreach ($result['grades'] as $grade) {
    echo $grade['ref'], ' ', $grade['result'], PHP_EOL; // won, lost, void, half_won, half_lost, pending, ungradeable
}
```

Re-send selections that are not `final`, at most once a minute.

## Outrights (golf)

Outrights are a paid add-on: PGA Tour, DP World Tour, LIV Golf and the majors. A key without the add-on gets a `ForbiddenException` whose message links to the billing page.

```php
use OddsApiIo\Exception\ForbiddenException;

try {
    $tournaments = $client->getOutrights(sport: 'golf', league: 'pga-tour');
    $odds = $client->getOutrightOdds($tournaments[0]['id'], ['DraftKings', 'Betfair Exchange']);
    $results = $client->getOutrightResults($tournaments[0]['id']);
} catch (ForbiddenException $e) {
    if ($e->isAddOnRequired()) {
        echo $e->getMessage();
    }
}
```

Markets: Winner, Top 5, Top 10, Top 20, their "(Incl. Ties)" variants and Make Cut. "Top N" settles a boundary tie under the bookmaker's dead-heat rules; "Top N (Incl. Ties)" pays it in full.

## Value bets, arbitrage and dropping odds

```php
// expectedValue is bookmaker odds / fair odds x 100, so the edge in percent is expectedValue - 100
$valueBets = $client->getValueBets('Bet365', sport: 'football', includeEventDetails: true);

// Price gaps across your bookmakers. Prices move and bookmakers limit stakes, so check every leg before acting.
$arbs = $client->getArbitrageBets(['Bet365', 'SingBet'], sport: 'football', markets: ['Totals'], minProfit: 1);

// Dropping odds from sharp bookmakers (paid plans); the total is in the X-Total-Count header
$drops = $client->getDroppingOdds(sport: 'football', markets: ['ML'], minDrop: 10, sort: 'recent', includeEventDetails: true);
$total = $client->getLastResponseHeader('X-Total-Count');
```

## Participants, logos and mappings

```php
$teams = $client->getParticipants('football', search: 'Chelsea');
file_put_contents('crest.png', $client->getParticipantLogo($teams[0]['id']));

// A bookmaker's own market and outcome ids for our events (paid plans)
$page = $client->getMappings('Polymarket', eventId: 71515842);
foreach ($client->iterateMappings('Kalshi', sport: 'american-football') as $row) {
    echo $row['eventId'], ' ', $row['marketId'], PHP_EOL;
}
```

## Bookmakers

```php
$bookmakers = $client->getBookmakers(); // name, active, status (ok, degraded, down), reason
$client->selectBookmakers(['Bet365', 'SingBet']);
$selected = $client->getSelectedBookmakers();
$client->clearSelectedBookmakers(); // once every 12 hours
```

## Methods

| Area | Method | Endpoint |
|------|--------|----------|
| Catalogue | `getSports()` | `GET /sports` |
| | `getMarkets(?sport)` | `GET /markets` |
| | `getLeagues(sport, ?all)` | `GET /leagues` |
| | `getBookmakers()` | `GET /bookmakers` |
| | `getSelectedBookmakers()` | `GET /bookmakers/selected` |
| | `selectBookmakers(bookmakers)` | `PUT /bookmakers/selected/select` |
| | `clearSelectedBookmakers()` | `PUT /bookmakers/selected/clear` |
| Events | `getEvents(...)`, `iterateEvents(...)` | `GET /events` |
| | `getEventById(id)` | `GET /events/{id}` |
| | `getLiveEvents(?sport)` | `GET /events/live` |
| | `searchEvents(query, ?status)` | `GET /events/search` |
| Odds | `getEventOdds(...)` | `GET /odds` |
| | `getOddsForMultipleEvents(...)` | `GET /odds/multi` |
| | `getUpdatedOddsSince(...)` | `GET /odds/updated` |
| | `getOddsMovement(...)` | `GET /odds/movements` |
| | `getOddsSummary(...)` | `GET /odds/summary` |
| | `getOddsSummaryMulti(...)` | `GET /odds/summary/multi` |
| Analysis | `getValueBets(...)` | `GET /value-bets` |
| | `getArbitrageBets(...)` | `GET /arbitrage-bets` |
| | `getDroppingOdds(...)` | `GET /dropping-odds` |
| Historical | `getHistoricalEvents(...)` | `GET /historical/events` |
| | `searchHistoricalEvents(...)` | `GET /historical/events/search` |
| | `getHistoricalOdds(...)` | `GET /historical/odds` |
| | `getClosingLines(...)`, `iterateClosingLines(...)` | `GET /historical/closing-lines` |
| Settlement | `getSettlements(eventIds)` | `GET /settlements` |
| | `gradeBets(selections)` | `POST /settlements/grade` |
| Outrights | `getOutrights(...)` | `GET /outrights` |
| | `getOutrightOdds(id, bookmakers)` | `GET /outrights/odds` |
| | `getOutrightResults(id)` | `GET /outrights/results` |
| Participants | `getParticipants(sport, ?search)` | `GET /participants` |
| | `getParticipantById(id)` | `GET /participants/{id}` |
| | `getParticipantLogo(id)` | `GET /participants/{id}/logo` |
| Mappings | `getMappings(...)`, `iterateMappings(...)` | `GET /mappings` |
| Usage | `getLastRateLimit()`, `getLastResponseHeader(name)` | `X-RateLimit-*` headers on every response |

Full parameter and response reference: [docs.odds-api.io/api-reference](https://docs.odds-api.io/api-reference/introduction).

## Errors

Every error extends `OddsApiIo\Exception\OddsApiException`. HTTP errors extend `ApiException`, which carries the server's own message plus `getStatusCode()`, `getBody()`, `getHeaders()` and `getRateLimit()`.

| Class | When |
|-------|------|
| `BadRequestException` | 400: a parameter is missing or invalid (the message says which) |
| `AuthenticationException` | 401: the key is missing or invalid |
| `ForbiddenException` | 403: the plan does not include the endpoint or bookmaker, or an add-on is missing (`isAddOnRequired()`) |
| `NotFoundException` | 404: the message often lists what does exist |
| `RateLimitException` | 429, with `getRetryAfter()` in seconds |
| `ServerException` | 5xx |
| `NetworkException` | no response (DNS, connection, TLS, timeout) |

Limits the API would reject (more than 30 bookmakers, 10 event ids, a historical range over 31 days and so on) throw `InvalidArgumentException` before any request is sent.

```php
use OddsApiIo\Exception\ApiException;
use OddsApiIo\Exception\RateLimitException;

try {
    $client->getValueBets('Bet365');
} catch (RateLimitException $e) {
    echo "retry in {$e->getRetryAfter()}s";
} catch (ApiException $e) {
    echo $e->getStatusCode(), ' ', $e->getMessage();
}

$rateLimit = $client->getLastRateLimit(); // limit, remaining, reset from the last response
```

The API key is sent as the `apiKey` query parameter. It is never included in exception messages, and `var_dump($client)` masks it.

## Configuration

```php
$client = new OddsApiClient(
    apiKey: getenv('ODDS_API_KEY'),
    baseUrl: 'https://api.odds-api.io/v3', // default
    timeout: 10.0,      // seconds per request (default Guzzle client)
    maxRetries: 2,      // retries on 429, 5xx and network errors; 0 (default) turns them off
    maxRetryWait: 30.0, // a 429 asking for a longer wait is thrown, not waited out
);
```

To use your own HTTP stack, pass any PSR-18 client (and optionally PSR-17 request and stream factories):

```php
$client = new OddsApiClient(
    apiKey: getenv('ODDS_API_KEY'),
    httpClient: $psr18Client,
    requestFactory: $psr17Factory,
    streamFactory: $psr17Factory,
);
```

## Examples

See [`examples/`](examples): basic usage, value bets, arbitrage, closing lines, bet settlement and error handling. Run one with:

```bash
ODDS_API_KEY=your-api-key php examples/basic_usage.php
```

## Development

```bash
composer install
composer test      # PHPUnit
composer analyse   # PHPStan
```

## Support

- Docs: [docs.odds-api.io](https://docs.odds-api.io)
- Email: [hello@odds-api.io](mailto:hello@odds-api.io)
- Issues: [github.com/odds-api-io/odds-api-php/issues](https://github.com/odds-api-io/odds-api-php/issues)

## License

MIT. See [LICENSE](LICENSE).
