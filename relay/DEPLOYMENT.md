# Production deployment

The hosted relay is separate from every customer Statamic installation. It never receives a customer OpenAI key and its web document root must be this project's `public/` directory.

## 1. Host and TLS

1. Create `secretary.statamic.no` on the production host.
2. Point its document root to the deployed `relay/public` directory.
3. Add an `A` or `AAAA` record for `secretary.statamic.no`.
4. Wait for DNS, then enable a valid TLS certificate.
5. Confirm that only `public/` is web-accessible and that `GET https://secretary.statamic.no/health` returns `{"status":"ok"}`.

The included `public/.htaccess` routes requests to `index.php`, disables directory indexes, and preserves the `Authorization` header for Postmark Basic Auth.

If authoritative DNS is managed outside the hosting account, `RELAY_PUBLIC_URL` may instead use a fixed path below an existing valid HTTPS origin. Point only that path at `public/index.php`, strip the path prefix before dispatch, and keep the rest of the relay outside the web root. `configure-postmark.php` preserves such a prefix in the inbound webhook URL.

## 2. Private environment

Copy `.env.example` to `.env` outside the document root. Generate independent values:

```bash
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
php -r 'echo rtrim(strtr(base64_encode(random_bytes(32)), "+/", "-_"), "="), PHP_EOL;'
```

Use the first value for `RELAY_DATABASE_KEY` and the second as a webhook password of at least 32 characters. Set:

```dotenv
RELAY_DATABASE_PATH=/absolute/private/path/relay.sqlite
RELAY_DATABASE_KEY=
RELAY_POSTMARK_SERVER_TOKEN=
RELAY_POSTMARK_WEBHOOK_USER=postmark_webhook
RELAY_POSTMARK_WEBHOOK_PASSWORD=
RELAY_PUBLIC_URL=https://secretary.statamic.no
RELAY_SHARED_ADDRESS=secretary@statamic.no
RELAY_FROM_ADDRESS=secretary@statamic.no
RELAY_FRIENDLY_ALIASES_ENABLED=true
RELAY_CPANEL_URL=https://host.example.com:2083
RELAY_CPANEL_USER=
RELAY_CPANEL_TOKEN=
RELAY_POSTMARK_INBOUND_ADDRESS=
RELAY_GA_MEASUREMENT_ID=
RELAY_STRIPE_SECRET_KEY=
RELAY_STRIPE_PRICE_ID=
RELAY_STRIPE_WEBHOOK_SECRET=
RELAY_STRIPE_WEBHOOK_TOLERANCE=300
RELAY_BILLING_RATE_LIMIT=300
RELAY_REQUIRE_SENDER_AUTHENTICATION=false
RELAY_MAXIMUM_REQUEST_BYTES=24000000
RELAY_MAXIMUM_ATTACHMENTS=4
RELAY_MAXIMUM_ATTACHMENT_BYTES=8000000
RELAY_MAXIMUM_TOTAL_ATTACHMENT_BYTES=16000000
```

Never place the SQLite file, `.env`, backups, or logs inside `public/`.

Stripe billing is optional during a controlled beta, but all three Stripe secret/price
values are required together when paid Relay is enabled. Follow the migration and
demo allowlisting order in [`../docs/relay-billing.md`](../docs/relay-billing.md)
before adding them. A partial configuration fails closed.

Friendly aliases give each site a readable address such as
`customer.example@statamic.no`. The cPanel token must be restricted to the
mail-forwarder operations needed by the relay. Each exact alias is forwarded to
Postmark with the installation's opaque route tag; do not configure a catch-all.
After enabling the feature on an existing relay, run:

```bash
php bin/migrate.php
php bin/provision-public-aliases.php
```

The second command is idempotent and provisions aliases for active installations.

`RELAY_GA_MEASUREMENT_ID` is optional. Set it to the landing site's GA4 web-stream ID
(`G-…`) to enable the consent manager. The Google tag is not requested before the
visitor accepts analytics. Declining leaves analytics unloaded; withdrawing consent
disables further measurement and removes known `_ga` cookies. Advertising storage,
personalization, Google Signals, and ad user data remain disabled.

## 3. Install and connect Postmark

```bash
composer install --no-dev --classmap-authoritative
php bin/migrate.php
php bin/configure-postmark.php
```

`configure-postmark.php` updates only the inbound webhook on the configured Postmark server. It sends the server token only to Postmark's fixed HTTPS API endpoint and does not print credentials.

Set the web server/PHP request-body limit to at least `RELAY_MAXIMUM_REQUEST_BYTES` and no more than the hard 32 MB application entry limit. The larger envelope accounts for base64 encoding while decoded image limits remain lower.

Forward `secretary@statamic.no` to the server's Postmark inbound address. With
friendly aliases enabled, the relay creates one exact cPanel forwarder per site,
for example `customer.example@statamic.no` to
`postmark-mailbox+r…@inbound.postmarkapp.com`. Before customer traffic, send one
test through a friendly alias and confirm Postmark reports the opaque route tag as
`MailboxHash`. Also test a reply to the route-and-conversation `Reply-To` address.
If the forwarder strips the envelope plus tag, the top-level `MailboxHash` may be
empty; Postmark must still retain the exact tagged address and matching
per-recipient hash in `ToFull`, which the relay validates before continuing the
conversation.

On shared hosts that drop Postmark's webhook requests before PHP, add an every-minute
cron entry for `php bin/poll-postmark.php`. The poller is an outbound-only fallback;
it does not weaken sender authentication and the normal message-ID idempotency makes
it safe to run alongside the webhook.

Hosted mode uses the email-verified pairing and exact active sender membership as
its authorization boundary, so customers do not need to change SPF, DKIM, or DMARC.
Set `RELAY_REQUIRE_SENDER_AUTHENTICATION=true` only if the operator deliberately
wants to require author-domain DKIM from every customer domain.

## 4. Stripe Billing

Create a USD 49 yearly recurring Stripe price and register
`POST https://secretary.statamic.no/v1/billing/stripe-webhook` for the Checkout and
subscription events listed in [`../docs/relay-billing.md`](../docs/relay-billing.md).
Use test mode for the complete unpaid → payment → activation → cancellation proof.
Configure Stripe's hosted customer portal and publish renewal, cancellation,
refund, tax, privacy, and support terms before replacing test credentials with live
credentials.

## 5. Customer addon

Each Statamic installation adds:

```dotenv
SECRETARY_RELAY_PAIRING_ENABLED=true
SECRETARY_RELAY_BASE_URL=https://secretary.statamic.no
SECRETARY_RELAY_CACHE_STORE=redis
```

An administrator then opens **Content → Secretary**, enters the email of an existing Statamic user with `use secretary`, requests the code, and pastes the received code. The addon stores the installation credentials encrypted. The customer does not need a `secretary@…` mailbox or a Postmark account for this hosted mode.

## 6. Operations gate

Schedule `php bin/prune.php`, back up the database and encryption key separately, and configure uptime, 5xx, disk, and backup alerts. Complete the two-installation isolation exercise in [`../docs/shared-address-relay.md`](../docs/shared-address-relay.md) before describing the shared address as production-ready.

## 7. Landing-site search setup

After deployment:

1. Confirm `/robots.txt`, `/sitemap.xml`, `/privacy`, and a missing URL return the expected status and content.
2. Verify the canonical HTTPS host redirects HTTP and alternate hosts with `301`.
3. Add `https://secretary.statamic.no` as a Google Search Console property.
4. Submit `https://secretary.statamic.no/sitemap.xml` and request indexing for `/`.
5. With a GA4 ID configured, test accept, decline, and withdrawal in a fresh browser profile. A declined visit must not request Google Analytics or create `_ga` cookies.
