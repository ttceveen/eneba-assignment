# Demo payment backend

## Scope and API

PaymentController → PaymentService → PaymentGatewayRegistry → PaymentGatewayInterface → adapter. CheckoutService and InventoryService remain provider-independent and unchanged.

All routes require existing Sanctum Bearer authentication and active-user middleware. Other users' orders/payments return 404. Keys are never included in OrderResource or PaymentResource.

- POST /api/orders/{order}/payments, body {"provider":"fake_card"}, required Idempotency-Key header. Returns 201 with payment id, order_id, provider, status, amount/currency strings, external_id, redirect_url, error_code/error_message.
- POST /api/payments/{payment}/simulate, body {"result":"success"} or {"result":"failure"}. Returns 200 with the immutable accepted result; state conflicts return 409.
- GET /api/orders/{order}/keys. Only the owner of a paid order can read keys. Returns data.order_id and items[{order_item_id,offer_id,keys:[{id,key_value}]}]. Eloquent encrypted cast performs decryption. This is the only response that deliberately contains plaintext keys. Cache-Control: no-store/private, Pragma: no-cache, Expires: 0. Repeated reads return the same assigned sold keys, create no issuance records and do not mutate order state.

Bodies reject extra fields: no card numbers, CVV, expiry, holder or client-provided amount. Invalid input is 422, disabled demo is 403, state conflict is 409, uncertain gateway initiation is a sanitized 502. Do not log keys-endpoint response bodies.

## Demo configuration

config/payment.php maps allowed string codes to container-resolved adapters. User-supplied PHP class names are never instantiated. PAYMENT_DEMO_ENABLED defaults to false in .env.example. It was explicitly enabled only in ignored local backend/.env; no other environment values changed. FakeCard requires both the flag and APP_ENV local/testing/demo, and remains unavailable in production even with the flag.

FakeCard is a deterministic simulator: success/failure is accepted solely for demo execution. It does not validate a real payment and never collects card details. Only configured simulation_gateways with supportsSimulation() may use the simulate endpoint; currently only fake_card. Real adapters must return false and must verify a trusted provider API.

## Gateway contract

PaymentGatewayInterface::create(Payment): PaymentResult starts/resumes a provider operation using the immutable internal payment ID as the external idempotency key. confirm(Payment, context): PaymentResult verifies its result. supportsSimulation(): bool declares demo capability.

PaymentResult is a typed readonly object with validated status (pending/succeeded/failed/cancelled), externalId, redirectUrl, errorCode and errorMessage. HTTPS redirect URLs and pending results support a future asynchronous adapter. It contains no card credentials. Adapters must verify external payment identity, amount and currency before returning succeeded.

To add PayPal later: implement this interface, register its code/class in config/payment.php, configure credentials privately and add necessary trusted return/webhook endpoints. Those endpoints must verify provider authenticity before passing results to PaymentService::applyResult. No CheckoutService/InventoryService changes are needed. PayPal, webhooks and refunds are not implemented here.

## Payment creation and retries

The service first commits any existing lazy expiration through CheckoutService. A short transaction locks the order, checks ownership/state/deadline and the existing attempt key. New attempts copy amount/currency from the order and start pending. UNIQUE(order_id,idempotency_key) protects persistence; the locked parent order serializes concurrent creation. A different provider with the same key is 409. The existing one-pending and one-succeeded partial UNIQUE indexes remain unchanged.

The transaction ends BEFORE create() is called on the adapter. Public create/simulate reject being called from an enclosing DB transaction. Adapters and external HTTP calls must not hold inventory locks.

The same key returns the same attempt. If provider initiation was interrupted before storing external_id/redirect_url, replay resumes that attempt with its immutable payment ID as provider idempotency key. Concurrent resumes require idempotent adapter implementation; FakeCard uses a deterministic external_id.

A declined attempt is failed; order and keys remain reserved with the ORIGINAL deadline. A retry uses a NEW Idempotency-Key and creates a new attempt, only while the reservation is valid. Unknown transport errors leave the attempt pending with gateway_unavailable instead of falsely assuming that money was not taken; retry the SAME key to reconcile initialization. A new attempt is blocked while an unresolved pending attempt exists. Raw provider exceptions are not exposed.

## Atomic completion and lock order

Order → Payment → Offers ascending ID → DigitalKeys ordered offer/id → game_popularities ascending game_id. Every operation locks Order first, so checkout cancellation/expiration cannot race through the same order. Provider calls are outside these transactions.

PaymentService::applyResult accepts only adapter-verified results internally, never raw client success for a real provider. It locks Order and Payment and returns an existing terminal result unchanged. Opposite repeated simulation cannot flip succeeded to failed or failed to succeeded.

For success, InventoryService::confirmSale verifies and sells the exact reserved keys. Statistics aggregate purchased units by game and increment existing purchase_count atomically. Order becomes paid and payment succeeded in the SAME outer transaction. offers.quantity does not change. Duplicate confirmations neither resell keys nor increment statistics again.

Sale/statistics use a savepoint. The deadline is checked after inventory locks and again after statistics updates. If it expires while waiting, sale/statistics are rolled back before CheckoutService expires the order and releases keys. The attempt becomes cancelled with late_success when a success arrived too late. The transaction commits release/statuses BEFORE returning HTTP 409; no succeeded payment is left attached to an unpaid order.

late_success and external_id preserve a distinction for future real-provider handling: real money may have been charged and needs separate reconciliation/refund work. No refund is attempted here. The current simulator moves no money.

## Database and data preservation

New migration: 2026_11_13_000005_add_payment_attempt_metadata. Nullable fields for historical compatibility: idempotency_key varchar(128), request_fingerprint varchar(64), redirect_url text, error_code varchar(100), error_message varchar(255); UNIQUE(order_id,idempotency_key). Existing indexes/constraints are retained. Rollback refuses to discard populated metadata.

The migration was applied only to verified local postgres/eneba. No historical rows, keys, encryption/HMAC secrets or remote configuration were changed. Existing 493 keys are not used by tests.

## Tests

PostgreSQL suite: tests/Postgres/payments.xml. It uses the same guarded empty disposable eneba_search_test/role/search-postgres alias and separate Docker network as existing suites. PAYMENT_DEMO_ENABLED=true is explicit in the test runner. Do not run against the working eneba database or Neon.

Fixtures are synthetic and committed only in the disposable database so gateway calls execute with transaction level zero. A fixture async adapter checks this property. Tests cover retries, deadlines, paid-key access, cache prevention, provider isolation, outcome idempotency, UNIQUE constraints, statistics and rollback.

Concurrency uses two independent PHP processes/connections. A third connection gates the order row until both workers are observed in PostgreSQL Lock wait state. Scenarios: same result twice, competing attempts, same creation key, cancel versus success, expiration versus success.

Limits: no real providers, webhook authentication, refunds, background reconciliation or frontend. Pending attempts on orders cancelled/expired through checkout alone are reconciled when a payment result is processed; they cannot complete a sale. Future paid-order code must preserve immutable sold-key assignments.
