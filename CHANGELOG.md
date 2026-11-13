# Changelog

## Unreleased

- Docs: `getMarkets()`, `getBookmakers()`, the `$markets` parameter of `getEventOdds()` and the README link to the market reference at https://odds-api.io/markets and bookmaker coverage at https://odds-api.io/sportsbooks#coverage.

## 0.1.0 (2026-11-11)

First release.

- `OddsApiClient` for every public REST v3 endpoint: sports, leagues, markets, bookmakers (list, select, clear), events (list, by id, live, search), odds (single, multi, updated, movements, summary, summary multi), value bets, arbitrage bets, dropping odds, historical events, search and odds, closing lines, settlements and bet grading, golf outrights (list, odds, results), participants (list, by id, logo) and bookmaker mappings.
- Generators that page for you: `iterateEvents`, `iterateClosingLines`, `iterateMappings`.
- Typed exceptions with the server's message, status, body and rate-limit headers: `BadRequestException`, `AuthenticationException`, `ForbiddenException`, `NotFoundException`, `RateLimitException` (with `getRetryAfter()`), `ServerException`, `NetworkException`.
- Rate-limit tracking with `getLastRateLimit()` and response headers with `getLastResponseHeader()`.
- Optional retries with backoff on 429, 5xx and network errors.
- Guzzle by default; any PSR-18 client can be passed in.
