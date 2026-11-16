# Catalog navigation

## Ordering and pagination

`GameController::index()` returns active games in 12-item pages. The default popular order joins `game_popularities` and sorts by `purchase_count DESC, game_id ASC`. `sort=alphabetical` uses `LOWER(games.name), games.id` without the popularity join. Other sort values use the default. Page input is validated against integer/offset overflow.

The header's Games link opens popular page 1; All Games opens alphabetical page 1. Search uses `q` and the existing search endpoint. URL query parameters are the source of catalog selection, not a separate global page store.

## Returning from a game

`catalogHistory.ts` validates catalog entries containing a history key, URL and optional reachable history index. `GameCard` and search-preview links pass that context when opening Game Details. The optional `includeSearch` mode preserves a searched catalog's query/page rather than replacing it with the earlier non-search catalog.

The Game Details “Games” link returns to the originating URL, using a reachable history entry when available. Its fallback carries `restoreCatalogKey` for position restoration. Direct game entry without an originating link returns to `/`; it does not adopt an unrelated remembered catalog. The header Games/logo links remain explicit navigation to the default catalog.

`SearchPanel` retains its existing non-search catalog context for Clear search. Including search for game-return links does not change that behavior. Optional search-panel drafts are scoped by history entry; browser Back can restore the relevant panel state.

## Scroll and async state

`useCatalogScroll` saves a visible/clicked game ID, offset, scroll Y and main height in sessionStorage. Restoration waits for API data and layout, then anchors the same game at its saved offset. If absent, it uses bounded scroll Y without fetching other pages. Normal new catalog navigation starts at the top. Storage failure leaves ordinary navigation usable without restoration.

The loader remounts on history/query/sort/page changes and aborts stale requests. Catalog/game error states now offer local Retry. Retry repeats only that GET without a document reload or parameter reset; a loading state removes the retry action and a synchronous disabled guard prevents duplicate clicks.

## Verification and limits

`navigation-feedback.browser.mjs` checks sort/search/page and scroll restoration, direct entry, Clear search, single-request Retry and return context after an error. Native API and PostgreSQL tests cover ordering/pagination/search. [Testing commands](testing.md) also run the mobile/purchase regression suites.

Historical verification included 12/12/12/4 records for the 40-game fixture, offset restoration within roughly 1.5 CSS px and five viewport widths. Those observations describe the measured fixture, not a fixed production total or a large-catalog performance guarantee. The UI currently renders all page-number buttons; a large catalog would need a bounded window.
