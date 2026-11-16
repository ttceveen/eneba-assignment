# Frontend Checkout

## Components and flow

The implemented route sequence is catalog, `GameDetails`, `Cart`, `Checkout`, `PaymentPage`, and `Success`. PayPal adds `PayPalReturn`. `RequireAuth` protects Checkout/order/return routes and preserves their destination through login/register.

- `Cart.tsx` resolves saved offer IDs through the existing batch GET, calculates display totals with integer cents/BigInt, and keeps unavailable offers removable. Checkout is disabled until all saved items resolve as available.
- `Checkout.tsx` uses the cart snapshot and a per-user/tab checkout attempt. It calls the existing POST `/api/checkout` with only offer IDs/quantities and a stable `Idempotency-Key`. A known order is resumed rather than created again.
- `Payment.tsx` loads the server order, its fixed item prices, original deadline and existing attempts. It never treats a local choice as proof of payment.
- `Summary.tsx` renders full order details and the server-confirmed total. It is also used on Success.

## Payment actions

Fake Card is selected by default when no pending attempt determines the method. Its success/failure radio choices drive the existing demo simulate endpoint; no real card details are collected. A confirmed decline allows an explicit retry within the original reservation. A pending attempt keeps its existing provider and displays: “Payment method is locked for the current payment attempt.”

PayPal creation opens a validated existing Sandbox Approval URL. **Continue in PayPal** opens that URL again; it does not create a payment or confirm it. **Confirm PayPal payment** resumes the existing confirmation path and can initiate capture. The duplicate status-check link was removed. Explanatory text distinguishes external approval from server confirmation.

Busy/uncertain states disable unsafe actions. The existing lock refs prevent duplicate clicks. Creation loss retries the same stored attempt/key; unknown outcomes first require refreshing the server order. There are no new payment endpoints or automatic replacement attempts.

## PayPal return

`PayPalReturn.tsx` accepts exactly one valid `token` PayPal Order ID. A normal return runs the existing confirmation automatically; `cancelled=1` does not automatically confirm or cancel. The explicit confirmation button can complete an approved payment, so it is not presented as a read-only status check.

After receiving a Payment, **Back to checkout** uses its `order_id`. Until then it falls back to the saved checkout context, or `/checkout` if none exists. A pending result preserves the cart and permits reopening an available Approval URL or explicitly retrying confirmation. Errors use `role="alert"` and do not expose provider/backend details. A return token alone never authorizes a purchase or key delivery.

## Success and delivery

`Success.tsx` fetches the server order before requesting keys. Before verified `paid`, it shows a neutral checking state. An unpaid order returns to Payment without requesting keys. A failed verification does not show “Purchase successful.”

After verified `paid`, the component applies this browser's purchase allocation journal and fetches the owner-only keys endpoint. Key loading can be retried without paying again. A delivery error states that payment is confirmed and does not claim successful delivery. Copy buttons use the Clipboard API, with manual-copy guidance if unavailable. Keys are not persisted to browser storage.

## Errors and Retry

Messages distinguish order creation, payment initiation, uncertain confirmation, order loading and key loading. They retain actionable HTTP guidance without rendering raw Laravel/provider exception text. A timeout never states that no payment occurred.

- Checkout Retry reuses its request key and original cart attempt.
- Payment refresh reads the current order before resuming uncertain work.
- PayPal confirmation retries the same external order.
- Success retries order/key reads, not payment creation or capture.

When the initial reservation countdown ends, Payment refreshes the server once. It no longer keeps displaying `0:00` or an endless “checking” message. A server-pending response is described as awaiting payment; the frontend does not assert that a protected reserve expired or was extended.

## Mobile behavior

On screens up to 900 px, Payment shows a compact server total, method choices and the single primary payment action before secondary information. Full order details are initially collapsed behind an accessible button with `aria-expanded` and `aria-controls`. Keyboard activation expands the same details. Desktop retains full details on the left and payment controls on the right.

At up to 800 px, Cart moves its one existing Checkout action into a fixed bottom dock with the same total/disabled conditions. Bottom spacing and scroll margins keep final content reachable; `safe-area-inset-bottom` is included. Quantity hit areas are at least 44 by 44 px. The narrow Game Details cover/spacing is reduced without changing image proportions or adding a sticky purchase button.

## Persistence and account boundaries

`CartProvider` stores an offer ID/quantity array under `eneba:cart`. `eneba:cart-owner` records a stable confirmed user ID. Successful explicit Logout clears state, storage and notices. A confirmed change of ID clears the prior basket before it is shown to the new user. Loading, temporary auth errors, reload and restoration of the same ID preserve it. A cart without an owner marker is treated as a guest cart.

The ownership boundary invalidates snapshots and remounts the existing UI subtree to dispose temporary notices and abort old requests. Storage synchronization does not adopt/overwrite another owner's cart. This remains a browser-local cart, not separate server-backed account baskets. See [cart details](frontend-cart.md).

`checkout/session.ts` stores checkout/payment attempt IDs and idempotency metadata in sessionStorage, scoped by user/order. `cartPurchases.ts` tracks the original checked-out quantities separately from later edits. Paid completion subtracts only the original allocation, once; a missing/ambiguous journal preserves the current basket rather than guessing. This journal is not authorization and contains no key material. Logout/account isolation does not cancel server orders or reset payment attempts.

## Verification and limits

Use [testing instructions](testing.md). Controlled browser suites cover auth redirects, duplicate clicks, return context, continuation versus confirmation, pending/error/deadline states, disclosure controls, cart synchronization/isolation and paid-only delivery. The opt-in real Laravel/PostgreSQL E2E covers a Fake Card decline, successful retry and synthetic key delivery.

There is no order-history listing, email delivery, automatic provider polling, refund flow or background reconciliation. A protected uncertain PayPal reservation requires the recovery described in [payments](payments.md). Screens rely on current game/seller descriptions, while unit prices remain order snapshots.
