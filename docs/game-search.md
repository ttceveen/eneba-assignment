# Game search

`GET /api/games/search` uses PostgreSQL `pg_trgm`. The migration enables the extension; a non-PostgreSQL connection or missing `word_similarity(text,text)` returns 503 rather than silently changing search semantics.

## Requests and ranking

- `q`: required trimmed Unicode string, at most 255 characters.
- `page`: optional positive integer bounded against offset overflow; full results use 12-item pagination.
- `preview`: optional boolean; preview returns at most six games plus unique title suggestions.

Matching uses case-insensitive literal substring search or `word_similarity(LOWER(query), LOWER(name)) >= 0.45`. Ranking prefers exact case-insensitive title, then prefix, then substring, then other fuzzy matches; similarity descending and game ID provide deterministic ordering. LIKE metacharacters are escaped and all SQL values are bound parameters. Search excludes inactive games and includes the existing public game/offer-summary fields, not key data.

The header `SearchPanel.tsx` opens a modal with focus management, Escape/close controls, loading/empty/error states and a 280 ms debounce. It cancels superseded requests and ignores stale responses. Submitting or choosing a suggestion opens full query results. Game preview/results links carry return context; Clear search restores the original non-search catalog. See [navigation](catalog-navigation.md).

## Limitations

The current fuzzy expression can scan active names. There is no dedicated search throttle or new candidate/index scheme. Measure the actual endpoint with realistic data and its aggregates before adding an index or changing matching/ranking. The small seeded catalog is not a load benchmark.

## Verification

Native tests validate payloads/transport, Feature tests validate query inputs and errors, and the disposable PostgreSQL search suite exercises actual substring/fuzzy ranking, escaping, activity, preview and pagination. Browser tests cover navigation, query-preserving Retry and Clear search. See [testing](testing.md).

Historical search validation used eight PostgreSQL tests/153 assertions in a fresh isolated database. It did not contact Neon or use real payment inventory.
