# Authentication

## Backend

Laravel Sanctum issues personal access Bearer tokens with a seven-day expiration. Register/login return `{data: user, token, token_type: "Bearer"}`. Only the issuance response contains the plaintext token; Sanctum stores its hash. User passwords use Laravel hashing and are excluded from public responses.

`auth:sanctum` and `EnsureUserIsActive` protect Checkout, order/payment/key and account routes. `sanctum.guard=[]` excludes web-session fallback. Blocked accounts cannot reuse a previously issued token. Logout deletes only the current access token; independently issued tokens remain valid until revoked/expired.

| Method | Route | Behavior |
| --- | --- | --- |
| POST | `/api/register` | First/last name, email and password; 201 with token/user |
| POST | `/api/login` | Email/password; token/user |
| GET | `/api/me` | Current active user |
| POST | `/api/logout` | Revoke current token |

Validation/incorrect credentials return 422, unauthenticated requests 401, blocked accounts 403 and throttled auth requests 429. Register/login are throttled. The user resource lists ID, names, email, status and timestamps, not password hashes. Email normalization and database uniqueness supplement request validation.

API calls are stateless Bearer requests with `credentials: 'omit'`; no CSRF-cookie bootstrap or XSRF header is used. Standard web CSRF protection remains in place. The package's CSRF-cookie route is not used by this SPA.

## Frontend lifecycle

`AuthProvider.tsx` stores the token only in sessionStorage under `eneba:auth-token`. `/me` restores the profile on reload, focus and protected-route transitions. Abort/version checks reject stale responses. A failed Logout schedules a fresh account check rather than leaving a permanently loading protected route. A successful explicit Logout emits `logoutRevision` after the existing revoke flow.

401/403 clear invalid authorization. Temporary transport/server failures preserve the stored token and allow retry. A transient missing profile is not proof of account switching. `RequireAuth` waits for verification and preserves the requested Checkout/order/PayPal return URL through login/register.

`CartProvider` uses the successful Logout signal and stable confirmed `user.id` to clear the previous basket and UI state. It does not clear merely because auth is loading or temporarily unverifiable. See [cart isolation](frontend-cart.md). Retiring the obsolete localStorage auth-token entry is separate from cart storage.

Each independent browser tab has its own sessionStorage. A browser may copy an opener's token to a new tab; revoking that shared token invalidates both copies at their next check. Session restoration after closing/restarting a tab depends on browser behavior; the application does not maintain an extra persistent credential copy.

## Security and deployment

The production frontend rejects an HTTP API URL before sending protected requests. Use the HTTPS backend URL and configured frontend origin. CORS permits the configured origin and local development origin, allows Authorization/Idempotency-Key and does not enable credential cookies.

sessionStorage is readable by JavaScript: it limits persistence, not XSS impact. Keep normal JSX escaping and avoid untrusted HTML/script injection. There is no automated expired-token pruning job in this repository. API authorization remains server-side; React route guards do not grant access to another user's order.

## Verification

[Testing guide](testing.md) documents the SQLite auth/CORS tests and controlled browser suites. Existing coverage includes hashed/private responses, expiration, revoked/blocked tokens, web-session rejection, real web CSRF, login destination preservation, stale response ordering, failed Logout recovery and cart ownership boundaries. No production login is required for these tests.
