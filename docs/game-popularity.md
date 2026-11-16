# Game activity and popularity

`games.is_active` defaults true. Public catalog/search exclude inactive games, and details for an inactive game return 404. The popularity table contains `game_id` as PK/FK with delete cascade and a nonnegative `purchase_count` defaulting to zero; it has no timestamps or generated ID. Ranking uses `(purchase_count DESC, game_id ASC)`.

## Creation and updates

The migration backfills zero records for existing games without overwriting counters. `Game::created` creates a zero popularity record, and creation through `Game::save()` wraps game/event persistence in a transaction. Existing-game updates do not recreate statistics or modify them.

Raw SQL, bulk inserts and operations with model events disabled do not run this hook. Any privileged importer using those paths must create both records consistently. Popular ordering uses an inner join, so a missing popularity row can exclude an otherwise active game.

`PaymentService::applyResult()` now increments purchased units on successful atomic completion, once per accepted payment. Failed, pending, cancelled and repeated accepted results do not add sales. Updating popularity does not update game descriptions or their timestamps. Purchase counts and activity flags are not public resource fields; game resources also carry offer summaries.

## Demo ranking

```sh
docker compose exec -T backend php artisan db:seed --class=GameSeeder
docker compose exec -T backend php artisan db:seed --class=GamePopularitySeeder
```

These are local/fresh-demo operations only. The optional popularity seeder writes fixed synthetic values only to zero counters for five named games: Cyberpunk 2077 (150), The Witcher 3 (320), Elden Ring (75), Baldur's Gate 3 (200), and Grand Theft Auto V (110). They are not real purchases. Nonzero counters are preserved; deliberately resetting one to zero makes it eligible again. The seeder is not automatic on startup/deploy.

## Historical verification

The original milestone ran 24 tests/398 assertions on PostgreSQL and SQLite and verified default creation, FK/CHECK constraints, rollback, repeat seeding and active-game ranking. A 40-row fixture produced 12/12/12/4 catalog pages without duplicates. A single local EXPLAIN measurement used sequential scans, hash join and sort with two buffer hits, about 0.072 ms execution/0.237 ms planning. This small-fixture measurement is not a production capacity estimate or a reason to force a particular index plan.

Current coverage includes API ranking tests and PostgreSQL payment tests for exactly-once statistics. Use [testing](testing.md), and the authorized [production DB workflow](production-database.md) for any Neon changes.
