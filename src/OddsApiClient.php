<?php

declare(strict_types=1);

namespace OddsApiIo;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use OddsApiIo\Exception\ApiException;
use OddsApiIo\Exception\AuthenticationException;
use OddsApiIo\Exception\BadRequestException;
use OddsApiIo\Exception\ForbiddenException;
use OddsApiIo\Exception\NetworkException;
use OddsApiIo\Exception\NotFoundException;
use OddsApiIo\Exception\OddsApiException;
use OddsApiIo\Exception\RateLimitException;
use OddsApiIo\Exception\ServerException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Client for the Odds-API.io REST API v3.
 *
 * List parameters (bookmakers, markets, event ids, leagues, status) accept a
 * comma-separated string or an array. Dates accept an RFC3339 string, a
 * DateTimeInterface or Unix seconds. Responses are decoded JSON arrays.
 *
 * Every API error throws a subclass of ApiException that keeps the server's
 * message, status code, body and rate-limit headers. The API key is sent as
 * the apiKey query parameter and is never included in exception messages.
 *
 * @phpstan-type Row array<string, mixed>
 * @phpstan-type StrList string|array<int, string>
 * @phpstan-type IdList int|string|array<int, int|string>
 * @phpstan-type TimeLike string|int|\DateTimeInterface
 */
class OddsApiClient
{
    public const VERSION = '0.1.1';
    public const BASE_URL = 'https://api.odds-api.io/v3';
    public const WEBSOCKET_URL = 'wss://api.odds-api.io/v3/ws';

    public const MAX_BOOKMAKERS = 30;
    public const MAX_MARKETS = 40;
    public const MAX_SUMMARY_MARKETS = 20;
    public const MAX_MULTI_EVENTS = 10;
    public const MAX_SETTLEMENT_EVENTS = 50;
    public const MAX_HISTORICAL_SPAN_DAYS = 31;
    public const MAX_CLOSING_LINES_SPAN_DAYS = 366;
    public const MAX_CLOSING_LINES_LEAGUES = 10;
    public const MAX_CLOSING_LINES_PAGE = 100;

    private const RETRY_STATUSES = [429, 500, 502, 503, 504];

    private readonly string $apiKey;
    private readonly string $baseUrl;
    private readonly ClientInterface $httpClient;
    private readonly RequestFactoryInterface $requestFactory;
    private readonly StreamFactoryInterface $streamFactory;
    private ?RateLimit $lastRateLimit = null;
    /** @var array<string, string> */
    private array $lastResponseHeaders = [];

    /**
     * @param string $apiKey your Odds-API.io key (https://odds-api.io/pricing)
     * @param ClientInterface|null $httpClient any PSR-18 client; defaults to Guzzle
     * @param float $timeout seconds per request for the default Guzzle client
     * @param int $maxRetries retries on 429, 5xx and network errors (0 turns them off)
     * @param float $maxRetryWait longest single wait in seconds; a 429 asking for longer is thrown
     */
    public function __construct(
        string $apiKey,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        string $baseUrl = self::BASE_URL,
        float $timeout = 10.0,
        private readonly int $maxRetries = 0,
        private readonly float $maxRetryWait = 30.0,
    ) {
        if (trim($apiKey) === '') {
            throw new \InvalidArgumentException('API key is required');
        }
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->httpClient = $httpClient ?? new GuzzleClient(['timeout' => $timeout]);
        $factory = new HttpFactory();
        $this->requestFactory = $requestFactory ?? $factory;
        $this->streamFactory = $streamFactory ?? $factory;
    }

    /**
     * Keeps the API key out of var_dump() and print_r() output.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'apiKey' => '***',
            'baseUrl' => $this->baseUrl,
            'maxRetries' => $this->maxRetries,
            'lastRateLimit' => $this->lastRateLimit,
        ];
    }

    /**
     * Rate-limit state (limit, remaining, reset) from the most recent response.
     */
    public function getLastRateLimit(): ?RateLimit
    {
        return $this->lastRateLimit;
    }

    /**
     * Headers of the most recent response, keyed by lower-case name.
     *
     * @return array<string, string>
     */
    public function getLastResponseHeaders(): array
    {
        return $this->lastResponseHeaders;
    }

    /**
     * One header of the most recent response, for example "X-Next-Since" or "X-Total-Count".
     */
    public function getLastResponseHeader(string $name): ?string
    {
        return $this->lastResponseHeaders[strtolower($name)] ?? null;
    }

    // Sports, leagues and markets

    /**
     * All sports.
     *
     * @return list<Row>
     */
    public function getSports(): array
    {
        return $this->getList('sports');
    }

    /**
     * Leagues for a sport. Pass $all = true to include leagues without upcoming events.
     *
     * @return list<Row>
     */
    public function getLeagues(string $sport, ?bool $all = null): array
    {
        return $this->getList('leagues', ['sport' => $sport, 'all' => $all]);
    }

    /**
     * The market catalogue: the exact names to pass as "markets". Omit $sport for every sport.
     * Browsable per sport at https://odds-api.io/markets (e.g. https://odds-api.io/markets/football).
     *
     * @return Row
     */
    public function getMarkets(?string $sport = null): array
    {
        return $this->getObject('markets', ['sport' => $sport]);
    }

    // Bookmakers

    /**
     * All bookmakers, with status (ok, degraded, down) and reason.
     * Coverage by sport: https://odds-api.io/sportsbooks#coverage
     *
     * @return list<Row>
     */
    public function getBookmakers(): array
    {
        return $this->getList('bookmakers');
    }

    /**
     * The bookmakers selected for your key.
     *
     * @return Row
     */
    public function getSelectedBookmakers(): array
    {
        return $this->getObject('bookmakers/selected');
    }

    /**
     * Add bookmakers to your selection.
     *
     * @param StrList $bookmakers
     * @return Row
     */
    public function selectBookmakers(string|array $bookmakers): array
    {
        return $this->asObject($this->request('PUT', 'bookmakers/selected/select', ['bookmakers' => $bookmakers]));
    }

    /**
     * Clear your bookmaker selection. Allowed once every 12 hours.
     *
     * @return Row
     */
    public function clearSelectedBookmakers(): array
    {
        return $this->asObject($this->request('PUT', 'bookmakers/selected/clear'));
    }

    // Events

    /**
     * Events with optional filters. Without $to the API returns the next 14 days;
     * each response holds at most 5000 events, so page with $limit and $skip or use iterateEvents().
     *
     * @param StrList|null $status pending, live, settled, cancelled
     * @param TimeLike|null $from
     * @param TimeLike|null $to
     * @return list<Row>
     */
    public function getEvents(
        string $sport,
        ?string $league = null,
        ?int $participantId = null,
        string|array|null $status = null,
        string|int|\DateTimeInterface|null $from = null,
        string|int|\DateTimeInterface|null $to = null,
        ?string $bookmaker = null,
        ?int $limit = null,
        ?int $skip = null,
    ): array {
        return $this->getList('events', [
            'sport' => $sport,
            'league' => $league,
            'participantId' => $participantId,
            'status' => $status,
            'from' => self::formatTime($from),
            'to' => self::formatTime($to),
            'bookmaker' => $bookmaker,
            'limit' => $limit,
            'skip' => $skip,
        ]);
    }

    /**
     * Every event matching the filters, paging with limit and skip.
     * Accepts the same named filters as getEvents() except limit and skip.
     *
     * @return \Generator<int, Row>
     */
    public function iterateEvents(string $sport, int $pageSize = 1000, mixed ...$filters): \Generator
    {
        $skip = 0;
        while (true) {
            $page = $this->getEvents($sport, ...$filters, limit: $pageSize, skip: $skip);
            yield from $page;
            if (count($page) < $pageSize) {
                return;
            }
            $skip += count($page);
        }
    }

    /**
     * One event by ID.
     *
     * @return Row
     */
    public function getEventById(int|string $id): array
    {
        return $this->getObject('events/' . rawurlencode((string) $id));
    }

    /**
     * Live events, for one sport or all. Live events carry a clock.
     *
     * @return list<Row>
     */
    public function getLiveEvents(?string $sport = null): array
    {
        return $this->getList('events/live', ['sport' => $sport]);
    }

    /**
     * Search events by team or league name (min 3 characters, up to 10 results).
     *
     * @param string|null $status "upcoming" (default) or "live"
     * @return list<Row>
     */
    public function searchEvents(string $query, ?string $status = null): array
    {
        return $this->getList('events/search', ['query' => $query, 'status' => $status]);
    }

    // Odds

    /**
     * Odds for one event.
     *
     * @param StrList $bookmakers up to 30, e.g. ['Bet365', 'SingBet']
     * @param StrList|null $markets up to 40, e.g. ['ML', 'Spread', 'Totals']; omit for every market.
     *     Every name per sport: https://odds-api.io/markets
     * @param bool|null $includeSeq true to get the WebSocket sequence in the X-OddsAPI-Seq header
     * @return Row
     */
    public function getEventOdds(
        int|string $eventId,
        string|array $bookmakers,
        string|array|null $markets = null,
        ?bool $includeSeq = null,
    ): array {
        self::checkMax('bookmakers', $bookmakers, self::MAX_BOOKMAKERS);
        self::checkMax('markets', $markets, self::MAX_MARKETS);

        return $this->getObject('odds', [
            'eventId' => $eventId,
            'bookmakers' => $bookmakers,
            'markets' => $markets,
            'includeSeq' => $includeSeq,
        ]);
    }

    /**
     * Odds for up to 10 events in one request (counts as one call).
     *
     * @param IdList $eventIds
     * @param StrList $bookmakers
     * @param StrList|null $markets
     * @return list<Row>
     */
    public function getOddsForMultipleEvents(
        int|string|array $eventIds,
        string|array $bookmakers,
        string|array|null $markets = null,
        ?bool $includeSeq = null,
    ): array {
        self::checkMax('eventIds', $eventIds, self::MAX_MULTI_EVENTS);
        self::checkMax('bookmakers', $bookmakers, self::MAX_BOOKMAKERS);
        self::checkMax('markets', $markets, self::MAX_MARKETS);

        return $this->getList('odds/multi', [
            'eventIds' => $eventIds,
            'bookmakers' => $bookmakers,
            'markets' => $markets,
            'includeSeq' => $includeSeq,
        ]);
    }

    /**
     * Odds that changed since a Unix timestamp (a delta, not a snapshot): each
     * event carries only the markets that changed. Pass
     * getLastResponseHeader('X-Next-Since') as $since on the next call to continue with no gap.
     *
     * @param int $since Unix seconds, at most 90 seconds old
     * @param StrList $markets required, up to 40
     * @return list<Row>
     */
    public function getUpdatedOddsSince(
        int $since,
        string $bookmaker,
        string $sport,
        string|array $markets,
        ?int $limit = null,
    ): array {
        self::checkMax('markets', $markets, self::MAX_MARKETS);

        return $this->getList('odds/updated', [
            'since' => $since,
            'bookmaker' => $bookmaker,
            'sport' => $sport,
            'markets' => $markets,
            'limit' => $limit,
        ]);
    }

    /**
     * Pre-match price history of one market line: {opening, latest, movements}.
     *
     * @param string|float|null $marketLine e.g. 2.5 or -1.25; omit for the main line
     * @return Row
     */
    public function getOddsMovement(
        int|string $eventId,
        string $bookmaker,
        string $market,
        string|float|null $marketLine = null,
    ): array {
        return $this->getObject('odds/movements', [
            'eventId' => $eventId,
            'bookmaker' => $bookmaker,
            'market' => $market,
            'marketLine' => $marketLine,
        ]);
    }

    /**
     * Best, average, median, worst and fair price per line across bookmakers.
     * Growth plans and above; lower plans get a sample with demo = true.
     *
     * @param StrList|null $markets up to 20; default ML, Spread, Totals
     * @param string|null $lines "main" (default) or "all"
     * @param StrList|null $bookmakers narrow to some of your selected bookmakers
     * @return Row
     */
    public function getOddsSummary(
        int|string $eventId,
        string|array|null $markets = null,
        ?string $lines = null,
        string|array|null $bookmakers = null,
        ?bool $includeExchanges = null,
    ): array {
        self::checkMax('markets', $markets, self::MAX_SUMMARY_MARKETS);

        return $this->getObject('odds/summary', [
            'eventId' => $eventId,
            'markets' => $markets,
            'lines' => $lines,
            'bookmakers' => $bookmakers,
            'includeExchanges' => $includeExchanges,
        ]);
    }

    /**
     * Odds summary for up to 10 events in one request.
     *
     * @param IdList $eventIds
     * @param StrList|null $markets
     * @param StrList|null $bookmakers
     * @return list<Row>
     */
    public function getOddsSummaryMulti(
        int|string|array $eventIds,
        string|array|null $markets = null,
        ?string $lines = null,
        string|array|null $bookmakers = null,
        ?bool $includeExchanges = null,
    ): array {
        self::checkMax('eventIds', $eventIds, self::MAX_MULTI_EVENTS);
        self::checkMax('markets', $markets, self::MAX_SUMMARY_MARKETS);

        return $this->getList('odds/summary/multi', [
            'eventIds' => $eventIds,
            'markets' => $markets,
            'lines' => $lines,
            'bookmakers' => $bookmakers,
            'includeExchanges' => $includeExchanges,
        ]);
    }

    // Value bets, arbitrage, dropping odds

    /**
     * Prices above a fair-odds estimate at one bookmaker. expectedValue is
     * bookmaker odds / fair odds x 100, so the edge in percent is expectedValue - 100.
     *
     * @return list<Row>
     */
    public function getValueBets(
        string $bookmaker,
        ?string $sport = null,
        ?string $league = null,
        ?bool $includeEventDetails = null,
    ): array {
        return $this->getList('value-bets', [
            'bookmaker' => $bookmaker,
            'sport' => $sport,
            'league' => $league,
            'includeEventDetails' => $includeEventDetails,
        ]);
    }

    /**
     * Pre-match price gaps where the best odds across bookmakers imply under 100%.
     * Prices move and bookmakers can void or limit bets, so check every leg before acting.
     *
     * @param StrList $bookmakers bookmakers every leg must come from
     * @param StrList|null $markets
     * @param float|null $minProfit minimum profitMargin in percent
     * @param int|null $limit default 50, max 500
     * @return list<Row>
     */
    public function getArbitrageBets(
        string|array $bookmakers,
        ?string $sport = null,
        ?string $league = null,
        string|array|null $markets = null,
        ?float $minProfit = null,
        ?bool $allCombinations = null,
        ?int $limit = null,
        ?bool $includeEventDetails = null,
    ): array {
        return $this->getList('arbitrage-bets', [
            'bookmakers' => $bookmakers,
            'sport' => $sport,
            'league' => $league,
            'markets' => $markets,
            'minProfit' => $minProfit,
            'allCombinations' => $allCombinations,
            'limit' => $limit,
            'includeEventDetails' => $includeEventDetails,
        ]);
    }

    /**
     * Prices that dropped at sharp bookmakers (paid plans). The total count is
     * in getLastResponseHeader('X-Total-Count').
     *
     * @param StrList|null $leagues
     * @param StrList|null $markets
     * @param string|null $timeWindow "opening" (default), "12h", "24h" or "48h"
     * @param string|null $sort "drop" (default), "recent" or "kickoff"
     * @param int|null $page 1-based
     * @return list<Row>
     */
    public function getDroppingOdds(
        ?string $sport = null,
        ?string $league = null,
        string|array|null $leagues = null,
        string|array|null $markets = null,
        ?string $timeWindow = null,
        ?string $sort = null,
        ?float $minDrop = null,
        ?int $limit = null,
        ?int $page = null,
        ?bool $includeEventDetails = null,
    ): array {
        return $this->getList('dropping-odds', [
            'sport' => $sport,
            'league' => $league,
            'leagues' => $leagues,
            'markets' => $markets,
            'timeWindow' => $timeWindow,
            'sort' => $sort,
            'minDrop' => $minDrop,
            'limit' => $limit,
            'page' => $page,
            'includeEventDetails' => $includeEventDetails,
        ]);
    }

    // Historical

    /**
     * Finished events for one league in a range of at most 31 days.
     *
     * @param TimeLike $from
     * @param TimeLike $to
     * @return list<Row>
     */
    public function getHistoricalEvents(
        string $sport,
        string $league,
        string|int|\DateTimeInterface $from,
        string|int|\DateTimeInterface $to,
    ): array {
        $start = self::formatTime($from);
        $end = self::formatTime($to);
        self::checkSpan($start, $end, self::MAX_HISTORICAL_SPAN_DAYS);

        return $this->getList('historical/events', [
            'sport' => $sport,
            'league' => $league,
            'from' => $start,
            'to' => $end,
        ]);
    }

    /**
     * Search finished events by team name (min 3 characters). The range is at most 31 days.
     *
     * @param TimeLike|null $from
     * @param TimeLike|null $to
     * @return list<Row>
     */
    public function searchHistoricalEvents(
        string $query,
        ?string $sport = null,
        string|int|\DateTimeInterface|null $from = null,
        string|int|\DateTimeInterface|null $to = null,
    ): array {
        $start = self::formatTime($from);
        $end = self::formatTime($to);
        self::checkSpan($start, $end, self::MAX_HISTORICAL_SPAN_DAYS);

        return $this->getList('historical/events/search', [
            'query' => $query,
            'sport' => $sport,
            'from' => $start,
            'to' => $end,
        ]);
    }

    /**
     * Stored odds of a finished event (same shape as getEventOdds()).
     *
     * @param StrList $bookmakers
     * @param StrList|null $markets
     * @return Row
     */
    public function getHistoricalOdds(
        int|string $eventId,
        string|array $bookmakers,
        string|array|null $markets = null,
    ): array {
        self::checkMax('bookmakers', $bookmakers, self::MAX_BOOKMAKERS);

        return $this->getObject('historical/odds', [
            'eventId' => $eventId,
            'bookmakers' => $bookmakers,
            'markets' => $markets,
        ]);
    }

    /**
     * Closing odds for every settled event in up to 10 leagues (paid plans).
     * The range may span 366 days; each response holds at most 100 events,
     * so page with $skip or use iterateClosingLines().
     *
     * @param StrList $leagues
     * @param TimeLike $from
     * @param TimeLike $to
     * @param StrList $markets
     * @param StrList $bookmakers
     * @return list<Row>
     */
    public function getClosingLines(
        string $sport,
        string|array $leagues,
        string|int|\DateTimeInterface $from,
        string|int|\DateTimeInterface $to,
        string|array $markets,
        string|array $bookmakers,
        ?int $limit = null,
        ?int $skip = null,
    ): array {
        $start = self::formatTime($from);
        $end = self::formatTime($to);
        self::checkSpan($start, $end, self::MAX_CLOSING_LINES_SPAN_DAYS);
        self::checkMax('leagues', $leagues, self::MAX_CLOSING_LINES_LEAGUES);
        self::checkMax('bookmakers', $bookmakers, self::MAX_BOOKMAKERS);

        return $this->getList('historical/closing-lines', [
            'sport' => $sport,
            'leagues' => $leagues,
            'from' => $start,
            'to' => $end,
            'markets' => $markets,
            'bookmakers' => $bookmakers,
            'limit' => $limit,
            'skip' => $skip,
        ]);
    }

    /**
     * Every closing line in the range, paging with skip.
     *
     * @param StrList $leagues
     * @param TimeLike $from
     * @param TimeLike $to
     * @param StrList $markets
     * @param StrList $bookmakers
     * @return \Generator<int, Row>
     */
    public function iterateClosingLines(
        string $sport,
        string|array $leagues,
        string|int|\DateTimeInterface $from,
        string|int|\DateTimeInterface $to,
        string|array $markets,
        string|array $bookmakers,
        int $pageSize = self::MAX_CLOSING_LINES_PAGE,
    ): \Generator {
        $skip = 0;
        while (true) {
            $page = $this->getClosingLines($sport, $leagues, $from, $to, $markets, $bookmakers, $pageSize, $skip);
            yield from $page;
            if (count($page) < $pageSize) {
                return;
            }
            $skip += count($page);
        }
    }

    // Settlement and grading

    /**
     * Status, final score and the final flag for up to 50 events (paid plans).
     *
     * @param IdList $eventIds
     * @return Row
     */
    public function getSettlements(int|string|array $eventIds): array
    {
        self::checkMax('eventIds', $eventIds, self::MAX_SETTLEMENT_EVENTS);

        return $this->getObject('settlements', ['eventIds' => $eventIds]);
    }

    /**
     * Grade up to 50 bets as won, lost, void, half_won or half_lost (paid plans).
     * Each selection needs eventId, market and side, plus hdp on markets with a
     * line and label for player props; ref is echoed back. Re-send selections
     * whose grade is not final, at most once a minute.
     *
     * @param array<int, array<string, mixed>> $selections
     * @return Row
     */
    public function gradeBets(array $selections): array
    {
        self::checkMax('selections', $selections, self::MAX_SETTLEMENT_EVENTS);

        return $this->asObject($this->request('POST', 'settlements/grade', [], ['selections' => array_values($selections)]));
    }

    // Outrights (add-on)

    /**
     * Outright tournaments (golf). Needs the Outrights add-on; without it a
     * ForbiddenException with isAddOnRequired() = true is thrown.
     *
     * @param string|null $league pga-tour, dp-world-tour, liv-golf or majors
     * @param StrList|null $status pending, live, settled, cancelled (default pending,live)
     * @return list<Row>
     */
    public function getOutrights(string $sport = 'golf', ?string $league = null, string|array|null $status = null): array
    {
        return $this->getList('outrights', ['sport' => $sport, 'league' => $league, 'status' => $status]);
    }

    /**
     * Prices for one tournament: Winner, Top 5/10/20, their "(Incl. Ties)" variants and Make Cut.
     *
     * @param StrList $bookmakers
     * @return Row
     */
    public function getOutrightOdds(int|string $id, string|array $bookmakers): array
    {
        self::checkMax('bookmakers', $bookmakers, self::MAX_BOOKMAKERS);

        return $this->getObject('outrights/odds', ['id' => $id, 'bookmakers' => $bookmakers]);
    }

    /**
     * Final positions and per-market winners. Empty until the tournament is settled.
     *
     * @return Row
     */
    public function getOutrightResults(int|string $id): array
    {
        return $this->getObject('outrights/results', ['id' => $id]);
    }

    // Participants

    /**
     * Teams and players for a sport.
     *
     * @return list<Row>
     */
    public function getParticipants(string $sport, ?string $search = null): array
    {
        return $this->getList('participants', ['sport' => $sport, 'search' => $search]);
    }

    /**
     * One participant by ID.
     *
     * @return Row
     */
    public function getParticipantById(int|string $id): array
    {
        return $this->getObject('participants/' . rawurlencode((string) $id));
    }

    /**
     * A participant's crest as PNG bytes. Throws NotFoundException when there is none.
     */
    public function getParticipantLogo(int|string $id): string
    {
        $body = $this->request('GET', 'participants/' . rawurlencode((string) $id) . '/logo', [], null, true);

        return is_string($body) ? $body : '';
    }

    // Mappings

    /**
     * A bookmaker's own event, market and outcome ids for our events (paid plans).
     * Look up by event or market ids, or pass none for a bulk sync paged by
     * cursor (see iterateMappings()).
     *
     * @param IdList|null $eventIds
     * @param StrList|null $marketIds
     * @return Row {data: list, nextCursor: ?string}
     */
    public function getMappings(
        string $bookmaker,
        int|string|null $eventId = null,
        int|string|array|null $eventIds = null,
        ?string $marketId = null,
        string|array|null $marketIds = null,
        ?string $outcomeId = null,
        ?string $market = null,
        ?bool $active = null,
        ?string $sport = null,
        ?string $league = null,
        ?int $limit = null,
        ?string $cursor = null,
    ): array {
        return $this->getObject('mappings', [
            'bookmaker' => $bookmaker,
            'eventId' => $eventId,
            'eventIds' => $eventIds,
            'marketId' => $marketId,
            'marketIds' => $marketIds,
            'outcomeId' => $outcomeId,
            'market' => $market,
            'active' => $active,
            'sport' => $sport,
            'league' => $league,
            'limit' => $limit,
            'cursor' => $cursor,
        ]);
    }

    /**
     * Every mapping row, following nextCursor. Accepts the named filters of getMappings().
     *
     * @return \Generator<int, Row>
     */
    public function iterateMappings(string $bookmaker, mixed ...$filters): \Generator
    {
        $cursor = $filters['cursor'] ?? null;
        unset($filters['cursor']);
        do {
            $page = $this->getMappings($bookmaker, ...$filters, cursor: is_string($cursor) ? $cursor : null);
            $rows = $page['data'] ?? [];
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    yield $row;
                }
            }
            $cursor = $page['nextCursor'] ?? null;
        } while (is_string($cursor) && $cursor !== '');
    }

    // Transport

    /**
     * @param array<string, mixed> $params
     * @return list<Row>
     */
    private function getList(string $path, array $params = []): array
    {
        $data = $this->request('GET', $path, $params);
        if ($data === null) {
            return [];
        }
        if (!is_array($data) || !array_is_list($data)) {
            throw new OddsApiException(sprintf('Unexpected response from /%s: expected a JSON array', $path));
        }

        /** @var list<Row> $data */
        return $data;
    }

    /**
     * @param array<string, mixed> $params
     * @return Row
     */
    private function getObject(string $path, array $params = []): array
    {
        return $this->asObject($this->request('GET', $path, $params));
    }

    /**
     * @return Row
     */
    private function asObject(mixed $data): array
    {
        if ($data === null) {
            return [];
        }
        if (!is_array($data)) {
            throw new OddsApiException('Unexpected response: expected a JSON object');
        }

        /** @var Row $data */
        return $data;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed>|null $json
     */
    private function request(string $method, string $path, array $params = [], ?array $json = null, bool $raw = false): mixed
    {
        $query = self::buildQuery($params + ['apiKey' => $this->apiKey]);
        $url = $this->baseUrl . '/' . $path . '?' . $query;

        $attempt = 0;
        while (true) {
            $request = $this->requestFactory->createRequest($method, $url)
                ->withHeader('Accept', $raw ? '*/*' : 'application/json')
                ->withHeader('User-Agent', 'odds-api-io-php/' . self::VERSION);
            if ($json !== null) {
                $request = $request
                    ->withHeader('Content-Type', 'application/json')
                    ->withBody($this->streamFactory->createStream(json_encode($json, JSON_THROW_ON_ERROR)));
            }

            try {
                $response = $this->httpClient->sendRequest($request);
            } catch (ClientExceptionInterface $e) {
                $error = new NetworkException(
                    sprintf('Request to /%s failed: %s', $path, $this->redact($e->getMessage())),
                );
                $delay = $this->nextRetryDelay($attempt, $error);
                if ($delay === null) {
                    throw $error;
                }
                $this->sleep($delay);
                $attempt++;
                continue;
            }

            $headers = self::normalizeHeaders($response);
            $this->lastResponseHeaders = $headers;
            $this->lastRateLimit = RateLimit::fromHeaders($headers) ?? $this->lastRateLimit;
            $content = (string) $response->getBody();
            $status = $response->getStatusCode();

            if ($status >= 200 && $status < 300) {
                return $raw ? $content : self::decode($content);
            }

            $error = $this->buildError($status, $content, $headers);
            $delay = $this->nextRetryDelay($attempt, $error);
            if ($delay === null) {
                throw $error;
            }
            $this->sleep($delay);
            $attempt++;
        }
    }

    /**
     * Waits before a retry. Override in tests to avoid real sleeps.
     */
    protected function sleep(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) round($seconds * 1_000_000));
        }
    }

    private function nextRetryDelay(int $attempt, OddsApiException $error): ?float
    {
        if ($attempt >= $this->maxRetries) {
            return null;
        }
        if ($error instanceof ApiException && !in_array($error->getStatusCode(), self::RETRY_STATUSES, true)) {
            return null;
        }
        $retryAfter = match (true) {
            $error instanceof RateLimitException, $error instanceof ServerException => $error->getRetryAfter(),
            default => null,
        };
        if ($retryAfter !== null) {
            return $retryAfter <= $this->maxRetryWait ? $retryAfter : null;
        }
        $backoff = min(0.5 * (2 ** $attempt), 8.0);

        return min($backoff + $backoff / 4 * (mt_rand() / mt_getrandmax()), $this->maxRetryWait);
    }

    /**
     * @param array<string, string> $headers
     */
    private function buildError(int $status, string $content, array $headers): ApiException
    {
        $body = self::decode($content);
        $message = $this->redact(self::errorMessage($body, $status));
        $rateLimit = RateLimit::fromHeaders($headers);

        return match (true) {
            $status === 400 => new BadRequestException($message, $status, $body, $headers, $rateLimit),
            $status === 401 => new AuthenticationException($message, $status, $body, $headers, $rateLimit),
            $status === 403 => new ForbiddenException($message, $status, $body, $headers, $rateLimit),
            $status === 404 => new NotFoundException($message, $status, $body, $headers, $rateLimit),
            $status === 429 => new RateLimitException($message, $status, $body, $headers, $rateLimit, self::retryAfter($headers)),
            $status >= 500 => new ServerException($message, $status, $body, $headers, $rateLimit, self::retryAfter($headers)),
            default => new ApiException($message, $status, $body, $headers, $rateLimit),
        };
    }

    private function redact(string $text): string
    {
        return str_replace([$this->apiKey, rawurlencode($this->apiKey)], '***', $text);
    }

    private static function errorMessage(mixed $body, int $status): string
    {
        if (is_array($body)) {
            foreach (['error', 'message', 'detail'] as $key) {
                if (isset($body[$key]) && is_string($body[$key]) && $body[$key] !== '') {
                    return $body[$key];
                }
            }

            return json_encode($body) ?: sprintf('HTTP %d', $status);
        }
        if (is_string($body) && trim($body) !== '') {
            return trim($body);
        }

        return sprintf('HTTP %d', $status);
    }

    /**
     * @param array<string, string> $headers
     */
    private static function retryAfter(array $headers): ?float
    {
        $now = time();
        $raw = trim($headers['retry-after'] ?? '');
        if ($raw !== '') {
            if (is_numeric($raw)) {
                return max(0.0, (float) $raw);
            }
            $when = strtotime($raw);
            if ($when !== false) {
                return (float) max(0, $when - $now);
            }
        }
        $reset = RateLimit::fromHeaders($headers)?->reset;
        if ($reset !== null) {
            return (float) max(0, $reset->getTimestamp() - $now);
        }

        return null;
    }

    private static function decode(string $content): mixed
    {
        if ($content === '') {
            return null;
        }
        try {
            return json_decode($content, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            return $content;
        }
    }

    /**
     * @return array<string, string>
     */
    private static function normalizeHeaders(ResponseInterface $response): array
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = implode(', ', $values);
        }

        return $headers;
    }

    /**
     * Drops nulls, joins lists with commas, sends booleans as true/false.
     *
     * @param array<string, mixed> $params
     */
    private static function buildQuery(array $params): string
    {
        $query = [];
        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (is_array($value)) {
                $value = implode(',', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $value));
            } elseif ($value instanceof \DateTimeInterface) {
                $value = self::formatTime($value);
            }
            $query[$key] = is_scalar($value) ? (string) $value : '';
        }

        return http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * RFC3339 UTC for DateTimeInterface and Unix seconds; strings pass through unchanged.
     */
    public static function formatTime(string|int|\DateTimeInterface|null $value): ?string
    {
        if ($value === null || is_string($value)) {
            return $value;
        }
        if (is_int($value)) {
            $value = new \DateTimeImmutable('@' . $value);
        }

        return \DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * @param string|int|array<int|string, mixed>|null $value
     */
    private static function checkMax(string $name, string|int|array|null $value, int $max): void
    {
        if (is_string($value)) {
            $count = count(array_filter(array_map('trim', explode(',', $value)), static fn (string $v): bool => $v !== ''));
        } elseif (is_array($value)) {
            $count = count($value);
        } else {
            return;
        }
        if ($count > $max) {
            throw new \InvalidArgumentException(sprintf('%s: at most %d allowed, got %d', $name, $max, $count));
        }
    }

    private static function checkSpan(?string $from, ?string $to, int $maxDays): void
    {
        if ($from === null || $to === null) {
            return;
        }
        try {
            $start = new \DateTimeImmutable($from, new \DateTimeZone('UTC'));
            $end = new \DateTimeImmutable($to, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return;
        }
        if ($start >= $end) {
            throw new \InvalidArgumentException("'from' must be before 'to'");
        }
        if ($end->getTimestamp() - $start->getTimestamp() > $maxDays * 86400) {
            throw new \InvalidArgumentException(sprintf('The date range may span at most %d days', $maxDays));
        }
    }
}
