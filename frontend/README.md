# Marketplace frontend

React/TypeScript SPA using Vite, React Router and CSS Modules. General setup, environment configuration, payments and deployment are documented in the [root README](../README.md).

## Responsibilities

- `src/pages/Home`, `GameDetails`, `Cart` and `Checkout`: catalog-to-key purchase flow.
- `src/api`: existing REST transport and response validation; production API requests require HTTPS.
- `src/auth`: Sanctum Bearer token lifecycle, with tokens in sessionStorage only.
- `src/cart`: browser persistence, stock feedback, cross-tab synchronization and confirmed account boundaries.
- `src/checkout`: stable attempt metadata and purchase-allocation journal.
- `src/utils/catalogHistory.ts` and `src/hooks/useCatalogScroll.ts`: catalog context and position restoration.

The backend is authoritative for prices, reservation validity, payment status and key assignment. The frontend never stores purchased keys persistently.

## Commands

From the repository root with Compose running:

```sh
docker compose exec -T frontend node --test tests/*.test.mjs
docker compose exec -T frontend npx tsc -b --pretty false
docker compose exec -T frontend npm run lint
docker compose exec -T frontend npm run build -- --outDir /tmp/eneba-frontend-build
```

Compose already starts Vite; do not start a second dev server in the running container. For a host-based installation, use Node.js 24, run `npm ci` in this directory, configure `frontend/.env`, then `npm run dev`.

`VITE_API_BASE_URL` is public build-time configuration, not a place for credentials. PayPal Client Secret, OAuth tokens and encryption secrets belong only on the backend.

See [Checkout behavior](../docs/frontend-checkout.md), [cart](../docs/frontend-cart.md), [navigation](../docs/catalog-navigation.md), and [browser/native test guide](tests/README.md). Account / Order History remains a placeholder. Cover fallback is expected for games without an `img` URL.
