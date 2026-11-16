# Historical technical audit and current context

## Scope of the archived assessment

The original analytical audit was performed on **12 November 2026**, including the then-existing working-tree changes. It made no implementation fixes. Its baseline contained catalog/search, offers, cart and Bearer authentication; Checkout, payment processing and order-key delivery had not yet been implemented. Statements about their absence apply only to that baseline.

The original assessment recorded zero Critical/High issues, eight Medium findings and eight Low findings. Findings included reproduced bugs and explicitly conditional growth risks, not sixteen demonstrated exploits. It did not certify a real-money marketplace.

This English reference preserves the finding IDs, evidence, architectural conclusions and verification boundaries. Current behavior is documented in the [README](../README.md), [Checkout services](checkout-services.md), [payments](payments.md), [authentication](authentication.md) and [cart](frontend-cart.md). AI transcripts and historical prompts are separate records and have not been rewritten.

## Architecture and performance observations

The code followed Laravel controllers/resources/models/middleware and frontend pages/contexts/hooks. It was a conventional layered monolith with partially grouped frontend responsibilities, not explicit independent bounded-context modules or microservices. New abstractions solely to satisfy a label were not recommended.

Catalog queries were bounded to 12 records. Eager loading and SQL aggregates avoided per-offer network-query growth, but constant query count did not mean constant cost. Game Details loaded all available offers, page navigation rendered every page number, and fuzzy search could scan active names. Deep OFFSET and data volume required realistic measurement before optimization.

A read-only local EXPLAIN on the small fixture showed sequential scan/sort behavior for representative search/alphabetical predicates. It was not a full load test or a basis for blindly adding GIN/functional indexes. A useful search index requires an indexable candidate predicate while preserving ranking. FK and maintenance indexing should likewise follow actual access patterns.

Sanctum tokens were database-backed, but instance-local file cache limited distributed rate-limit consistency. Render client-IP/proxy interpretation was not inspected. DigitalKeySeeder loaded/decrypted all keys and was suitable only for the small synthetic fixture, not a production importer.

## Historical findings and present relevance

“Historical” below preserves what the original audit found; it does not assert that the same issue remains reproducible today. Follow current tests/source for current status.

| ID | Original priority | Finding / evidence | Current context or follow-up |
| --- | --- | --- | --- |
| F01 | Medium | Catalog/details used materialized quantity while batch cart availability also counted actual keys. A quantity-five offer without keys was displayed differently across endpoints. | The current batch still bounds by available keys; privileged counter drift can differ from catalog summaries. Checkout now independently locks/validates both and refuses missing inventory. Do not describe the read paths as identical. |
| F02 | Medium | A stale details tab with stock two overwrote another tab's newer quantity three after a stock-five refresh. | Cart mutations now read current snapshots; stock-response version guards and persistent cross-tab regression tests were added. |
| F03 | Medium | A stored cart with 101 unique offers passed old parsing but exceeded the batch loader's 100-ID limit, preventing all loading. | Current cart normalization/addition is bounded to 20 offers; legacy oversized snapshots and rejection feedback are tested. |
| F04 | Medium | Failed Logout invalidated an in-flight `/me` without replacing it, leaving a protected page loading. | AuthProvider now schedules recovery and rejects stale replies; retained browser cases cover failure ordering. |
| F05 | Low | An out-of-BIGINT numeric route ID produced 500 rather than a controlled response. | This historical range-validation finding should be judged against each current controller/binding path; new payment controllers explicitly validate IDs. No blanket resolution is claimed here. |
| F06 | Medium | A game's whole offer collection was loaded/rendered without a limit. | Still a small-catalog scaling boundary. Add bounded offer retrieval only when the product/data volume requires it. |
| F07 | Low | UI generated every page button from last_page. | Still a growth boundary; a bounded window is appropriate for a much larger catalog. |
| F08 | Medium | Fuzzy search had no dedicated throttle and used a potentially scanning expression. | Still a measured-growth/abuse-resistance consideration, not evidence of present overload. |
| F09 | Medium | File cache made limiter state instance-local. | Shared limiter storage and verified proxy/IP behavior are needed before multi-instance scaling; not a reason to add Redis to the current small demo automatically. |
| F10 | Low | Login/register used implicit throttling buckets with a shared-policy concern. | Keep the actual throttle policy explicit; named independent limits are a possible future refinement. |
| F11 | Low | Strict TypeScript intent was not explicit in config. | Historical configuration recommendation; compiler versions/defaults and a successful run do not alone document project intent. |
| F12 | Medium | There were no retained React/Router/storage browser regressions or verified CI gate. | Browser suites now exist for auth/cart/navigation/mobile/purchases/PayPal. A complete automated CI/release pipeline is not claimed. |
| F13 | Low | Expired personal access tokens were rejected but not pruned on a repository-defined schedule. | Still an operational cleanup consideration; it does not bypass token expiration. |
| F14 | Low | Generated Laravel package/service manifests were reported as versioned during the historical audit. | Version-control state was not re-inspected during documentation maintenance. Production build excludes local caches and regenerates discovery; the finding was not proof of dev providers running in production. |
| F15 | Low | Unreferenced scaffold assets and an assertTrue-only unit example were cleanup candidates. | Optional separate cleanup, not a runtime incident. Generated manifests, dynamic package config, migrations and package routes must not be deleted solely because imports are absent. |
| F16 | Low | Pint reported single-quote style differences in GameSeeder/GamePopularityTest. | Historical formatting observation, not a runtime/PSR claim. Check current files separately rather than mixing formatting into feature work. |

## Reproduction details retained

- F01 compared real Resources/HTTP-kernel results in an isolated DB: details had quantity five and one offer, while batch had zero/false when no digital keys existed. It was not proof of overselling because Checkout was absent then; current Checkout validates actual keys.
- F02 used two Chromium tabs and demonstrated a stale storage write, not just an assumed React dependency problem. The fix needed current-state mutation and response guards, not CRDT/server-cart architecture.
- F03 made zero offer requests because client validation rejected the oversized list before fetch. Existing items were not destroyed; manual removal was still possible. A consistent product limit was preferred over batch complexity without a requirement for huge baskets.
- F04 reproduced multiple response orders around failed Logout and pending auth restoration. Restoring verification after failure had to preserve successful-Logout stale-response protection.
- F12's original green native suites did not mount React/Router, so they could not detect the reproduced lifecycle bugs. Later browser regressions address that coverage gap.

## Historical security assessment

Sanctum issued seven-day hashed personal tokens; active middleware rejected blocked users, logout revoked the current token and web-session fallback was disabled. Public user fields were explicit. Normal web CSRF was retained; the SPA used Authorization and `credentials: omit` without a CSRF-cookie bootstrap.

sessionStorage remained JavaScript-readable. This was an accepted XSS impact boundary, not a discovered XSS exploit. The reviewed React paths used escaped JSX, with no confirmed untrusted HTML/eval path. Search used bound SQL and escaped LIKE metacharacters. A limited then-current pattern scan found no confirmed real secrets; it was not a complete history/image/artifact secret audit.

Production source used non-root execution and production PHP settings, excluded local environment files from the Docker build, and kept migrations/key generation out of startup. Actual cloud secrets, TLS/proxy settings and infrastructure were outside that original audit. Factory/demo seed values were synthetic, not discovered production credentials. DatabaseSeeder depended on development Faker and was not a production bootstrap.

At the historical baseline no implemented orders/key delivery existed to assess for order IDOR or payment authenticity. Current ownership/payment tests must be used instead of interpreting that absence as a current vulnerability or current absence of functionality.

## Historical verification

| Check | Original recorded result |
| --- | --- |
| Main isolated SQLite PHPUnit | 99 tests, 1053 assertions, passed |
| Disposable PostgreSQL search | 8 tests, 153 assertions, passed |
| Disposable PostgreSQL offer/key suite | 26 tests, 83 assertions, passed |
| Native frontend and TypeScript/build/lint | Passed at that baseline; not a current test count |
| Browser fixtures | Reproduced the stock/storage/auth findings described above |
| Dependency registry audit | No reported advisory/abandoned-package finding at the time; not a current vulnerability guarantee |

The original audit did not perform destructive security tests, flood/load tests, real payment capture, production login, Neon changes, OS-image scanning, full history secret scanning, image OCR or a complete accessibility audit. Small-fixture plans/timings did not prove production capacity. Its queries to the working local DB were read-only; test records were confined to disposable databases.

## Recommendations in context

The historical near-term priorities were stock-contract agreement, F02/F03/F04 regression/fixes, range validation and retained browser coverage. Checkout's required server prices, original-key reservation, owner authorization, idempotency and provider verification were subsequently implemented and tested; see the current service/payment documents. Do not preserve old “future Checkout” wording as a statement about today's application.

Growth work remains conditional: bounded offers/page buttons, measured search optimization/throttling, multi-instance limiter storage/proxy checks, token pruning, explicit compiler policy and isolated scaffold/format cleanup. There is no need to create microservices, a universal payment framework or unnecessary module layers for this MVP.

The current system remains a demo, with synthetic Fake Card inventory and Sandbox-only PayPal. Protected uncertain PayPal reservations, missing order history and current deployment/test boundaries are described in the [README limitations](../README.md#known-limitations). No implementation changes, production operations or new dependency audits are implied by this documentation update.
