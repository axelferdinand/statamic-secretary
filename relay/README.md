# Statamic Secretary hosted relay core

This directory contains the framework-independent routing and security core for the optional shared `secretary@statamic.no` service. It is deliberately excluded from the Composer addon archive and is not deployed by installing the addon.

The core has no model access and never selects a site from prompt text. It routes to exactly one active installation from an opaque route alias, or from an exact sender match only when that sender belongs to exactly one installation. It signs site deliveries, verifies signed reply requests, enforces route/conversation binding, and exposes atomic persistence interfaces for nonce, inbound, and outbound idempotency. Provider IDs and reply idempotency keys are bound to exact SHA-256 request fingerprints, so the same identity cannot be reused with changed content.

`Persistence\SqliteSchema` and `Persistence\SqliteRelayStore` provide the first durable adapter. Installation signing secrets are encrypted with libsodium, two-phase signing-secret and route rotations are atomic and retry-safe, retired routes remain bound only to their existing conversations, claims are atomic across processes, crashed work can be reclaimed only after a bounded lease, old owners cannot complete reclaimed claims, nonces remain single-use across workers, and delivery metadata can be pruned without removing installations or conversation bindings. The database encryption key is a separate random 32-byte value loaded from deployment secrets. `CurlHttpTransport` resolves and pins public DNS destinations, blocks private or reserved addresses and redirects, verifies TLS hostnames, bounds request and response sizes, and rejects unsafe headers. Shared Postmark delivery is fixed to the official `/email` endpoint.

This directory is also a standalone Composer project. Point a TLS-only PHP site at `public/`, set the environment values below, and run the migration command. `public/index.php` exposes `GET /health`, authenticated `POST /v1/postmark/inbound`, signed `POST /v1/replies`, and one-time `POST /v1/pairings/claim`. Permanent unsafe mail is acknowledged without forwarding so Postmark does not retry it forever; temporary site/Postmark failures and concurrent processing return retryable `503` responses.

```dotenv
RELAY_DATABASE_PATH=/absolute/private/path/relay.sqlite
RELAY_DATABASE_KEY=<base64-encoded-32-byte-key>
RELAY_POSTMARK_SERVER_TOKEN=
RELAY_POSTMARK_WEBHOOK_USER=
RELAY_POSTMARK_WEBHOOK_PASSWORD=
RELAY_SHARED_ADDRESS=secretary@statamic.no
RELAY_FROM_ADDRESS=secretary@statamic.no
RELAY_POSTMARK_MESSAGE_STREAM=outbound
RELAY_REQUIRE_SENDER_AUTHENTICATION=true
RELAY_MAXIMUM_SPAM_SCORE=5
RELAY_RETENTION_DAYS=30
RELAY_RATE_LIMIT_WINDOW_SECONDS=60
RELAY_POSTMARK_RATE_LIMIT=600
RELAY_REPLY_RATE_LIMIT=300
RELAY_PAIRING_RATE_LIMIT=60
```

```bash
composer install --no-dev --classmap-authoritative
php bin/migrate.php
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

The manual provisioning command prints the four site-specific values once. The pairing command instead prints a short-lived one-time code for the site's administrator to paste into the Secretary control panel. The server stores only the pairing-code digest and stores the resulting signing secret encrypted; a retry of the same claim returns the same isolated installation, while a different claim cannot reuse the code. `manage-installation.php` can show redacted status, enable/disable one installation, or add/remove one exact sender without exposing the signing secret. `rotate-installation-secret.php` deliberately returns a new bearer secret during its prepare phase; transfer it directly to the site's hidden Artisan prompt and promote the same rotation ID within the chosen grace window. `rotate-installation-route.php` performs the equivalent prepare/install/promote flow for a public alias while keeping existing retired-route threads bound to the original site. Endpoint limits use atomic SQLite windows keyed by an HMAC of the direct socket peer; a limit returns `429` plus `Retry-After`. Security logs contain stable categories and exception classes but never exception messages or request identities. Configure Postmark's inbound webhook as `https://<basic-auth>@relay.example/v1/postmark/inbound`, keep the SQLite file and encryption key outside the document root, and schedule `prune.php`. See [`OPERATIONS.md`](OPERATIONS.md) before deployment.

A production service still needs an authenticated customer-facing code-issuance surface, production metrics/alerts, and a separately approved deployment plus two-site isolation proof. See [`../docs/shared-address-relay.md`](../docs/shared-address-relay.md).
