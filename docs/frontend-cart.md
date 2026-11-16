# Frontend cart

## Storage and operations

`CartProvider.tsx` owns the cart and exposes it through `cart/context.ts`. `eneba:cart` remains a localStorage array of `{offerId, quantity}`; prices, full game records and purchased key material are not persisted there. `parseCart()` accepts positive safe integer IDs/quantities, deduplicates IDs and retains at most 20 unique offers. A full cart rejects a new offer with feedback while allowing changes to an existing offer.

Mutations read the latest stored snapshot rather than trusting a stale render. Quantity buttons apply deltas and clamp against known positive stock. Storage events synchronize tabs without reapplying an old tab's cached stock. If writing fails, the provider keeps an in-memory cart and shows the existing storage warning.

`resolveCartOffers()` requests only the saved bounded IDs. Missing/inactive/unavailable offers remain removable, are excluded from displayed totals and block Checkout. Loading/error keeps saved items; Retry/Refresh prices repeats the batch read. Display totals use integer cents/BigInt. Prices and availability are revalidated by the server at Checkout.

## Stock feedback

`rememberStock()` applies the existing `limitCartQuantities()` only if the response snapshot is still current. A real automatic reduction records its before/after quantities and displays one English status notice per offer in Cart or Game Details. Repeated identical stock reads do not append notices. Manual add/remove/quantity actions clear the relevant notice and never generate a stock-reduction notice. Stale rejected responses cannot announce a reduction that was not applied.

`AddToCartButton` reports successful addition, unavailable stock, stock limit and full-cart rejection. The header derives its badge from the same cart state.

## Logout and account ownership

The metadata key `eneba:cart-owner` records the stable ID of the confirmed authenticated owner. A guest/legacy cart without a marker is adopted by the first confirmed user. Existing ownership cannot be reconstructed retroactively without that marker.

- Successful explicit Logout clears React items, saved cart content, stock notices and temporary UI state. A failed Logout preserves the cart and existing auth recovery behavior.
- A confirmed change of `user.id` clears the previous cart before displaying it to the new user. Names and email are not used as identity.
- Loading, temporary auth/network errors, reload, same-user restoration and PayPal round trips preserve the cart.
- Cleanup invalidates snapshot versions and remounts the existing UI subtree so old requests/temporary notices are disposed. Old results cannot restore the prior basket. Purchase completion checks the active owner before applying cart cleanup.
- Storage synchronization refuses to adopt or overwrite a different owner's shared cart.

This is a single browser-local cart, not independently saved server-side baskets for different accounts. Different-account tabs should not expect simultaneous independent persisted carts. Browser storage is not an authorization boundary; the API still checks ownership. If the browser refuses deletion/writes, in-memory cleanup can run but persistent deletion cannot be guaranteed.

Cart isolation does not cancel an existing server order, release inventory, remove payment identities or claim that payment failed. Checkout/session and purchase-journal details are in [frontend Checkout](frontend-checkout.md).

## Responsive behavior

Desktop keeps the existing list and order-summary layout. At widths up to 800 px the one existing Checkout button appears in a fixed bottom dock with the current total and identical availability rules. Bottom padding/scroll margins keep content reachable, including the final item, and CSS includes the bottom safe-area inset. Quantity hit areas are at least 44 by 44 px.

## Verification

[Testing guide](testing.md) covers native cart arithmetic/normalization and browser cases for stale details, repeated/rapid edits, cross-tab synchronization, oversized legacy carts, disabled storage, stock feedback, account switching, Logout, API failures and mobile dock behavior. The existing real E2E verifies subtraction of only purchased units. Read-modify-write localStorage operations are not a claim of atomic multi-renderer transactions.
