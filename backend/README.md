# Marketplace backend

Laravel 13 / PHP 8.4 REST API backed by PostgreSQL 17. See the [root README](../README.md) for Docker setup, demo seed order and deployment.

## Structure

- `app/Http/Controllers`, `Resources`, `Middleware`: request validation, explicit public responses, Sanctum and active-account checks.
- `app/Services/CheckoutService.php`: server-priced order creation, idempotency, lazy expiry and cancellation.
- `app/Services/InventoryService.php`: transactional reservation/release/sale of exact digital keys.
- `app/Services/PaymentService.php`: payment attempts, provider confirmation, local completion and owner-only delivery.
- `app/Payments`: Fake Card and PayPal Sandbox adapters, client and registry.
- `app/Models`, `database/migrations`, `database/seeders`: persistence and explicit synthetic fixtures.

This is a layered monolith, not a set of microservices. Provider HTTP happens outside DB transactions. Digital keys are encrypted and globally deduplicated through a separate HMAC fingerprint secret.

## Useful commands

Run from the repository root:

```sh
docker compose exec -T backend php artisan migrate:status
docker compose exec -T backend php vendor/bin/phpunit --filter TestDatabaseIsolationTest --do-not-cache-result
docker compose exec -T backend php vendor/bin/phpunit --do-not-cache-result
docker compose exec -T backend php vendor/bin/pint --test
```

The normal PHPUnit suite requires SQLite in-memory. PostgreSQL suites require a separate disposable database; see [testing](../docs/testing.md). Do not weaken test guards to use the working DB. `composer test` also clears Laravel's config cache; direct PHPUnit commands above avoid that extra action.

## Configuration and boundaries

`APP_KEY` decrypts key material. `DIGITAL_KEY_FINGERPRINT_SECRET` must be independent, at least 32 bytes and stable. Store both privately and preserve them for existing inventory. [Schema/backfill documentation](../docs/checkout-schema.md) explains maintenance requirements.

Fake Card requires explicit `PAYMENT_DEMO_ENABLED=true`, including when `APP_ENV=production`; it is only appropriate for synthetic demo inventory. PayPal is Sandbox-only. See [payments](../docs/payments.md) for exact flags, callbacks, idempotency and uncertain-capture recovery.

The production image does not run migrations or generate keys on startup. Authorized Neon changes use the existing manual [production database workflow](../docs/production-database.md). No refunds, webhooks, scheduler or background reconciliation are implemented.
