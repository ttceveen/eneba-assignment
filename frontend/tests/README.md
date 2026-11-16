# Frontend tests

The complete setup and safe execution recipes are in [the testing guide](../../docs/testing.md). General project setup is in the [root README](../../README.md).

## Test groups

- `*.test.mjs`: native Node tests for API transport/validation, authentication, cart arithmetic and checkout journals. Run with Node.js 24 through the existing frontend container.
- `auth-provider.browser.mjs`: real AuthProvider/Router lifecycle with mocked API, including logout failures and stale `/me` responses.
- `cart-sync.browser.mjs`: latest-snapshot mutations, legacy limits, cross-tab storage and disabled storage.
- `cart-isolation.browser.mjs`: successful Logout, stable user ID boundaries, restoration/loading/errors, PayPal preservation and stale results.
- `navigation-feedback.browser.mjs`: catalog/search return context, local Retry, stock-change notices and locked payment methods.
- `mobile-purchase.browser.mjs`: mobile dock, 44px targets, expandable order details and loading/error states across five widths.
- `checkout-auth.browser.mjs`, `purchase-flow.browser.mjs`, `paypal-flow.browser.mjs`: protected Checkout, payment retries, provider return and paid-only key delivery.
- `purchase-real.browser.mjs`: opt-in real Laravel/disposable PostgreSQL E2E. The provided runner creates synthetic inventory and performs only Fake Card payments.

```sh
docker compose exec -T frontend node --test tests/*.test.mjs
```

```bash
PLAYWRIGHT_MODULE_PATH=/absolute/path/to/playwright/index.mjs \
FRONTEND_BASE_URL=http://localhost:5173 \
node --test --test-concurrency=1 frontend/tests/*.browser.mjs
```

Browser suites require an existing Playwright/Chromium installation and any required system-library environment. Controlled suites mock API traffic. The real E2E is skipped without its explicit local sandbox variable; run it through `scripts/tests/frontend-checkout-e2e.sh` as described in the testing guide. Do not point fixtures at a working or production database, and do not replace mocked provider calls with real capture.
