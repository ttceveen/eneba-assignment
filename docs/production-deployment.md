# Deployment

## Existing architecture

Vercel serves the built React SPA, Render runs the Laravel backend, and Neon stores PostgreSQL data. These are separately configured deployments; repository documentation does not imply an automated release pipeline or that every latest local change is already deployed.

- Frontend source/root: `frontend/`; build: `npm run build`; output: `dist`.
- `frontend/vercel.json` rewrites SPA paths to `index.html` so direct game/order/return URLs can load the router.
- Backend Dockerfile: `backend/Dockerfile.prod`; build context: `backend/`.
- With repository-root paths in Render, leave its Root Directory unset and use those Dockerfile/context paths. Check the service's actual configuration rather than combining conflicting root-directory conventions.

## Runtime configuration

Store values privately in the appropriate platform. The following table lists names only; it is not an export of any running service.

| Target | Names / purpose |
| --- | --- |
| Vercel | `VITE_API_BASE_URL`: HTTPS backend API base including `/api`; embedded during build |
| Render application | `APP_ENV`, `APP_DEBUG`, `APP_KEY`, `APP_URL`, `FRONTEND_URL` |
| Render database | `DB_CONNECTION`, `DATABASE_URL` |
| Render inventory | `DIGITAL_KEY_FINGERPRINT_SECRET` |
| Render logging/runtime | `LOG_CHANNEL`, `LOG_LEVEL`, `PORT` |
| Render demo payments | `PAYMENT_DEMO_ENABLED`, `PAYPAL_ENABLED`, `PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET`, `PAYPAL_MERCHANT_ID`, `PAYPAL_RETURN_URL`, `PAYPAL_CANCEL_URL` |

Keep the deployed application in production mode with debug output disabled. Preserve the existing APP_KEY and separate fingerprint secret for its inventory. Configure the exact HTTPS frontend origin for CORS; the backend also retains the explicit local development origin. Protected requests use Bearer headers, not cross-site session cookies.

PayPal callbacks must target the frontend return route and cancellation query described in [payments](payments.md). PayPal credentials remain on Render, never in `VITE_*`. Fake Card can be enabled explicitly in production with its demo flag; this is only suitable for nonvaluable demonstration inventory. The source default remains disabled. `PAYPAL_MERCHANT_ID` is present in config but is not a separate verified payee check.

Do not mix a production `DATABASE_URL` with conflicting `DB_URL` or separate `DB_*` connection settings. In the pgsql config, DATABASE_URL has priority over DB_URL, and URL components override separate fields. Retain TLS settings required by Neon. The production entrypoint exports libpq `PGCHANNELBINDING` from the connection URL's `channel_binding` query parameter, validates its value, and otherwise preserves the process setting/default. This bridge applies when using the normal entrypoint.

## Backend image

```sh
docker build -f backend/Dockerfile.prod -t eneba-backend-prod ./backend
```

The image uses PHP 8.4/FrankenPHP without Octane, installs locked production dependencies with `--no-dev --no-scripts`, then performs package discovery. It uses production PHP settings, a non-root UID 1000 and writable storage/cache directories. Source files are not writable by the runtime user. Tests, local environment files, logs and generated local cache files are excluded from the build context. No credential-bearing config cache is built into the image.

FrankenPHP listens on `PORT` (image default 10000); Render terminates public TLS. The `cap_net_bind_service` file capability is removed because high ports do not need it and restricted runtimes can reject the executable otherwise. The entrypoint passes signals through with `exec`. It does not run migrations, seeders or key generation.

For a genuinely new application, a key can be generated for private storage:

```sh
docker run --rm eneba-backend-prod php artisan key:generate --show
```

Do not use this to replace the key of an existing database. Keep generated output out of documentation/log exports. For private Docker env files, follow the unquoted uppercase `KEY=value` format required by the existing production DB script; do not publish their contents.

## Database release operations

Apply/review migrations locally and run isolated tests first. Neon migration and each selected seeder need separate authorization and manual confirmation. Use [production database operations](production-database.md); do not add migrations/seeding to build or startup. Review recoverability/backups and code in `up()`/seeders before applying anything.

The generic DatabaseSeeder uses a development factory and is not a no-dev production bootstrap. Select the intended game/seller/offer/key seeder explicitly, only after checking its preconditions and whether synthetic data is appropriate.

## Verification after an authorized deployment

Read-only checks can verify frontend/SPA-route availability, backend `/up`, `/api/health`, catalog/search and CORS GET/preflight responses. A request without a token to the keys endpoint must be unauthorized; a missing API route should return a safe error without a debug trace. Verify that the built frontend targets the deployed HTTPS API rather than localhost.

Changing Vercel's API variable requires a new frontend build. Changing Render runtime variables requires the platform's corresponding service update/restart. Documentation changes alone do not apply either operation. Payment enablement and cloud credentials cannot be proven merely by inspecting the public frontend bundle; use a separately authorized Sandbox demonstration, never a Live payment.

## Historical deployment troubleshooting

Earlier local image validation covered custom PORT, static/API routes, separate PostgreSQL TLS/SCRAM, non-root permissions and SIGTERM. One TLS check confirmed channel binding was required; disabling SSL with mandatory binding failed as expected. This verified the image/libpq path, not every possible Neon endpoint/pooler setting.

A previous restricted-runtime failure returned `exec: frankenphp: Operation not permitted` with code 126. The executable had a file bind capability; removing it fixed the reproduced failure. Follow-up local tests used dropped capabilities and `no-new-privileges`, verified UID 1000, empty effective capabilities, successful requests and clean SIGTERM. This does not claim that Render's private runtime configuration was inspected.

An earlier published CORS diagnosis found two independent configuration errors: the frontend bundle targeted localhost, and backend allow-origin did not match the deployed frontend. The code now uses `VITE_API_BASE_URL` and Laravel's explicit origin/header configuration, including Authorization and Idempotency-Key. Existing CORS tests cover allowed/forbidden origins, preflights and error responses. That historical fix is not an instruction to copy old compiled asset names or assume that a newer local build is already published.

## Limits

There is no automated cloud configuration, production migration pipeline, shared multi-replica cache/session store, webhook worker or payment reconciliation scheduler. File cache/session state is local to the backend instance. Plan shared rate-limiter state and actual proxy/IP verification before scale-out. Account / Order History is a placeholder; all payments remain demonstrations. See [known limitations](../README.md#known-limitations).
