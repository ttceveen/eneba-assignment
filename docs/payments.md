# Payments

## Scope and components

Implemented payment methods are `fake_card` and PayPal Sandbox. There are no Live payments, refunds, webhooks or background reconciliation jobs.

`PaymentController` validates requests and returns `PaymentResource`; `PaymentService` owns local state changes. `PaymentGatewayRegistry` resolves configured adapters through Laravel's container. `PaymentGatewayInterface` defines `create(Payment)`, `confirm(Payment, context)` and `supportsSimulation()`.

`PaymentResult` carries a validated status, `externalId`, optional redirect/error fields and optional `captureId`. For PayPal, `externalId` remains the PayPal Order ID; `captureId` is separate. Gateways do not update local orders, reserve keys or create additional attempts.

## API

All routes below require Sanctum Bearer authentication and `EnsureUserIsActive`. Foreign and unknown orders/payments return 404.

| Method | Route | Input / result |
| --- | --- | --- |
| POST | `/api/orders/{order}/payments` | Body contains only `provider`; required `Idempotency-Key`; 201 payment resource |
| POST | `/api/payments/{payment}/simulate` | Body contains only `result`: success or failure; Fake Card only |
| POST | `/api/payments/paypal/confirm` | Body contains only `paypal_order_id`; payment resource |
| GET | `/api/orders/{order}/keys` | Owner-only delivery for a paid order; no-cache response |

The frontend cannot submit a trusted amount, currency, payment status or Capture ID. `PaymentResource` includes payment/order IDs, provider, amount/currency strings, status, idempotency key, external Order ID, redirect URL and safe error fields. It does not expose Capture ID, credentials or digital keys. Keys are returned only by the dedicated paid-order endpoint.

## Local states and persistence

- Orders: `pending_payment`, `paid`, `expired`, `cancelled`.
- Payments: `pending`, `succeeded`, `failed`, `cancelled`.
- Digital keys: `available`, `reserved`, `sold`.

Order creation snapshots prices in `order_items`. A payment copies the server order amount and EUR currency. Payment attempt metadata includes `idempotency_key`, `request_fingerprint`, `external_id`, `redirect_url`, safe error fields, `confirmation_started_at` and `capture_id`.

PostgreSQL enforces unique `(order_id, idempotency_key)`, nonnull `(provider, external_id)` and nonnull `(provider, capture_id)`. Partial unique indexes allow at most one pending and one succeeded payment per order. Service logic prevents new attempts after payment.

Pending results without a new redirect URL preserve the existing Approval URL. A supplied validated URL replaces it. A terminal result clears an absent redirect URL; later results do not revive a terminal payment.

## Fake Card

`FakeCardGateway` is a deterministic simulator: no card fields, real card verification or money movement. It requires both:

```php
config('payment.demo_enabled') === true
&& app()->environment(['local', 'testing', 'demo', 'production'])
```

`payment.demo_enabled` comes from `PAYMENT_DEMO_ENABLED`, default false. Production is permitted only with explicit opt-in. The check runs in the constructor and again in `create()`/`confirm()`. Existing comments in the environment example may predate production opt-in; the gateway condition is authoritative.

Creation returns pending with `fake_card_{payment_id}`. Simulation returns succeeded or failed according to the requested demo result. A verified success follows the same atomic inventory/order completion as other providers. A decline preserves the cart and original reservation deadline. This provider must not be enabled for a real marketplace with valuable inventory.

## PayPal Sandbox configuration

Backend configuration is in `config/payment.php`:

| Variable | Purpose |
| --- | --- |
| `PAYPAL_ENABLED` | Explicitly enable the provider; disabled by default |
| `PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET` | Sandbox REST OAuth credentials, kept on the backend |
| `PAYPAL_MERCHANT_ID` | Stored configuration field; not currently sent or checked as a separate payee constraint |
| `PAYPAL_RETURN_URL` | Frontend `/paypal/return` URL |
| `PAYPAL_CANCEL_URL` | The same frontend route with `cancelled=1` in the query |

For local development the return route is `http://localhost:5173/paypal/return` and the cancellation route is `http://localhost:5173/paypal/return?cancelled=1`. Use the deployed frontend's HTTPS origin when configuring the deployed demo. PayPal supplies its Order ID in the `token` query parameter. URLs come from trusted server configuration, not request input.

Only `api-m.sandbox.paypal.com` is used for REST requests; approval links must use HTTPS and `www.sandbox.paypal.com`. `PayPalClient` disables redirects, applies connection/request timeouts and replaces provider failures with credential-safe exceptions. OAuth tokens use Laravel Cache with expiration and an early-refresh margin. Cache identity changes when credentials change.

## Creation and approval

1. `CheckoutService` validates the cart and reserves the actual keys.
2. `PaymentService::create()` commits a pending attempt before provider HTTP.
3. `PayPalGateway::create()` sends its stored decimal amount to Orders v2 with `intent=CAPTURE`, EUR, callback URLs and `NO_SHIPPING`.
4. The create request uses stable `PayPal-Request-Id: paypal-create-{payment_id}`.
5. The backend saves the PayPal Order ID and validated payer-action/approve URL.
6. The browser opens that existing Sandbox URL for approval.

A lost creation response may already correspond to an external order. Retry the same local attempt/idempotency key; do not create a replacement attempt merely because the request timed out.

## Return, capture and confirmation

`PayPalReturn.tsx` validates the token and uses the existing confirmation endpoint. A normal valid return automatically confirms; this is the existing flow and can perform capture. A `cancelled=1` return does not automatically confirm or cancel the local order. Its explicit confirmation button can still complete a payment approved in PayPal.

`PaymentService::confirm()` finds the PayPal payment through both provider/Order ID and the current user's order. A short transaction locks order then payment, validates amounts/state/original reserved keys, and saves `confirmation_started_at` before HTTP. An existing marker is never reset or backdated.

Outside the transaction, `PayPalGateway::confirm()` reads the same PayPal Order. It checks identity, CAPTURE intent, amount and EUR currency. Only an approved order without existing captures can trigger capture, using `paypal-capture-{payment_id}`. An existing capture is checked instead of captured again.

Success requires matching order identity, a valid Capture ID, matching amount/currency, capture `COMPLETED` and order `COMPLETED`. HTTP success, `APPROVED`, `PAYER_ACTION_REQUIRED` and a pending capture do not prove payment. Valid noncompleted capture information remains pending.

A verified Capture ID is persisted in a separate short transaction before final completion. It cannot be replaced or reused by another payment. Its presence alone does not mark the order paid. If local completion fails after capture, another confirmation can reuse the provider's completed capture and finish the same purchase.

## Atomic completion and key delivery

`PaymentService::applyResult()` holds the order/payment locks. Success uses the original reserved keys, updates them to sold, increments purchase statistics, and marks order/payment paid/succeeded atomically. Savepoints protect against partial inventory/statistics updates. Repeated completion does not sell keys or increment statistics again.

The inventory lock order is order, offers in ascending ID order, then keys in deterministic order. Provider calls never hold a DB transaction open. Checkout/payment transactions retry recognized concurrency errors up to three times.

`keys()` checks ownership and `paid`, then returns only sold keys assigned to the order. Responses use `no-store`/private cache headers. General order/payment responses never contain key material. See [schema and fingerprints](checkout-schema.md).

## Errors, retries and reservation recovery

- Invalid input: 422; unauthenticated: 401; blocked/disabled provider: 403; foreign/unknown order: 404; state/inventory conflict: 409.
- Provider creation/confirmation failures can return safe 502 responses. Raw provider bodies, credentials and transport exception chains are not exposed.
- A timeout is an uncertain result, not proof that payment failed. Pending attempts block new independent attempts. Creation retries retain the same local/provider request IDs.
- Ordinary reservations expire lazily on order access or related Checkout creation, not through a scheduler. Original deadlines are not extended by a failed payment.
- A PayPal confirmation started before the deadline protects its original keys after that deadline and prevents cancellation. This also applies when a lookup still reports payer action required.

Use **Continue in PayPal** to reopen the saved Approval URL and **Confirm PayPal payment** to confirm the same external order. After a lost response, reload the existing order and retry its confirmation. If capture is complete, the verified existing result can finish local delivery. If provider status remains uncertain, retain the keys and investigate manually; do not clear the marker, release stock or assert failure based only on elapsed time. There is no administrative recovery endpoint, refund workflow or background reconciler in this MVP.

## Testing

[Testing instructions](testing.md) cover the normal SQLite suite and disposable PostgreSQL `payments.xml`. Tests include demo production opt-in/opt-out, repeat/concurrent operations, ownership, wrong amount/currency/Capture ID, deadline crossing, pending/timeout recovery, local rollback and exact key delivery. PayPal requests are mocked; no real capture is required. The opt-in real browser E2E uses Fake Card and synthetic inventory only.
