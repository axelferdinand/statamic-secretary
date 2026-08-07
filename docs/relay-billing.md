# Relay billing and entitlement

Secretary has two independent products:

1. **Secretary addon — USD 49 once per production site.** Sold and licensed through the Statamic Marketplace.
2. **Secretary Relay — USD 49/year per live site.** Sold directly through Stripe Billing because it is an ongoing hosted service. Control Panel chat and customer-managed Postmark do not require this subscription.

The public demo is the trial: its relay installation is explicitly marked `complimentary`. There is no automatic free period on a customer's live domain.

## Customer flow

1. The customer installs the addon and chooses **Easy setup**.
2. Secretary verifies an existing permitted Statamic user's email address.
3. The relay creates an isolated installation in `pending` state and returns a Stripe Checkout URL. It does **not** return a route token, signing secret, or usable email address.
4. Stripe Checkout sells one recurring yearly price for USD 49.
5. Stripe calls `POST /v1/billing/stripe-webhook`. The relay verifies the signature against the unmodified request body and records the event idempotently.
6. The customer returns to Secretary and presses **Finish connection**. The exact pairing retry now returns the site's credentials and provisions the readable alias.
7. Inbound messages, replies, and site-selection notices work only while the installation is `complimentary`, `active`, or `trialing`. A `past_due` subscription remains usable only through its already-paid period end.

## Stripe configuration

Create a normal Stripe account owned by the Secretary business. This is separate from the Stripe Connect account used for Statamic Marketplace payouts.

In Stripe test mode first:

1. Create a product named **Secretary Relay**.
2. Create one recurring price: **USD 49.00, yearly, quantity 1**.
3. Add a webhook endpoint:

   ```text
   https://secretary.statamic.no/v1/billing/stripe-webhook
   ```

4. Subscribe it to:
   - `checkout.session.completed`
   - `checkout.session.async_payment_succeeded`
   - `customer.subscription.created`
   - `customer.subscription.updated`
   - `customer.subscription.deleted`
5. Configure Stripe's hosted customer portal so customers can update payment details, view invoices, and cancel at period end.
6. Decide and publish the legal terms for renewal, cancellation, refunds, taxes, privacy, and support before enabling live mode.

Add the test credentials to the relay environment, never to a customer addon:

```dotenv
RELAY_STRIPE_SECRET_KEY=sk_test_...
RELAY_STRIPE_PRICE_ID=price_...
RELAY_STRIPE_WEBHOOK_SECRET=whsec_...
RELAY_STRIPE_WEBHOOK_TOLERANCE=300
RELAY_BILLING_RATE_LIMIT=300
```

All three secret/price values are required together. With none present, the existing beta relay behavior remains available for controlled migration. A partial Stripe configuration makes the relay fail closed.

## Safe production activation order

Run these commands in the deployed relay directory before adding the Stripe environment values:

```shell
php bin/migrate.php
php bin/manage-installation.php --action=list
php bin/manage-installation.php --action=billing-complimentary --id=<demo installation id>
php bin/manage-installation.php --action=billing-required --id=<each non-demo beta installation id>
```

Confirm the output carefully. `billing-complimentary` is intended only for the public demo or a deliberately comped customer. Do not apply `billing-required` to an installation that already has a real Stripe subscription.

Then add the Stripe environment values, reload PHP, and verify:

1. `GET /health` returns `200`.
2. The demo still accepts and replies to email.
3. A new live pairing exposes Checkout but no relay credentials.
4. A Stripe test payment activates the installation through the webhook.
5. **Finish connection** returns the readable address and email works in both directions.
6. A duplicate webhook changes nothing.
7. Canceling the test subscription causes Relay to reject new traffic after the entitlement ends.

Only after the complete test-mode proof should the relay use `sk_live_…`, the live price id, and the live webhook's separate `whsec_…` secret.

## Operator states

- `beta` — legacy transition state; entitled only while Stripe billing is not configured.
- `complimentary` — entitled without payment; use sparingly and deliberately.
- `pending` — payment required; no relay access.
- `active` / `trialing` — relay access.
- `past_due` — access only through the recorded paid period end.
- `canceled`, `unpaid`, `incomplete`, `incomplete_expired`, `paused` — no relay access.

Billing events are stored by Stripe event ID so retries are idempotent. The relay stores Stripe customer/subscription identifiers and entitlement status, but never card data.
