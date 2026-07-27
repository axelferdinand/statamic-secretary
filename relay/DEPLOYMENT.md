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
```

Never place the SQLite file, `.env`, backups, or logs inside `public/`.

## 3. Install and connect Postmark

```bash
composer install --no-dev --classmap-authoritative
php bin/migrate.php
php bin/configure-postmark.php
```

`configure-postmark.php` updates only the inbound webhook on the configured Postmark server. It sends the server token only to Postmark's fixed HTTPS API endpoint and does not print credentials.

Forward `secretary@statamic.no` and plus-address variants such as `secretary+r…@statamic.no` to the server's Postmark inbound address. Do not remove the plus tag. Before customer traffic, send one tagged test and confirm Postmark reports the same tag as `MailboxHash`.

## 4. Customer addon

Each Statamic installation adds:

```dotenv
SECRETARY_RELAY_PAIRING_ENABLED=true
SECRETARY_RELAY_BASE_URL=https://secretary.statamic.no
SECRETARY_RELAY_CACHE_STORE=redis
```

An administrator then opens **Content → Secretary**, enters the email of an existing Statamic user with `use secretary`, requests the code, and pastes the received code. The addon stores the installation credentials encrypted. The customer does not need a `secretary@…` mailbox or a Postmark account for this hosted mode.

## 5. Operations gate

Schedule `php bin/prune.php`, back up the database and encryption key separately, and configure uptime, 5xx, disk, and backup alerts. Complete the two-installation isolation exercise in [`../docs/shared-address-relay.md`](../docs/shared-address-relay.md) before describing the shared address as production-ready.
