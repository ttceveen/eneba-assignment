# Checkout and inventory services

`CheckoutService` and `InventoryService` implement server-priced reservation. Payment completion is implemented in [payments](payments.md); the SPA flow is described in [frontend Checkout](frontend-checkout.md).

## API contract

All routes require Sanctum and an active user; foreign/unknown orders return 404.

| Method | Route | Result |
| --- | --- | --- |
| POST | `/api/checkout` | 201 order, including replay of the same request |
| GET | `/api/orders/{order}` | Order with lazy expiration |
| POST | `/api/orders/{order}/cancel` | Safe repeated cancellation; paid/protected orders conflict |

Creation requires `Idempotency-Key`: 1–128 ASCII letters, digits, dots, hyphens, underscores or colons. Body:

```json
{"items":[{"offer_id":12,"quantity":1},{"offer_id":18,"quantity":2}]}
```

Accept 1–20 unique positive offer IDs and positive integer quantities within PostgreSQL integer range. Duplicate IDs, extra item fields such as price, or malformed/missing request keys are rejected. Prices come from locked active offers with active games/sellers; currency is EUR. Totals use integer cents with the `numeric(12,2)` limit checked before multiplication/accumulation.

The order resource includes status, item/offer IDs, quantities, unit-price snapshots, current game/seller descriptions, total/currency/deadline and loaded payment resources. It does not contain keys, ciphertext, fingerprints or the internal order request fingerprint. Payment resources contain their client-known idempotency keys for attempt recovery.

Errors: 401 unauthenticated, 403 blocked account, 404 unknown/foreign order, 422 invalid input/missing offers and 409 stock/activity/state/idempotency conflict.

## Transactions and idempotency

Creation normalizes/sorts the cart, hashes its ID/quantity representation and takes a transaction-scoped PostgreSQL advisory lock for user/request key. An existing key with another cart returns 409; the same cart reuses the same order without extending its deadline. Database uniqueness is an additional safeguard.

The lock order is order, offers in ascending ID order, then keys by offer/ID. Reservation selects only the necessary available key IDs using `FOR UPDATE SKIP LOCKED`. A conditional counter decrement and exact key count must both succeed. Order, item prices, deadline and key assignments commit together; nested inventory operations use savepoints. Recognized Laravel deadlock/concurrency failures are retried up to three times.

No external provider HTTP occurs in these DB transactions. `PaymentService` uses the same ordering for completion.

## Expiration and cancellation

New reservations last 15 minutes. `get()` and `cancel()` lazily expire an ordinary pending order; new Checkout also processes expired orders for affected offers, in bounded batches. Release increments available stock only for that order's still-reserved keys and clears their assignment/deadline. Sold keys are not returned to stock.

A PayPal payment with `confirmation_started_at` strictly before its order deadline protects the original reserved keys while the order remains pending_payment. `hasProtectedConfirmation()` is checked under the order lock before release/expiry/cancellation. A late marker provides no protection. Protected cancellation returns 409.

`assertReserved()`/`confirmSale()` check that the original exact key counts remain assigned and reserved. Protected sale may complete after the original deadline; it neither creates new reservations nor substitutes other keys. Provider result uncertainty therefore cannot cause the original stock to be reused elsewhere.

There is no scheduled expiration/reconciliation job. A protected confirmation can remain pending indefinitely if the user does not complete recovery. See [payment recovery limitations](payments.md).

## Verification

[Disposable PostgreSQL tests](testing.md) cover exact cents, immutable snapshots, 15-minute deadlines, normal/protected/late expiry, cancellation, original-key ownership/counts, insufficient stock, idempotency and real lock contention. The suite has been extended with protected-reservation cases; use its current output for test counts. API tests verify ownership/validation and safe error responses.
