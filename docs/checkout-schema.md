# Checkout schema and digital key protection

## Relationships and constraints

Users own orders; orders own order items and payment attempts. Offers own keys and are referenced by order items. `digital_keys(order_item_id, offer_id)` references `order_items(id, offer_id)` through a composite FK, preventing assignment from a different offer. History-bearing relationships restrict deletion rather than cascading away purchases.

- Orders: pending_payment / paid / expired / cancelled.
- Payments: pending / succeeded / failed / cancelled.
- Key assignments: available has no order/deadline; reserved has both; sold has an order but no deadline.
- Amounts are `numeric(12,2)` / Eloquent decimal strings; quantities must be positive and counters nonnegative. Reservation/payment dates use timezone-aware timestamps.
- Order idempotency: unique `(user_id, idempotency_key)` and a normalized request fingerprint.
- Payment idempotency: unique `(order_id, idempotency_key)`, partial unique `(provider, external_id)`, one pending payment per order and one succeeded payment per order.
- Confirmation metadata: nullable `confirmation_started_at` and `capture_id` (255 characters); partial unique `(provider, capture_id)` when capture is nonnull.

Indexes support owner/date lookup, pending-order deadlines, order/offer item access, reserved-key deadlines and available-key selection. The composite reference requires unique `order_items(id, offer_id)`. These constraints supplement service-level transitions; they do not independently prove external payment or valid timing.

## Key encryption and fingerprints

`DigitalKey` encrypts `key_value` through Laravel and hides both it and `key_fingerprint` from ordinary serialization. Eloquent writes compute global HMAC-SHA256 fingerprints through `DigitalKey::fingerprint()`. Unique fingerprints prevent duplicate plaintext codes despite randomized encryption, regardless of offer/status. The required-fingerprint migration rejects NULL before enforcing PostgreSQL NOT NULL; uniqueness and format checks remain.

`DIGITAL_KEY_FINGERPRINT_SECRET` must be a separate stable random secret of at least 32 bytes. Reusing `APP_KEY` or its decoded value is rejected. Keep both secrets consistent for every process accessing the same inventory; changing one requires a planned maintenance procedure. Do not rotate encryption/fingerprint secrets merely to repair a local configuration issue.

`DigitalKey::save()` uses a savepoint so a rejected duplicate does not poison an outer PostgreSQL transaction. Duplicate errors omit SQL/bindings and do not chain the database exception. Direct SQL/bulk writes bypass Eloquent events: the DB can reject missing/duplicate fingerprints but cannot compute/verify the application's HMAC from ciphertext.

## Authorized backfill

The existing maintenance command decrypts keys in memory, checks duplicates/mismatches and fills only missing fingerprints in one transaction. It locks against concurrent writes and does not alter ciphertext, timestamps, key states or offer quantities. It reports counts, not key contents.

```sh
docker compose exec -T backend php artisan digital-keys:backfill-fingerprints --allow-decryption --dry-run
docker compose exec -T backend php artisan digital-keys:backfill-fingerprints --allow-decryption
```

Both commands explicitly authorize reading encrypted values; even dry-run requires approval for the target dataset. They are not routine setup/test steps. A conflict aborts without automatic deletion or repair. Production changes require the separate [authorized database workflow](production-database.md); do not run this against Neon as an ordinary test.

## Service guarantees and rollback

`offers.quantity` is a materialized available-stock counter. `InventoryService` changes key state and counter transactionally: reservation decrements, release increments, sale leaves the available counter unchanged. Server price validation, exact key counts, 15-minute deadlines, protected PayPal confirmation and idempotent completion are implemented by [Checkout services](checkout-services.md) and [payments](payments.md), not by schema constraints alone. There are no inventory triggers or background jobs.

Historical checkout/fingerprint backfill verification inspected 493 local synthetic records without duplicate plaintexts. That is a past fixture observation, not a current stock count. Rollback methods refuse to discard populated assignments, fingerprints, confirmation metadata or history. Do not delete working data to make rollback pass.

## Testing

The normal SQLite suite cannot prove PostgreSQL CHECK/partial-index behavior. Disposable `checkout.xml` and `fingerprints.xml` cover schema constraints, Capture ID uniqueness, compatibility, migration rollback safeguards and fingerprint maintenance; `checkout-services.xml`/`payments.xml` cover transitions. See [testing](testing.md).
