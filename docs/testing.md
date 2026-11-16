# Testing

Run ordinary commands from the repository root. Use the existing dependency installations; no additional application dependencies are needed. Test artifacts can be written to temporary directories. These instructions do not authorize changing a working or production database.

## Main backend suite

```sh
docker compose exec -T backend php vendor/bin/phpunit --filter TestDatabaseIsolationTest --do-not-cache-result
docker compose exec -T backend php vendor/bin/phpunit --do-not-cache-result
docker compose exec -T backend php vendor/bin/pint --test
```

`backend/phpunit.xml` forces `APP_ENV=testing`, SQLite `:memory:`, empty `DATABASE_URL`/`DB_URL`, array cache/session and a synthetic fingerprint secret. `Tests\TestCase` validates both resolved configuration and actual PDO before `RefreshDatabase`. Cached/unsafe configuration is rejected; do not bypass this guard. This suite does not prove PostgreSQL partial-index, CHECK, advisory-lock or concurrency behavior.

## Disposable PostgreSQL suites

`compose.search-test.yaml` is a separate Compose project, not an override for `compose.yaml`. The DB is tmpfs-backed, has no host-published port, and uses alias `search-postgres`, database/role `eneba_search_test`. The runner mounts backend source read-only and works in a temporary copy.

Every suite checks `SEARCH_TEST_SANDBOX=1`, `APP_ENV=testing`, `DB_CONNECTION=pgsql`, the exact host/database/role, empty connection URLs, the actual DB identity and an empty public schema before migrations. Give each suite a fresh database; do not run all configurations successively against the same populated instance.

| Configuration under `backend/tests/Postgres/` | Coverage |
| --- | --- |
| `phpunit.xml` | PostgreSQL search/ranking |
| `offers.xml` | Offer/key models and constraints |
| `fingerprints.xml` | Encrypted-key fingerprint uniqueness and backfill |
| `checkout.xml` | Checkout schema, metadata and rollback safeguards |
| `checkout-services.xml` | Reservation, expiry, cancellation and concurrency |
| `payments.xml` | Fake Card, PayPal HTTP fakes, completion, ownership and races |

The following Bash/WSL recipe uses the existing Compose service and overrides only its temporary test command. Set `qa_config` to one filename from the table. Choose a distinct `qa_project` or remove its disposable services after each run. It installs locked dependencies into the runner's temporary filesystem, not the source directory.

```bash
qa_project=eneba-pg-review
qa_config=payments.xml

docker compose -f compose.search-test.yaml -p "$qa_project" run --build --rm \
  -e PAYMENT_DEMO_ENABLED=true \
  -e DIGITAL_KEY_FINGERPRINT_SECRET=checkout-test-only-secret-32-bytes-minimum \
  search-backend "tar -C /source --exclude='./.env*' --exclude='./vendor' --exclude='./storage' --exclude='./bootstrap/cache' -cf - . | tar -C /app -xf -;
mkdir -p /app/storage/framework/cache/data /app/storage/framework/sessions /app/storage/framework/views /app/storage/logs /app/bootstrap/cache;
touch /app/.env;
composer install --no-interaction --prefer-dist;
php vendor/bin/phpunit --configuration tests/Postgres/$qa_config --do-not-cache-result"

docker compose -f compose.search-test.yaml -p "$qa_project" down
```

The fingerprint value above is a public synthetic test fixture, not an application secret. `PAYMENT_DEMO_ENABLED=true` must reach child processes in concurrency tests; changing Laravel config only in the parent test is insufficient. Do not reuse the test identity/secret for a real application. Inspect the PHPUnit exit status before cleanup; `down` is scoped to this separate disposable project. Do not use the development project's data volumes or Neon.

For search alone, the unmodified Compose service already selects `tests/Postgres/phpunit.xml`:

```sh
docker compose -f compose.search-test.yaml -p eneba-search-review up --build --abort-on-container-exit --exit-code-from search-backend
docker compose -f compose.search-test.yaml -p eneba-search-review down
```

The default command excludes `.env*`; some runtime/tool versions may report a missing test env-file warning. The general recipe above explicitly creates an empty file only in the temporary runner.

## Frontend native and build checks

```sh
docker compose exec -T frontend node --test tests/*.test.mjs
docker compose exec -T frontend npx tsc -b --pretty false
docker compose exec -T frontend npm run lint
docker compose exec -T frontend npm run build -- --outDir /tmp/eneba-review-build
```

There is no `npm test` script. Native tests use Node's test runner and TypeScript stripping; use the configured Node.js 24 container. The production build includes `tsc -b`. An output directory outside the source tree avoids replacing application build artifacts; Vite may report that the external output directory is not automatically emptied.

## Controlled browser suites

Start the existing development frontend. Supply an existing Playwright installation with Chromium; it is intentionally not added to `package.json` by these tests.

```bash
PLAYWRIGHT_MODULE_PATH=/absolute/path/to/playwright/index.mjs \
FRONTEND_BASE_URL=http://localhost:5173 \
node --test --test-concurrency=1 frontend/tests/*.browser.mjs
```

If Playwright resolves normally, omit `PLAYWRIGHT_MODULE_PATH`. Linux Chromium may need `LD_LIBRARY_PATH` pointing to already available browser libraries. Use your actual toolchain path, not a path copied from another machine. API mocks keep controlled suites away from working backend data and external PayPal.

Suites cover auth lifecycle/redirects, cart synchronization and ownership, catalog navigation/Retry, stock feedback, mobile disclosure/dock states, Fake Card, PayPal return/retry and paid-only key delivery. Provider behavior is mocked; a passing mock test is not verification of cloud credentials.

## Real Laravel/PostgreSQL browser E2E

This existing Bash/WSL script creates a separate network, tmpfs DB and temporary backend copy using existing Docker images. It does not install project dependencies or use working `.env` files. It publishes only local API port 8011 and cleans up its temporary services on exit.

```bash
PLAYWRIGHT_MODULE_PATH=/absolute/path/to/playwright/index.mjs \
FRONTEND_BASE_URL=http://localhost:5173 \
bash scripts/tests/frontend-checkout-e2e.sh
```

Keep the required Linux browser-library environment if applicable. Docker images used by development must already exist (`--pull=never`), the development frontend must use the expected local port-8000 API URL, and port 8011 must be free. The test redirects those API requests to its isolated server.

It exercises guest cart, registration, Fake Card decline, successful retry, key delivery/copy and reload with synthetic `BROWSER-DEMO-NOT-VALID` inventory. It does not contact PayPal. `purchase-real.browser.mjs` is skipped in the general browser run unless `CHECKOUT_SANDBOX_API_URL` is supplied; the script supplies it. The test accepts only a localhost/127.0.0.1 API on port 8011.

## Production-operation script tests

```powershell
.\scripts\tests\production-db.tests.ps1
```

These are offline Windows PowerShell 5.1+ tests with mocked Docker and confirmation input. They do not read real credentials or connect to Neon. See [production database operations](production-database.md).

Never run production migrations, seeders, destructive database commands or real PayPal capture as part of ordinary automated verification.
