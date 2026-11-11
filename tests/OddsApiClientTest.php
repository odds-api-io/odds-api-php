<?php

declare(strict_types=1);

namespace OddsApiIo\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OddsApiIo\Exception\ApiException;
use OddsApiIo\Exception\AuthenticationException;
use OddsApiIo\Exception\BadRequestException;
use OddsApiIo\Exception\ForbiddenException;
use OddsApiIo\Exception\NetworkException;
use OddsApiIo\Exception\NotFoundException;
use OddsApiIo\Exception\OddsApiException;
use OddsApiIo\Exception\RateLimitException;
use OddsApiIo\Exception\ServerException;
use OddsApiIo\OddsApiClient;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class OddsApiClientTest extends TestCase
{
    private const KEY = 'secret-key-1234';

    /** @var \ArrayObject<int, array<string, mixed>> */
    private \ArrayObject $history;

    /** @var \ArrayObject<int, float> */
    private \ArrayObject $sleeps;

    /**
     * @param list<Response|\Throwable> $responses
     */
    private function client(array $responses, int $maxRetries = 0): OddsApiClient
    {
        $this->history = new \ArrayObject();
        $this->sleeps = new \ArrayObject();
        $stack = HandlerStack::create(new MockHandler($responses));
        $container = $this->history;
        $stack->push(Middleware::history($container));

        return new class (self::KEY, new Client(['handler' => $stack]), $maxRetries, $this->sleeps) extends OddsApiClient {
            /**
             * @param \ArrayObject<int, float> $sleeps
             */
            public function __construct(string $apiKey, Client $http, int $maxRetries, private readonly \ArrayObject $sleeps)
            {
                parent::__construct($apiKey, $http, maxRetries: $maxRetries);
            }

            protected function sleep(float $seconds): void
            {
                $this->sleeps->append($seconds);
            }
        };
    }

    /**
     * @param array<string, string> $headers
     */
    private static function json(mixed $data, int $status = 200, array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'] + $headers, (string) json_encode($data));
    }

    private function lastRequest(): RequestInterface
    {
        $this->assertNotEmpty($this->history);

        $entry = $this->history[count($this->history) - 1];
        $this->assertIsArray($entry);
        $this->assertInstanceOf(RequestInterface::class, $entry['request']);

        return $entry['request'];
    }

    /**
     * @return array<string, string>
     */
    private function lastQuery(): array
    {
        parse_str($this->lastRequest()->getUri()->getQuery(), $query);

        /** @var array<string, string> $query */
        return $query;
    }

    public function testRequiresApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OddsApiClient('');
    }

    public function testSendsApiKeyAsQueryParamAndUserAgent(): void
    {
        $client = $this->client([self::json([['name' => 'Football', 'slug' => 'football']])]);

        $sports = $client->getSports();

        $this->assertSame('football', $sports[0]['slug']);
        $request = $this->lastRequest();
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('https', $request->getUri()->getScheme());
        $this->assertSame('api.odds-api.io', $request->getUri()->getHost());
        $this->assertSame('/v3/sports', $request->getUri()->getPath());
        $this->assertSame(self::KEY, $this->lastQuery()['apiKey']);
        $this->assertSame('odds-api-io-php/' . OddsApiClient::VERSION, $request->getHeaderLine('User-Agent'));
    }

    public function testEventsBuildsQueryFromNamedArguments(): void
    {
        $client = $this->client([self::json([['id' => 1]])]);

        $client->getEvents(
            sport: 'football',
            league: 'england-premier-league',
            status: ['pending', 'live'],
            from: new \DateTimeImmutable('2026-11-01 12:00:00', new \DateTimeZone('Europe/Oslo')),
            to: '2026-11-08T00:00:00Z',
            limit: 50,
        );

        $query = $this->lastQuery();
        $this->assertSame('/v3/events', $this->lastRequest()->getUri()->getPath());
        $this->assertSame('football', $query['sport']);
        $this->assertSame('england-premier-league', $query['league']);
        $this->assertSame('pending,live', $query['status']);
        $this->assertSame('2026-11-01T11:00:00Z', $query['from']);
        $this->assertSame('2026-11-08T00:00:00Z', $query['to']);
        $this->assertSame('50', $query['limit']);
        $this->assertArrayNotHasKey('skip', $query);
        $this->assertArrayNotHasKey('bookmaker', $query);
    }

    public function testEventOddsJoinsListsAndSendsBooleans(): void
    {
        $client = $this->client([self::json(['id' => 123, 'bookmakers' => ['Bet365' => []]])]);

        $odds = $client->getEventOdds(123, ['Bet365', 'SingBet'], 'ML,Totals', includeSeq: true);

        $this->assertSame(123, $odds['id']);
        $query = $this->lastQuery();
        $this->assertSame('123', $query['eventId']);
        $this->assertSame('Bet365,SingBet', $query['bookmakers']);
        $this->assertSame('ML,Totals', $query['markets']);
        $this->assertSame('true', $query['includeSeq']);
    }

    /**
     * @return iterable<string, array{0: callable(OddsApiClient): mixed, 1: string, 2: string, 3: array<string, string>}>
     */
    public static function endpointProvider(): iterable
    {
        yield 'leagues' => [fn (OddsApiClient $c) => $c->getLeagues('football', all: true), 'GET', '/v3/leagues', ['sport' => 'football', 'all' => 'true']];
        yield 'markets' => [fn (OddsApiClient $c) => $c->getMarkets('football'), 'GET', '/v3/markets', ['sport' => 'football']];
        yield 'bookmakers' => [fn (OddsApiClient $c) => $c->getBookmakers(), 'GET', '/v3/bookmakers', []];
        yield 'selected' => [fn (OddsApiClient $c) => $c->getSelectedBookmakers(), 'GET', '/v3/bookmakers/selected', []];
        yield 'select' => [fn (OddsApiClient $c) => $c->selectBookmakers(['Bet365', 'SingBet']), 'PUT', '/v3/bookmakers/selected/select', ['bookmakers' => 'Bet365,SingBet']];
        yield 'clear' => [fn (OddsApiClient $c) => $c->clearSelectedBookmakers(), 'PUT', '/v3/bookmakers/selected/clear', []];
        yield 'event by id' => [fn (OddsApiClient $c) => $c->getEventById(42), 'GET', '/v3/events/42', []];
        yield 'live' => [fn (OddsApiClient $c) => $c->getLiveEvents('basketball'), 'GET', '/v3/events/live', ['sport' => 'basketball']];
        yield 'search' => [fn (OddsApiClient $c) => $c->searchEvents('Liverpool', 'live'), 'GET', '/v3/events/search', ['query' => 'Liverpool', 'status' => 'live']];
        yield 'multi' => [fn (OddsApiClient $c) => $c->getOddsForMultipleEvents([1, 2], 'Bet365', ['ML']), 'GET', '/v3/odds/multi', ['eventIds' => '1,2', 'bookmakers' => 'Bet365', 'markets' => 'ML']];
        yield 'updated' => [fn (OddsApiClient $c) => $c->getUpdatedOddsSince(1700000000, 'Bet365', 'football', ['ML', 'Totals'], 100), 'GET', '/v3/odds/updated', ['since' => '1700000000', 'bookmaker' => 'Bet365', 'sport' => 'football', 'markets' => 'ML,Totals', 'limit' => '100']];
        yield 'movements' => [fn (OddsApiClient $c) => $c->getOddsMovement(1, 'Bet365', 'Totals', 2.5), 'GET', '/v3/odds/movements', ['eventId' => '1', 'bookmaker' => 'Bet365', 'market' => 'Totals', 'marketLine' => '2.5']];
        yield 'summary' => [fn (OddsApiClient $c) => $c->getOddsSummary(1, ['ML'], 'all', includeExchanges: false), 'GET', '/v3/odds/summary', ['eventId' => '1', 'markets' => 'ML', 'lines' => 'all', 'includeExchanges' => 'false']];
        yield 'summary multi' => [fn (OddsApiClient $c) => $c->getOddsSummaryMulti([1, 2]), 'GET', '/v3/odds/summary/multi', ['eventIds' => '1,2']];
        yield 'value bets' => [fn (OddsApiClient $c) => $c->getValueBets('Bet365', sport: 'football', includeEventDetails: true), 'GET', '/v3/value-bets', ['bookmaker' => 'Bet365', 'sport' => 'football', 'includeEventDetails' => 'true']];
        yield 'arbitrage' => [fn (OddsApiClient $c) => $c->getArbitrageBets(['Bet365', 'SingBet'], markets: ['Totals'], minProfit: 1.5, allCombinations: true), 'GET', '/v3/arbitrage-bets', ['bookmakers' => 'Bet365,SingBet', 'markets' => 'Totals', 'minProfit' => '1.5', 'allCombinations' => 'true']];
        yield 'dropping' => [fn (OddsApiClient $c) => $c->getDroppingOdds(sport: 'football', timeWindow: '24h', sort: 'recent', minDrop: 10, page: 2), 'GET', '/v3/dropping-odds', ['sport' => 'football', 'timeWindow' => '24h', 'sort' => 'recent', 'minDrop' => '10', 'page' => '2']];
        yield 'historical events' => [fn (OddsApiClient $c) => $c->getHistoricalEvents('football', 'england-premier-league', '2026-09-01T00:00:00Z', '2026-09-30T00:00:00Z'), 'GET', '/v3/historical/events', ['sport' => 'football', 'league' => 'england-premier-league', 'from' => '2026-09-01T00:00:00Z', 'to' => '2026-09-30T00:00:00Z']];
        yield 'historical search' => [fn (OddsApiClient $c) => $c->searchHistoricalEvents('Arsenal', 'football'), 'GET', '/v3/historical/events/search', ['query' => 'Arsenal', 'sport' => 'football']];
        yield 'historical odds' => [fn (OddsApiClient $c) => $c->getHistoricalOdds(9, 'Bet365', 'ML'), 'GET', '/v3/historical/odds', ['eventId' => '9', 'bookmakers' => 'Bet365', 'markets' => 'ML']];
        yield 'closing lines' => [fn (OddsApiClient $c) => $c->getClosingLines('football', ['a', 'b'], '2026-01-01T00:00:00Z', '2026-03-01T00:00:00Z', 'ML', 'Bet365', 100, 200), 'GET', '/v3/historical/closing-lines', ['sport' => 'football', 'leagues' => 'a,b', 'from' => '2026-01-01T00:00:00Z', 'to' => '2026-03-01T00:00:00Z', 'markets' => 'ML', 'bookmakers' => 'Bet365', 'limit' => '100', 'skip' => '200']];
        yield 'settlements' => [fn (OddsApiClient $c) => $c->getSettlements([1, 2, 3]), 'GET', '/v3/settlements', ['eventIds' => '1,2,3']];
        yield 'outrights' => [fn (OddsApiClient $c) => $c->getOutrights(league: 'majors'), 'GET', '/v3/outrights', ['sport' => 'golf', 'league' => 'majors']];
        yield 'outright odds' => [fn (OddsApiClient $c) => $c->getOutrightOdds(1001, ['DraftKings']), 'GET', '/v3/outrights/odds', ['id' => '1001', 'bookmakers' => 'DraftKings']];
        yield 'outright results' => [fn (OddsApiClient $c) => $c->getOutrightResults(1001), 'GET', '/v3/outrights/results', ['id' => '1001']];
        yield 'participants' => [fn (OddsApiClient $c) => $c->getParticipants('football', 'Chelsea'), 'GET', '/v3/participants', ['sport' => 'football', 'search' => 'Chelsea']];
        yield 'participant' => [fn (OddsApiClient $c) => $c->getParticipantById(45), 'GET', '/v3/participants/45', []];
        yield 'mappings' => [fn (OddsApiClient $c) => $c->getMappings('Polymarket', eventId: 71515842, active: true), 'GET', '/v3/mappings', ['bookmaker' => 'Polymarket', 'eventId' => '71515842', 'active' => 'true']];
    }

    /**
     * @param callable(OddsApiClient): mixed $call
     * @param array<string, string> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('endpointProvider')]
    public function testEndpointPathsAndParams(callable $call, string $method, string $path, array $expected): void
    {
        $client = $this->client([self::json([])]);

        $call($client);

        $this->assertSame($method, $this->lastRequest()->getMethod());
        $this->assertSame($path, $this->lastRequest()->getUri()->getPath());
        $query = $this->lastQuery();
        $this->assertSame(self::KEY, $query['apiKey']);
        unset($query['apiKey']);
        ksort($query);
        ksort($expected);
        $this->assertSame($expected, $query);
    }

    public function testGradeBetsPostsJsonBody(): void
    {
        $client = $this->client([self::json(['grades' => [['ref' => 'bet-1', 'result' => 'won', 'final' => true]]])]);

        $result = $client->gradeBets([
            ['ref' => 'bet-1', 'eventId' => 74919888, 'market' => 'Spread', 'hdp' => -0.75, 'side' => 'home'],
        ]);

        $this->assertSame('won', $result['grades'][0]['result']);
        $request = $this->lastRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/v3/settlements/grade', $request->getUri()->getPath());
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame(['selections' => [['ref' => 'bet-1', 'eventId' => 74919888, 'market' => 'Spread', 'hdp' => -0.75, 'side' => 'home']]], $body);
    }

    public function testParticipantLogoReturnsRawBytes(): void
    {
        $png = "\x89PNG\r\n\x1a\nfake";
        $client = $this->client([new Response(200, ['Content-Type' => 'image/png'], $png)]);

        $this->assertSame($png, $client->getParticipantLogo(45));
        $this->assertSame('/v3/participants/45/logo', $this->lastRequest()->getUri()->getPath());
    }

    public function testRateLimitAndHeadersAreTracked(): void
    {
        $client = $this->client([self::json([], 200, [
            'X-RateLimit-Limit' => '5000',
            'X-RateLimit-Remaining' => '4999',
            'X-RateLimit-Reset' => '2026-11-11T13:00:00Z',
            'X-Next-Since' => '1700000042',
        ])]);

        $client->getUpdatedOddsSince(1700000000, 'Bet365', 'football', 'ML');

        $rateLimit = $client->getLastRateLimit();
        $this->assertNotNull($rateLimit);
        $this->assertSame(5000, $rateLimit->limit);
        $this->assertSame(4999, $rateLimit->remaining);
        $this->assertSame('2026-11-11T13:00:00+00:00', $rateLimit->reset?->format(DATE_ATOM));
        $this->assertSame('1700000042', $client->getLastResponseHeader('X-Next-Since'));
    }

    /**
     * @return iterable<string, array{int, class-string<ApiException>}>
     */
    public static function errorProvider(): iterable
    {
        yield '400' => [400, BadRequestException::class];
        yield '401' => [401, AuthenticationException::class];
        yield '403' => [403, ForbiddenException::class];
        yield '404' => [404, NotFoundException::class];
        yield '409' => [409, ApiException::class];
        yield '429' => [429, RateLimitException::class];
        yield '503' => [503, ServerException::class];
    }

    /**
     * @param class-string<ApiException> $class
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('errorProvider')]
    public function testErrorsMapToTypedExceptions(int $status, string $class): void
    {
        $client = $this->client([self::json(['error' => 'Something specific went wrong'], $status)]);

        try {
            $client->getSports();
            $this->fail('Expected an exception');
        } catch (ApiException $e) {
            $this->assertInstanceOf($class, $e);
            $this->assertInstanceOf(OddsApiException::class, $e);
            $this->assertSame($status, $e->getStatusCode());
            $this->assertSame('Something specific went wrong', $e->getMessage());
            $this->assertSame(['error' => 'Something specific went wrong'], $e->getBody());
        }
    }

    public function testRateLimitExceptionCarriesRetryAfter(): void
    {
        $client = $this->client([self::json(['error' => 'Rate limit exceeded'], 429, ['Retry-After' => '12'])]);

        try {
            $client->getSports();
            $this->fail('Expected an exception');
        } catch (RateLimitException $e) {
            $this->assertSame(12.0, $e->getRetryAfter());
        }
    }

    public function testForbiddenDetectsAddOn(): void
    {
        $client = $this->client([self::json(['error' => 'Outrights is a paid add-on. Add it at https://odds-api.io/billing'], 403)]);

        try {
            $client->getOutrights();
            $this->fail('Expected an exception');
        } catch (ForbiddenException $e) {
            $this->assertTrue($e->isAddOnRequired());
        }
    }

    public function testNonJsonErrorBodyIsKeptAsText(): void
    {
        $client = $this->client([new Response(502, [], 'Bad Gateway')]);

        try {
            $client->getSports();
            $this->fail('Expected an exception');
        } catch (ServerException $e) {
            $this->assertSame('Bad Gateway', $e->getMessage());
            $this->assertSame('Bad Gateway', $e->getBody());
        }
    }

    public function testRetriesServerErrorsWhenEnabled(): void
    {
        $client = $this->client([
            self::json(['error' => 'busy'], 503, ['Retry-After' => '1']),
            self::json([['slug' => 'football']]),
        ], maxRetries: 2);

        $this->assertSame('football', $client->getSports()[0]['slug']);
        $this->assertCount(2, $this->history);
        $this->assertSame([1.0], $this->sleeps->getArrayCopy());
    }

    public function testRateLimitLongerThanMaxWaitIsThrownNotSlept(): void
    {
        $client = $this->client([self::json(['error' => 'slow down'], 429, ['Retry-After' => '3600'])], maxRetries: 3);

        $this->expectException(RateLimitException::class);
        try {
            $client->getSports();
        } finally {
            $this->assertCount(0, $this->sleeps);
        }
    }

    public function testClientErrorsAreNotRetried(): void
    {
        $client = $this->client([self::json(['error' => 'bad'], 400)], maxRetries: 3);

        $this->expectException(BadRequestException::class);
        $client->getSports();
    }

    public function testNetworkErrorNeverLeaksApiKey(): void
    {
        $request = new Request('GET', 'https://api.odds-api.io/v3/sports?apiKey=' . self::KEY);
        $client = $this->client([new ConnectException('cURL error 6: Could not resolve host for https://api.odds-api.io/v3/sports?apiKey=' . self::KEY, $request)]);

        try {
            $client->getSports();
            $this->fail('Expected an exception');
        } catch (NetworkException $e) {
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
            $this->assertStringContainsString('***', $e->getMessage());
        }
    }

    public function testDebugInfoHidesApiKey(): void
    {
        $client = new OddsApiClient(self::KEY);

        $this->assertStringNotContainsString(self::KEY, print_r($client, true));
    }

    public function testClientSideLimitsFailFast(): void
    {
        $client = $this->client([]);

        $this->expectException(\InvalidArgumentException::class);
        $client->getOddsForMultipleEvents(range(1, 11), 'Bet365');
    }

    public function testHistoricalSpanIsChecked(): void
    {
        $client = $this->client([]);

        $this->expectException(\InvalidArgumentException::class);
        $client->getHistoricalEvents('football', 'england-premier-league', '2026-01-01T00:00:00Z', '2026-03-01T00:00:00Z');
    }

    public function testIterateEventsPages(): void
    {
        $client = $this->client([
            self::json([['id' => 1], ['id' => 2]]),
            self::json([['id' => 3]]),
        ]);

        $ids = array_map(static fn (array $e): mixed => $e['id'], iterator_to_array($client->iterateEvents('football', 2, league: 'x'), false));

        $this->assertSame([1, 2, 3], $ids);
        $this->assertSame(['2', '2', 'x'], [$this->lastQuery()['limit'], $this->lastQuery()['skip'], $this->lastQuery()['league']]);
    }

    public function testIterateClosingLinesPages(): void
    {
        $client = $this->client([
            self::json([['id' => 1]]),
            self::json([]),
        ]);

        $rows = iterator_to_array($client->iterateClosingLines('football', 'a', '2026-01-01T00:00:00Z', '2026-02-01T00:00:00Z', 'ML', 'Bet365', 1), false);

        $this->assertCount(1, $rows);
        $this->assertSame('1', $this->lastQuery()['skip']);
    }

    public function testIterateMappingsFollowsCursor(): void
    {
        $client = $this->client([
            self::json(['data' => [['marketId' => 'a']], 'nextCursor' => 'c1']),
            self::json(['data' => [['marketId' => 'b']], 'nextCursor' => null]),
        ]);

        $rows = iterator_to_array($client->iterateMappings('Kalshi', sport: 'american-football'), false);

        $this->assertSame(['a', 'b'], array_column($rows, 'marketId'));
        $this->assertSame('c1', $this->lastQuery()['cursor']);
        $this->assertSame('american-football', $this->lastQuery()['sport']);
    }

    public function testCustomBaseUrl(): void
    {
        $this->history = new \ArrayObject();
        $stack = HandlerStack::create(new MockHandler([self::json([])]));
        $container = $this->history;
        $stack->push(Middleware::history($container));
        $client = new OddsApiClient(self::KEY, new Client(['handler' => $stack]), baseUrl: 'http://localhost:8080/v3/');

        $client->getSports();

        $this->assertSame('http://localhost:8080/v3/sports', (string) $this->lastRequest()->getUri()->withQuery(''));
    }

    public function testFormatTime(): void
    {
        $this->assertSame('2023-11-14T22:13:20Z', OddsApiClient::formatTime(1700000000));
        $this->assertSame('2026-01-01', OddsApiClient::formatTime('2026-01-01'));
        $this->assertNull(OddsApiClient::formatTime(null));
    }
}
