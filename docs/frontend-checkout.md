# Frontend purchase flow

## Pages and API

Existing React Router/AuthProvider/Sanctum Bearer and CartProvider are retained.

- /cart: existing remove/quantity/empty/loading/error UI. Quantity buttons now apply deltas to the latest provider snapshot, avoiding stale-render values during rapid clicks. Totals and Header count update through the same provider, with the existing eneba:cart array of offerId/quantity. Limit 20 and stock clamping remain.
- /checkout: protected entry point. Reads a fresh cart snapshot, stores an immutable request body and UUID idempotency key per user, records the cart allocation, then POSTs checkout. Network failures/reload reuse that request/key. A definitive 409/422 lets the user return to cart and begin again after refreshing availability. Empty carts never POST.
- /orders/:id: protected payment page. Loads the existing order, product/seller names, fixed server prices, status and payments. Displays the original reserved_until countdown. Client time never renews a reservation; deadline triggers a server refresh once. Pending orders may be cancelled. Expired/cancelled orders offer return to cart.
- /orders/:id/success: verifies paid on the backend, removes only locally tracked purchased units once, then fetches keys. Keys stay solely in component memory, are absent from URLs/storage/logs, and support clipboard copying with success/failure feedback. Cart-storage errors do not prevent key retrieval.

Account checks and login/register redirect allowlists now include the two order routes. Existing 401 invalidation uses the same AuthProvider; an old response cannot clear a newly changed token. Demo-disabled 403 does not erase a valid login.

## Payment recovery

Only the fake-card demo is offered, with Successful payment / Failed payment radio options and no card inputs. Pending attempt information is stored as IDs, idempotency key and chosen demo result; no key material or card data is stored.

Before paying or after an uncertain result, GET order is used to reconcile state. If a pending attempt exists it is resumed; if payment creation needs retry it uses the same stored key. Unknown outcomes require Check payment status, not automatic creation. A confirmed failed attempt allows an explicit new attempt/new UUID, keeping the same order/deadline. Payment and order statuses come from the server; choosing success does not itself make the UI paid.

Buttons have synchronous in-flight guards as well as disabled states. Operations and data loads are aborted on unmount; cancelled responses do not navigate or replace current UI. Backend idempotency remains authoritative.

Server prices are displayed for review before payment, even if they differ from cart estimates. Error messages are mapped by status and never render Laravel exception text. Purchased-key fetch failures have a retry action without repeating payment or cart subtraction.

## Additive backend read changes

CheckoutController eagerly loads items.offer.game, items.offer.seller and descending payments. OrderResource adds game/seller descriptions to items and a payments collection. PaymentResource adds the client-known idempotency_key so an interrupted create attempt can be matched exactly. No new endpoints, migrations or payment business logic were required. Ownership and Sanctum middleware are unchanged; ordinary order/payment responses still contain no digital keys.

## Cart completion journal

The cart storage format is unchanged. Additional eneba:cart-purchases metadata contains only user/order/request IDs and quantities. It tracks original checkout units separately from later additions. Decreases/removal reduce the original allocation first; readding the same offer does not reallocate it to the old order.

On paid status, remove at most the server-purchased quantity, the original units still allocated, and the current quantity. Other offers and later additions survive. An applied receipt prevents subtraction on reload. A small write-ahead before/after snapshot recovers an interrupted two-key storage write; ambiguous later edits are preserved rather than overwritten.

Clearing depends on this browser's journal. If storage was cleared or purchase completed in another browser without a matching record, the current cart is preserved. localStorage still has the existing cross-renderer read-modify-write limitation: no claim of atomic multi-tab transactions is made. Server orders/payments remain protected by PostgreSQL locks and constraints.

Only one current checkout is resumed per user/tab. Back to cart from a closed order clears its entry; paid completion clears it. Session metadata is not a server cart, contains no digital keys, and does not replace authorization.

## Verification commands

No new dependencies were installed. Existing Node tests and Chromium/Playwright toolchain are used.

```sh
docker compose exec -T frontend node --test tests/*.test.mjs
docker compose exec -T frontend npx tsc -b --pretty false
docker compose exec -T frontend npm run lint
docker compose exec -T frontend npx vite build --outDir /tmp/eneba-checkout-build --emptyOutDir

PLAYWRIGHT_MODULE_PATH=/path/to/existing/playwright/index.mjs \
FRONTEND_BASE_URL=http://localhost:5173 node --test frontend/tests/*.browser.mjs
```

For the real-browser integration with an actual Laravel server and disposable PostgreSQL:

```sh
PLAYWRIGHT_MODULE_PATH=/path/to/existing/playwright/index.mjs \
FRONTEND_BASE_URL=http://localhost:5173 bash scripts/tests/frontend-checkout-e2e.sh
```

On Linux, retain LD_LIBRARY_PATH required by the existing Chromium installation. The script uses existing Docker images (--pull=never), a private network, tmpfs PostgreSQL and an isolated backend copy with secrets/config caches excluded. Only local port 8011 is published. Real-E2E test is skipped unless CHECKOUT_SANDBOX_API_URL is explicitly supplied; it refuses non-local/non-8011 URLs. Containers/network are removed on exit.

Chromium forwards API traffic to this sandbox and performs actual registration, checkout, declined payment, successful retry, key delivery/copy and reload. Fixture stock is six synthetic keys; two are sold and four remain. No manual API calls are needed in the user flow. Working 493-key stock and Neon are never used.

Controlled-browser tests additionally cover unknown outcomes, retry keys, expiry, cart removal/readdition, later offers, double clicks, clipboard, reload, API errors and mobile width. Native tests cover journal recovery/allocation and API transport/validation. Existing auth F04 and cart F02/F03 browser suites remain in place.

## Limits

No real providers, frontend order history, email or background polling were added. Payment state refreshes on page load, user action and displayed expiry. Product/seller names come from current related records; immutable unit prices come from order_items. The frontend does not release inventory or decide whether a payment succeeded.

## Files in this frontend milestone

Created:

- frontend/src/api/checkout.ts
- frontend/src/checkout/session.ts, cartPurchases.ts, useConnection.ts
- frontend/src/pages/Checkout/Checkout.tsx, Payment.tsx, Success.tsx, Summary.tsx, Checkout.module.css
- frontend/tests/checkout.test.mjs, purchase-flow.browser.mjs, purchase-real.browser.mjs
- scripts/tests/frontend-checkout-e2e.sh
- docs/frontend-checkout.md

Updated:

- frontend/src/App.tsx
- frontend/src/auth/AuthProvider.tsx
- frontend/src/pages/Auth/Auth.tsx
- frontend/src/cart/CartProvider.tsx, context.ts
- frontend/src/pages/Cart/Cart.tsx
- frontend/tests/checkout-auth.browser.mjs, README.md
- backend/app/Http/Controllers/CheckoutController.php
- backend/app/Http/Resources/OrderResource.php, PaymentResource.php
- backend/tests/Postgres/CheckoutServiceTest.php, PaymentServiceTest.php

Verification result: 140 native frontend tests; 45 controlled Chromium tests plus one separately executed real Laravel/PostgreSQL browser E2E; 99 main backend tests; 50 affected PostgreSQL tests. TypeScript, Oxlint (0 warnings/errors), production build in a temporary directory and diff whitespace checks passed. Tracked dist files were restored after the first verification build; no compiled bundle changes are part of this milestone. No working DB migrations, seeds, real-key reservations, secret changes, deploy or Git commits were performed.
