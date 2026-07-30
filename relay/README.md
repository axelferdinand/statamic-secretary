# Statamic Secretary hosted relay core

This directory contains the framework-independent routing and security core for the optional shared `secretary@statamic.no` service. It is deliberately excluded from the Composer addon archive and is not deployed by installing the addon.

The core has no model access and never selects a site from prompt text. It routes to exactly one active installation from an opaque route alias, or from an exact sender match only when that sender belongs to exactly one installation. It signs site deliveries, verifies signed reply requests, enforces route/conversation binding, and exposes atomic persistence interfaces for nonce, inbound, and outbound idempotency. Provider IDs and reply idempotency keys are bound to exact SHA-256 request fingerprints, so the same identity cannot be reused with changed content.

`Persistence\SqliteSchema` and `Persistence\SqliteRelayStore` provide the first durable adapter. Installation signing secrets are encrypted with AES-256-GCM through OpenSSL; legacy libsodium ciphertext remains readable when that optional extension is available. Two-phase signing-secret and route rotations are atomic and retry-safe, retired routes remain bound only to their existing conversations, claims are atomic across processes, crashed work can be reclaimed only after a bounded lease, old owners cannot complete reclaimed claims, nonces remain single-use across workers, and delivery metadata can be pruned without removing installations or conversation bindings. The database encryption key is a separate random 32-byte value loaded from deployment secrets. `CurlHttpTransport` resolves and pins public DNS destinations, blocks private or reserved addresses and redirects, verifies TLS hostnames, bounds request and response sizes, and rejects unsafe headers. Shared Postmark delivery is fixed to the official `/email` endpoint.

This directory is also a standalone Composer project. Point a TLS-only PHP site at `public/`, set the environment values below, and run the migration command. `public/index.php` exposes `GET /health`, authenticated `POST /v1/postmark/inbound`, signed `POST /v1/replies`, email-verified `POST /v1/pairings/request`, and one-time `POST /v1/pairings/claim`. Permanent unsafe mail is acknowledged without forwarding so Postmark does not retry it forever; temporary site/Postmark failures and concurrent processing return retryable `503` responses.

```dotenv
RELAY_DATABASE_PATH=/absolute/private/path/relay.sqlite
RELAY_DATABASE_KEY=<base64-encoded-32-byte-key>
RELAY_POSTMARK_SERVER_TOKEN=
RELAY_POSTMARK_WEBHOOK_USER=
RELAY_POSTMARK_WEBHOOK_PASSWORD=
RELAY_PUBLIC_URL=https://secretary.statamic.no
RELAY_SHARED_ADDRESS=secretary@statamic.no
RELAY_FROM_ADDRESS=secretary@statamic.no
RELAY_POSTMARK_MESSAGE_STREAM=outbound
RELAY_REQUIRE_SENDER_AUTHENTICATION=false
RELAY_MAXIMUM_SPAM_SCORE=5
RELAY_RETENTION_DAYS=30
RELAY_RATE_LIMIT_WINDOW_SECONDS=60
RELAY_POSTMARK_RATE_LIMIT=600
RELAY_REPLY_RATE_LIMIT=300
RELAY_PAIRING_RATE_LIMIT=60
RELAY_PAIRING_REQUEST_RATE_LIMIT=10
RELAY_PAIRING_RECIPIENT_RATE_LIMIT=3
```

```bash
composer install --no-dev --classmap-authoritative
php bin/migrate.php
php bin/configure-postmark.php
php bin/poll-postmark.php --verbose
php bin/provision.php \
  --webhook=https://site.example/_secretary/webhooks/relay/inbound \
  --label="Site" \
  --sender=editor@example.com
php bin/issue-pairing.php \
  --label="Site" \
  --sender=editor@example.com \
  --minutes=30
php bin/manage-installation.php \
  --action=status \
  --id=si_...
php bin/rotate-installation-secret.php \
  --action=prepare \
  --id=si_...
php bin/rotate-installation-route.php \
  --action=prepare \
  --id=si_...
php bin/prune.php
```

Normal customers never run the operator commands. In the Statamic Control Panel they request a code for an existing Secretary user's email address. The relay sends the code only to that address, retains only its digest, and lets the addon claim one isolated installation. The signing secret is encrypted at rest and never exposed to the browser. A retry of the same claim returns the same isolated installation, while another claimant cannot reuse the code.

The manual provisioning and pairing commands remain available for incident recovery and controlled tests. `manage-installation.php` can show redacted status, enable/disable one installation, or add/remove one exact sender without exposing the signing secret. `rotate-installation-secret.php` and `rotate-installation-route.php` implement the documented two-phase rotations. Endpoint limits use atomic SQLite windows keyed by an HMAC of the direct socket peer; a limit returns `429` plus `Retry-After`. Security logs contain stable categories and exception classes but never exception messages or request identities. See [`OPERATIONS.md`](OPERATIONS.md) before deployment.

`poll-postmark.php` is a safe outbound-only fallback for hosts that block Postmark's
inbound webhook addresses before PHP. Schedule it every minute while keeping the
webhook configured. It reads only scheduled or failed inbound messages from the
fixed Postmark API, sends them through the exact same validation and routing path,
and uses durable leases plus the normal inbound idempotency checks.

The shared service defaults `RELAY_REQUIRE_SENDER_AUTHENTICATION` to `false`.
Customer access is instead determined by the email-verified pairing and an exact,
active sender membership for a Statamic user with `use secretary`. The relay marks
that sender as authorized only after the installation has been resolved. Operators
may enable author-domain DKIM as an optional stricter policy, but it is not a
customer setup requirement.

A production service still needs production metrics/alerts, a separately approved deployment, and two-site isolation proof. Follow [`DEPLOYMENT.md`](DEPLOYMENT.md) and the contract in [`../docs/shared-address-relay.md`](../docs/shared-address-relay.md).
