# Statamic Secretary

<img src="docs/assets/statamic-secretary-icon-512.png" alt="Statamic Secretary icon" width="112">

Statamic Secretary is a guarded content assistant for Statamic 6. Editors can request content changes in natural language from the Control Panel or by email, review the resulting draft, and publish only after an explicit command.

The model never receives shell or arbitrary filesystem access. It can only call typed application tools that inspect Statamic collections and blueprints, validate field values, enforce native user policies, and write through Statamic's repositories. All prospective and existing content paths are checked against the configured `content/` boundary, including symlink resolution.

The boundary applies to site-content targets. Secretary necessarily keeps conversations and audit records in the application database, and Statamic Pro may keep working-copy revision metadata in its configured revision store. Neither location is addressable by the model's tools.

## Current scope

- Statamic 6, Laravel 12/13, and PHP 8.3 or newer.
- Collection entries, including structured page collections.
- Existing-entry working copies through Statamic Pro Revisions.
- New unpublished entries that conform to a real collection and blueprint.
- Taxonomy term updates and new terms, staged outside live content until publication.
- Localized global-value updates, staged outside live content until publication.
- Complete navigation-tree updates with node, depth, entry-reference, URL, and blueprint validation.
- Multi-turn Control Panel and Postmark inbound-email conversations.
- Isolated Postmark delivery that does not replace the site's normal mailer.
- Explicit publication, native permissions, optimistic locking, audit records, optional sender allowlists, and webhook idempotency.

Asset binaries and asset metadata, deletion, code, templates, blueprints, and configuration are intentionally outside the agent tools. Statamic commonly stores asset metadata beside the asset file rather than in `content/`, so changing it would violate Secretary's hard content-directory boundary.

## Installation

The package is not on Packagist yet. For local development, add it as a path repository in a Statamic site:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../statamic-secretary",
            "options": { "symlink": true }
        }
    ]
}
```

```shell
composer require axelferdinand/statamic-secretary:@dev
php artisan migrate --force
php please stache:refresh
php please secretary:doctor
```

Statamic Pro and Revisions should be enabled for safe drafts of already-published entries:

```dotenv
STATAMIC_PRO_ENABLED=true
STATAMIC_REVISIONS_ENABLED=true
```

Without Revisions, Secretary may create unpublished entries but refuses to modify a live published entry.

## OpenAI configuration

```dotenv
OPENAI_API_KEY=
SECRETARY_OPENAI_PROJECT=
SECRETARY_OPENAI_MODEL=gpt-5.5
SECRETARY_OPENAI_REASONING_EFFORT=medium
SECRETARY_OPENAI_MAX_OUTPUT_TOKENS=4096
SECRETARY_OPENAI_STORE=true
SECRETARY_MAX_TOOL_ROUNDS=12
SECRETARY_JOB_TIMEOUT=1200
SECRETARY_RETENTION_DAYS=90
SECRETARY_MAX_RESOURCE_CHARACTERS=250000
```

The project ID is optional. The key remains server-side and is never included in content or browser props. `SECRETARY_OPENAI_STORE=false` uses locally stored conversation messages and stateless Responses API tool continuations instead of `previous_response_id`; encrypted reasoning items are requested and replayed so reasoning-model tool turns retain their required context without server-side response storage.

Restrict each content source with comma-separated allowlists. An empty value allows every source of that type, while all writes still remain inside `content/`:

```dotenv
SECRETARY_COLLECTIONS=pages,articles
SECRETARY_TAXONOMIES=topics,tags
SECRETARY_GLOBAL_SETS=company,footer
SECRETARY_NAVIGATIONS=main,footer
SECRETARY_CONTENT_ROOT=/absolute/path/to/site/content
```

`SECRETARY_CONTENT_ROOT` defaults to the site's normal `content` directory.

Run `php please secretary:doctor` after installation and configuration. It checks the database tables, content boundary, model setup, revisions, queue, and optional email setup without printing credentials.

Conversation and audit records are self-hosted in the site's database. Use `php please secretary:prune --days=90` for an interactive retention cleanup, or add `--force` only in a deliberate scheduled task. See [PRIVACY.md](PRIVACY.md).

## Control Panel

Editors with access to Secretary get a floating chat button throughout the Control Panel. It opens a native side panel over the page they are already editing, so they can start or continue a conversation without leaving their work. While Secretary is processing a request, the panel refreshes that conversation automatically and shows the reply and prepared change as soon as they are ready.

The full conversation workspace remains available under **Content → Secretary** for detailed before/after review and publication. Assign these addon permissions to non-super users as needed:

- `use secretary`
- `publish with secretary`
- `configure secretary` (connects or reconnects Postmark)

Every mutation is shown as a change card. Existing published entries are saved to a working copy. Terms, globals, and navigation are instead staged in Secretary's database, because those Statamic resources do not provide working-copy revisions. In both cases, live content does not change until the user selects **Publish** or sends a narrowly recognized immediate publication command.

## Postmark email

Email setup needs one additional environment value:

```dotenv
OPENAI_API_KEY=
POSTMARK_API_KEY=
```

Use the **Server API Token** from the Postmark server. Then open **Content → Secretary** as a super user or a user with `configure secretary`, enter the public Secretary address and connect. Secretary retrieves Postmark's generated inbound address, registers a Basic Auth-protected webhook, configures an isolated outbound mailer, and shows the single forwarding rule to add to the public mailbox.

The site's normal Laravel mailer is not changed, and the required Postmark transport packages are included by this addon. A production `APP_URL` is used automatically when it is public HTTPS. Local `.test` sites instead need a temporary public HTTPS URL, such as Herd Share, during setup.

Inbound senders must match an existing Statamic user with `use secretary`. `SECRETARY_ALLOWED_SENDERS` remains available as an optional additional allowlist; leaving it empty relies on Statamic users and permissions. Postmark must report author-domain `DKIM_VALID_AU` by default, while a generic `SPF_PASS` is insufficient. Email publishing remains disabled unless `SECRETARY_EMAIL_ALLOW_PUBLISHING=true`, and also requires `publish with secretary` plus an authenticated sender.

Replies use the generated Postmark address with plus-addressing (`hash+<conversation-id>@inbound.postmarkapp.com`) so Postmark returns the validated conversation ID as `MailboxHash`. Webhook credentials are derived from the site's `APP_KEY`; reconnect Postmark after rotating that key.

One public mailbox must not be connected directly to multiple sites. The addon includes a disabled-by-default signed receiver, reply client, and email-verified Control Panel pairing flow for the optional shared `secretary@statamic.no` service. A customer needs no mailbox or Postmark account for this mode: an existing Statamic user with `use secretary` requests a 15-minute code at their own email address, pastes it into Secretary, and receives isolated encrypted relay credentials. Requests are bound to one installation ID, route token, independent 256-bit secret, short timestamp window, exact body digest, and single-use nonce; replies remain bound to the same route and conversation. This repository also contains the separate hosted-relay service under `relay/`, deliberately excluded from the Composer addon archive. The hosted HTTPS/Postmark transport, unknown-sender rejection, plain forwarding, and exact plus-tag preservation were verified live on 2026-07-27. The remaining public-release gate is the paired two-installation X/Y/random-sender exercise documented in [docs/shared-address-relay.md](docs/shared-address-relay.md).

Control Panel and inbound-email work is sent to the `secretary` queue. With a persistent production driver, the job is written durably before the HTTP response is returned; the local `sync` fallback defers processing until after the response. A typical production worker includes both queues:

```shell
php artisan queue:work --queue=secretary,default
```

Use a persistent, shared cache for per-conversation locks. Keep the queue connection's `retry_after` and worker timeout longer than `SECRETARY_JOB_TIMEOUT` (1200 seconds by default); lower all three together only after measuring your model/tool workflow. Jobs have a fixed retry deadline of at least 24 hours so newer messages can wait safely behind slower work without exhausting attempts, while real processing exceptions remain bounded. Duplicate provider deliveries may enqueue duplicate jobs intentionally; reply uniqueness, the conversation lock, and provider message IDs make those jobs idempotent.

## Security model

- No shell, PHP execution, generic file-write, generic HTTP, delete, config, or schema tools.
- Strict OpenAI function schemas; arbitrary field data is passed as JSON text and decoded server-side; output tokens, tool rounds, resource payload size, and request rates are bounded.
- Per-resource allowlists, real blueprint handles, editable-field checks, Statamic validation, and native content policies.
- Canonical-path and symlink checks before any content write.
- Working-copy drafts for live entries; database-staged drafts for terms, globals, and navigation; explicit application-side publication for both.
- Separate draft and live-baseline fingerprints prevent a draft from overwriting or publishing over newer human edits, including direct live-file changes while a working copy exists.
- Postmark Basic Auth or installation-scoped relay HMAC, native Statamic-user authorization, optional sender allowlist, author-domain DKIM checks, spam threshold, and unique provider message IDs.
- All returned content is treated as untrusted data to reduce prompt-injection risk.

See [docs/architecture.md](docs/architecture.md) for the detailed trust boundaries and release limits.

## Development

```shell
composer install
npm install
npm run build
composer lint
composer test
```

The repository includes compiled production CP assets in `resources/dist` for Composer/Marketplace installations. Tests cover the content boundary, revisions, staged terms/globals/navigation, unsafe navigation URLs, new entries and terms, conflicts, explicit publication, Responses API tool continuation in stored and stateless modes, CP rendering, Postmark authentication/idempotency, and the addon side of shared-relay tenant isolation.

See the [completion matrix](docs/completion-matrix.md) for requirement-by-requirement evidence and the live gates that remain before a public release.

## Marketplace status

The Composer package structure, compiled assets, support/privacy/security policies, diagnostics, CI matrix, [Marketplace copy](docs/marketplace-listing.md), and [release checklist](docs/release-checklist.md) are ready for a development install. Before a public Marketplace release, the project still needs live proof, a tagged GitHub release, Packagist publication, and final Marketplace assets.
