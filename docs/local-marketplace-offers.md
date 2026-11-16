# Demo sellers, offers and keys

## Fresh local inventory

Use the [root setup instructions](../README.md) against the local Compose PostgreSQL only. On a fresh database, seed in order: GameSeeder, optional GamePopularitySeeder, SellerSeeder, OfferSeeder, DigitalKeySeeder. Configure independent stable `APP_KEY` and `DIGITAL_KEY_FINGERPRINT_SECRET` before key writes.

The fixture contains 40 games, five sellers, 120 offers and 493 synthetic non-redeemable keys. Sellers are GameVault, DigitalWorld, KeyMarket, PixelStore and GameHub. Offers have two to four sellers per game, varied demo EUR prices and positive initial quantities. Game cover URLs start null; the frontend displays a fallback. Region labels are sample availability data, not an implemented geographic sales restriction.

`firstOrCreate` preserves existing games/sellers/offers. `GamePopularitySeeder` only updates eligible zero counters. `DigitalKeySeeder` computes fingerprints in memory and adds only missing synthetic slots. It refuses reserved/sold stock or key counts above an offer's quantity and rolls back rather than deleting keys or overwriting stock. It is not a replenishment tool after purchases or a production key importer. The default DatabaseSeeder is not this fixture sequence.

## Public API and stock

Catalog/search resources add `min_price` as a two-decimal string or null and `offers_count`. `Game::availableOffers` requires an active offer/seller and positive materialized quantity; inactive games are excluded by the enclosing public query. Summary aggregates avoid loading all keys/offers into catalog responses.

Game details return offers sorted by price/ID with seller ID/name, decimal price and quantity. The batch cart endpoint additionally bounds quantity by its available-key count and checks game/seller/offer activity. The two read paths can differ if privileged imports/manual edits leave the counter inconsistent with keys. Checkout independently locks and validates both the counter and actual available keys; displayed availability is not a reservation guarantee.

Digital keys are not returned by public catalog/offer endpoints. Purchases reserve and sell keys through [Checkout](checkout-services.md) and [payments](payments.md).

## Frontend

Cards show “From” prices and English offer counts or “No offers available.” Game Details provides existing Add to cart controls with stock/limit feedback. Cart reloads bounded IDs, preserves removable unavailable items and reports actual automatic quantity reductions. It does not persist a trusted price or grant ownership of a key.

## Historical fixture verification

The initial local inventory preparation applied the seller/offer/key migrations and seeded only those tables for the existing 40 games. It observed five sellers, 120 offers and 493 available keys, with total quantity matching key count. Repeat seeding preserved records; game/popularity contents and APP_KEY were retained. Those counts are historical initial-stock observations, not current post-purchase assertions.

That milestone recorded 84 SQLite tests/903 assertions, 26 PostgreSQL offer tests/83 assertions, eight search tests/153 assertions and 107 native frontend tests. Query checks measured two catalog queries and three detail queries without per-offer query growth or public key reads. Browser checks included unavailable/zero-price cases and mobile widths. Later Checkout/PayPal/UI work extends those results; [testing](testing.md) documents current commands.
