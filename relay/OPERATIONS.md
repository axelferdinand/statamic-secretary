# Hosted relay operations

The shared-address relay is a separate security boundary. It must not run inside a customer Statamic installation and must never receive a customer OpenAI key or content filesystem access.

## Deployment gate

- Use a dedicated TLS hostname and make `relay/public` the only document root.
- Install with `composer install --no-dev --classmap-authoritative`.
- Keep the SQLite database, database encryption key, Postmark token, and webhook credentials outside the repository and document root.
- Restrict request bodies to 256 KiB at the reverse proxy, disable request-body and `Authorization` logging, and do not follow redirects upstream.
- Apply an IP rate limit at the public reverse proxy. The application also enforces atomic endpoint limits from `REMOTE_ADDR`; it intentionally ignores spoofable forwarding headers. If a trusted proxy is the direct peer, its bucket is a service-wide safety cap rather than a per-customer limit.
- Run more than one PHP worker only on a local filesystem that correctly supports SQLite WAL and locks. Do not place SQLite on NFS.
- Run `php bin/migrate.php` before serving traffic.
- Require a successful `GET /health` after each release.
- Issue short-lived setup codes with `php bin/issue-pairing.php --label="Site" --sender=owner@example.com --minutes=30`; deliver the code only to the authenticated site owner.
- Back up the database and encryption key separately. A database backup without its matching key cannot recover installation signing secrets.
- Schedule `RELAY_RETENTION_DAYS=30 php bin/prune.php` daily.
- Configure uptime checks, 5xx rate alerts, disk-space alerts, backup verification, and log shipping before public use.

## Rate limits and security events

The durable application limits default to:

```dotenv
RELAY_RATE_LIMIT_WINDOW_SECONDS=60
RELAY_POSTMARK_RATE_LIMIT=600
RELAY_REPLY_RATE_LIMIT=300
RELAY_PAIRING_RATE_LIMIT=60
```

Each endpoint and direct socket peer receives an independent fixed-window bucket. Updates use `BEGIN IMMEDIATE`, so multiple PHP workers cannot exceed a bucket through races. The database stores only `HMAC-SHA-256(peer, RELAY_DATABASE_KEY)` and prunes expired buckets with the normal retention command. A rejected request receives `429`, `Retry-After`, `Cache-Control: no-store`, and no customer identifier.

Do not pass an untrusted `X-Forwarded-For` value into PHP as `REMOTE_ADDR`. Per-client public-IP limits belong at the trusted reverse proxy. Keep the application defaults high enough for Postmark retries and normal multi-worker traffic while retaining a bounded service-wide fallback.

Relay security/error records contain an event name, exception class, stable category, UTC time, and a validated rate-limit scope where applicable. They deliberately omit exception messages, request bodies, sender addresses, client addresses, pairing codes, route tokens, signing secrets, webhook URLs, and Postmark tokens. Alert on sustained `authentication`, `rate_limit`, `transient`, and `unexpected` categories without adding those raw values in the log pipeline.

## Postmark

- Use a dedicated Postmark server and server token for the relay.
- Verify `secretary@statamic.no` as the sender.
- Configure the inbound webhook with independent high-entropy HTTP Basic credentials at `/v1/postmark/inbound`.
- Preserve plus addressing when forwarding the public domain to Postmark so `MailboxHash` contains the route and optional conversation token.
- Keep the message stream explicit and monitor bounces and suppressions.

The relay code sends the server token only to `https://api.postmarkapp.com/email`. Site webhook requests use fresh HMAC nonces and a DNS-pinned public HTTPS connection.

## Pairing

- Pairing codes are bearer credentials. Keep their lifetime short, never log them, and send them only through an authenticated customer channel.
- The database stores a SHA-256 digest rather than the code itself.
- A claim is bound to one stable client claim ID. Exact retries receive the same installation credentials; a different claimant receives no credentials.
- The site's webhook must be a public HTTPS URL. Private, loopback, reserved, credential-bearing, query-bearing, and fragment-bearing destinations are rejected.
- Until a customer portal exists, code issuance is an operator action. Do not expose `issue-pairing.php` through the web root.

## Installation controls

Use one exact action per command. Output contains the route, webhook, status, label, and sender memberships, but never the signing secret.

```bash
php bin/manage-installation.php --action=status --id=si_...
php bin/manage-installation.php --action=disable --id=si_...
php bin/manage-installation.php --action=enable --id=si_...
php bin/manage-installation.php --action=add-sender --id=si_... --sender=editor@example.com
php bin/manage-installation.php --action=remove-sender --id=si_... --sender=editor@example.com
```

All actions are retry-safe. Disabling takes effect before the next route decision and preserves installation identity, encrypted signing secret, conversations, and delivery metadata. Removing every sender leaves the installation unreachable by email without deleting its history. Restore at least one authorized sender before enabling normal use.

## Signing-secret rotation

Signing-secret rotation is a manual two-phase protocol. It keeps both directions available while the operator moves the site and relay to the new secret. Do not start a second rotation while a previous-secret grace period is active.

1. Prepare on the relay:

   ```bash
   php bin/rotate-installation-secret.php --action=prepare --id=si_...
   ```

   The response contains a rotation ID and a bearer `signing_secret`. The database stores the secret encrypted. An exact prepare retry returns the same pending rotation and secret. Do not log, email, or paste this response into a ticket.

2. On the paired Statamic site, paste only the secret into the hidden prompt:

   ```bash
   php please secretary:relay-install-secret-rotation sr_... --grace-minutes=15
   ```

   `SECRETARY_RELAY_SIGNING_SECRET` must be unset because environment configuration cannot be changed atomically by the addon. The site immediately signs replies with the new secret and temporarily accepts inbound delivery signed with the old secret. The relay still signs inbound delivery with the old secret and accepts replies signed with either the old or pending secret.

3. Immediately promote the same rotation ID on the relay:

   ```bash
   php bin/rotate-installation-secret.php \
     --action=promote \
     --id=si_... \
     --rotation-id=sr_... \
     --grace-minutes=15
   ```

   The relay now signs inbound delivery with the new secret and temporarily accepts the old secret for replies already in flight. Exact promote retries are safe and never print a secret.

Complete step 3 before the site's grace window expires. If promotion is interrupted, retry the exact promote command; do not prepare another rotation. After the window, `prune.php` removes the expired encrypted previous secret. This procedure rotates only the HMAC signing secret; use the separate route procedure below for the public alias.

## Route and alias rotation

Route rotation uses the same ordered prepare/install/promote protocol, but retired public aliases are retained for existing conversation tokens rather than removed on a timer:

1. Prepare a new opaque alias on the relay:

   ```bash
   php bin/rotate-installation-route.php --action=prepare --id=si_...
   ```

   Exact retries return the same rotation ID, route token, and public address. The pending alias cannot start a conversation.

2. Install it on the Statamic site:

   ```bash
   php please secretary:relay-install-route-rotation \
     rr_... \
     r... \
     --transition-minutes=15
   ```

   `SECRETARY_RELAY_ROUTE_TOKEN` must be unset. The encrypted site setting makes the new token current, temporarily lets the previous token start a thread while the relay still uses it, and permanently retains that token only for follow-ups carrying an existing bound conversation token.

3. Immediately promote the same rotation on the relay:

   ```bash
   php bin/rotate-installation-route.php \
     --action=promote \
     --id=si_... \
     --rotation-id=rr_... \
     --transition-minutes=15
   ```

   The new alias becomes current atomically. The previous alias can no longer start a conversation, but an existing route-and-conversation pair continues to resolve to the same installation and reply address. Exact promote retries are safe.

Complete step 3 before the site's transition expires. A second rotation is refused until the selected 5–60 minute transition has elapsed. Never delete a retired route row while a conversation or inbound-delivery row still references it.

## Incident response

1. Disable the affected installation in the database or stop the relay if the affected scope is unknown.
2. Rotate the Postmark server token if outbound access may be exposed.
3. Rotate the webhook Basic password if inbound authentication may be exposed.
4. Rotate the database encryption key only through a purpose-built re-encryption procedure; changing the environment value alone makes stored installation secrets unreadable.
5. Use the two-phase procedures above for an installation signing secret or route. Re-provision only when the entire installation identity must change.
6. Preserve metadata and logs required for investigation without copying email bodies or site content into incident tickets.
7. Verify that no message crossed installation boundaries before restoring public traffic.

## Restore test

Restore the database and its matching encryption key into an isolated environment, run `/health`, load each installation through `SqliteRelayStore`, and perform signed delivery/reply smoke tests without using production recipients. Test this procedure before the shared address is advertised.
