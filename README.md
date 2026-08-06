# Secretary

<img src="docs/assets/statamic-secretary-icon-512.png" alt="Secretary icon" width="112">

Secretary for Statamic is a guarded content assistant for Statamic 6. Editors can request content changes in natural language from the Control Panel or by email, review the resulting draft, and publish only after an explicit command.

> **Commercial addon:** Secretary costs **USD 49 per production site** through the Statamic Marketplace. You may use it without a license during local development and testing, but each production installation requires a valid Marketplace license. See [LICENSE.md](LICENSE.md).

## Naming

The public addon name is **Secretary**. Use **Secretary for Statamic** when a first mention or marketing context needs to explain the platform. The technical Composer package, repository, namespace, routes, handles, and asset filenames intentionally remain `axelferdinand/statamic-secretary` and `statamic-secretary` for compatibility.

The model never receives shell, arbitrary filesystem, or generic web access. It can only call typed application tools that inspect Statamic collections, blueprints, and configured asset containers, validate field values, enforce native user policies, and write through Statamic's repositories. All prospective and existing content paths are checked against the configured `content/` boundary, including symlink resolution. Image imports use a separate, narrow Statamic asset boundary.

The boundary applies to site-content targets. Secretary keeps conversations and audit records in a private SQLite database under Laravel's `storage` directory, and Statamic Pro may keep working-copy revision metadata in its configured revision store. Neither location is addressable by the model's tools.

## Current scope

- Statamic 6, Laravel 12/13, and PHP 8.3 or newer.
- Collection entries, including structured page collections.
- Existing-entry working copies through Statamic Pro Revisions.
- New unpublished entries that conform to a real collection and blueprint.
- Taxonomy term updates and new terms, staged outside live content until publication.
- Localized global-value updates, staged outside live content until publication.
- Complete navigation-tree updates with node, depth, entry-reference, URL, and blueprint validation.
- Multi-turn Control Panel and Postmark inbound-email conversations.
- Permission-filtered search and visual inspection of existing JPEG, PNG, and WebP Statamic assets.
- Authenticated image attachments imported append-only into one configured Statamic asset container.
- Isolated Postmark delivery that does not replace the site's normal mailer.
- Explicit publication, native permissions, optimistic locking, audit records, optional sender allowlists, and webhook idempotency.

Asset deletion, replacement, arbitrary uploads, metadata editing, code, templates, blueprints, and configuration remain outside the agent tools. Secretary can read supported images from configured Statamic asset containers and import authenticated email attachments through Statamic's upload API. Imported paths are content-addressed; an existing path is verified and never overwritten.

## Installation

Install Secretary in a Statamic 6 site:

```shell
composer require axelferdinand/statamic-secretary
```

That is the complete standard installation. Statamic publishes the Control Panel assets, while Secretary creates and migrates its own private SQLite store independently of the site's database configuration. Open **Content → Secretary** to add the OpenAI key and choose the easy hosted relay or the advanced self-hosted Postmark setup.

Deployment systems with a read-only build stage may set `SECRETARY_AUTO_MIGRATE=false`; Secretary finishes private-storage setup automatically on its first Control Panel or inbound-email request. `storage` must be writable, as Laravel already requires. An advanced installation may opt into an existing Laravel connection with `SECRETARY_DB_CONNECTION`, but no external database is required.

Secretary setup is environment-specific. A local encrypted database and its secrets should not be deployed to production. The production installation creates a clean private store and guides an administrator through connecting OpenAI and email once for that live URL.

For local addon development, add the repository as a path repository in a Statamic site:

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
```

Safe drafts of already-published entries require Statamic Pro. First-run onboarding explains the working-copy model and offers one **Enable safe drafts** action. That action enables Statamic revisions globally for Secretary and on every current collection; it does not change or publish entry content. If a new collection is later added without revisions, onboarding and Control Panel diagnostics surface the same action again.

## OpenAI configuration

The first-run Control Panel setup accepts the OpenAI API key and stores it encrypted with the site's `APP_KEY`. Environment configuration remains available for teams that prefer secrets-as-code and takes precedence over the Control Panel value:

```dotenv
OPENAI_API_KEY=
SECRETARY_OPENAI_PROJECT=
SECRETARY_OPENAI_MODEL=gpt-5.5
SECRETARY_OPENAI_REASONING_EFFORT=medium
SECRETARY_OPENAI_MAX_OUTPUT_TOKENS=4096
SECRETARY_OPENAI_STORE=true
SECRETARY_MAX_TOOL_ROUNDS=20
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
SECRETARY_ASSET_CONTAINERS=assets
SECRETARY_ATTACHMENT_CONTAINER=assets
SECRETARY_ATTACHMENT_FOLDER=secretary-inbox
```

`SECRETARY_CONTENT_ROOT` defaults to the site's normal `content` directory.
Asset values are optional. With exactly one uploadable asset container, Secretary selects it automatically. Configure `SECRETARY_ATTACHMENT_CONTAINER` when a site has more than one. Existing-asset search and attachment imports always apply the requesting Statamic user's native view/upload permissions. Email accepts up to four JPEG, PNG, or WebP attachments by default, 8 MB each and 16 MB total.

The same checks as `php please secretary:doctor` are shown under **System status** in the Control Panel. The command remains available for CI and deployment checks but is not an installation step.

Use `php please secretary:doctor --json` in deployment checks. `php please secretary:dry-run "…" --user=editor@example.com --entry=home --json` runs the real model and authorization/tool inspection while preventing entry writes and permanently blocking the resulting audit records from publication.

Conversation and audit records are self-hosted in Secretary's private store. Use `php please secretary:prune --days=90` for an interactive retention cleanup, or add `--force` only in a deliberate scheduled task. See [PRIVACY.md](PRIVACY.md).

## Control Panel

Editors with access to Secretary get a floating chat button throughout the Control Panel. It opens a native side panel over the page they are already editing, so they can start or continue a conversation without leaving their work. While Secretary is processing a request, the panel refreshes that conversation automatically and shows the reply and prepared change as soon as they are ready.

When the editor invokes the panel from a field, Secretary validates and stores that field as conversation context. Bard and Replicator set context is kept when the Control Panel exposes it. Type `@` followed by an entry title to insert an exact, permission-filtered entry reference.

The full conversation workspace remains available under **Content → Secretary** for detailed before/after review, live-versus-draft preview, editorial guidance, diagnostics, and publication. Every changed field—and each changed Bard/Replicator module—can be kept or rejected independently. Rejected values are removed from the native working copy with the same optimistic-lock safeguards as the original draft. Assign these addon permissions to non-super users as needed:

- `use secretary`
- `publish with secretary`
- `configure secretary` (connects or reconnects Postmark)

Every mutation is shown as a change card. Existing published entries are saved to a working copy. Terms, globals, and navigation are instead staged in Secretary's database, because those Statamic resources do not provide working-copy revisions. In both cases, live content does not change until the user selects **Publish** or sends a narrowly recognized immediate publication command.

Site-specific audience, voice, terminology, and “avoid” rules can be maintained in the Control Panel or in `config/secretary.php`. Configuration is the default; Control Panel values override it per site.

## Email setup

The recommended **Easy setup** connects the hosted Secretary Relay from the first-run screen. It requires no mailbox, Postmark account, API key, webhook, or environment configuration. Enter the site's public HTTPS URL first; Secretary uses that domain to preview the real site-specific address, such as `example.com@statamic.no`. It prefers the selected Statamic site's URL, falls back to a public production `APP_URL`, and never guesses the customer domain from the administrator's email address. An existing Statamic user then verifies their prefilled account email with a one-time code.

The **Advanced setup** connects a Postmark server controlled by the site owner. Paste the Server API Token in the Control Panel; it is stored encrypted with the site's `APP_KEY`. Environment configuration remains available and takes precedence:

```dotenv
POSTMARK_API_KEY=
```

Use the **Server API Token** from the Postmark server. Then open **Content → Secretary** as a super user or a user with `configure secretary`, enter the public email address people will write to, and connect. Secretary retrieves Postmark's generated inbound address, registers a Basic Auth-protected webhook, configures an isolated outbound mailer, and keeps onboarding open on one final step. Add the displayed `public mailbox → Postmark inbound address` forwarding rule with the mailbox provider, then confirm it in Secretary. The rule remains visible in settings afterward.

The site's normal Laravel mailer is not changed, and the required Postmark transport packages are included by this addon. A production `APP_URL` is used automatically when it is public HTTPS. Local `.test` sites instead need a temporary public HTTPS URL, such as Herd Share, during setup.

Inbound senders must match an existing Statamic user with `use secretary`. `SECRETARY_ALLOWED_SENDERS` remains available as an optional additional allowlist; leaving it empty relies on Statamic users and permissions. Postmark must report author-domain `DKIM_VALID_AU` by default, while a generic `SPF_PASS` is insufficient. Email publishing remains disabled unless `SECRETARY_EMAIL_ALLOW_PUBLISHING=true`, and also requires `publish with secretary` plus an authenticated sender.

When an instruction needs an image, Secretary first searches the site's allowed Statamic assets. If no suitable image exists, it asks the sender to reply with a JPEG, PNG, or WebP attachment. The image is validated from its actual bytes, imported append-only through Statamic, recorded by asset ID in the conversation audit metadata, and visually supplied to the configured OpenAI project. Secretary does not fetch images from the web.

Replies use the generated Postmark address with plus-addressing (`hash+<conversation-id>@inbound.postmarkapp.com`) so Postmark returns the validated conversation ID as `MailboxHash`. Webhook credentials are derived from the site's `APP_KEY`; reconnect Postmark after rotating that key.

One public mailbox must not be connected directly to multiple sites. The addon includes a signed receiver, reply client, and email-verified Control Panel pairing flow for the optional shared `secretary@statamic.no` service. A customer needs no mailbox or Postmark account for this mode: an existing Statamic user with `use secretary` requests a 15-minute code at their own email address, pastes it into Secretary, and receives isolated encrypted relay credentials. Requests are bound to one installation ID, route token, independent 256-bit secret, short timestamp window, exact body digest, and single-use nonce; replies remain bound to the same route and conversation. This repository also contains the separate hosted-relay service under `relay/`, deliberately excluded from the Composer addon archive. The hosted HTTPS/Postmark transport, unknown-sender rejection, plain forwarding, and exact plus-tag preservation were verified live on 2026-07-27. The remaining public-release gate is the paired two-installation X/Y/random-sender exercise documented in [docs/shared-address-relay.md](docs/shared-address-relay.md).

Control Panel and inbound-email work is sent to the `secretary` queue. Laravel's default `sync` connection processes each job after the HTTP response, so a normal Secretary installation requires no queue worker. Larger sites may opt into a persistent queue for durability and throughput. When the site already uses such a driver, its worker should include both queues:

```shell
php artisan queue:work --queue=secretary,default
```

Persistent-queue users should use a shared cache for per-conversation locks and keep the queue connection's `retry_after` and worker timeout longer than `SECRETARY_JOB_TIMEOUT` (1200 seconds by default). The built-in `sync` path needs none of this setup. Jobs have a fixed retry deadline of at least 24 hours so newer messages can wait safely behind slower work without exhausting attempts, while real processing exceptions remain bounded. Duplicate provider deliveries may enqueue duplicate jobs intentionally; reply uniqueness, the conversation lock, and provider message IDs make those jobs idempotent.

## Security model

- No shell, PHP execution, generic file-write, generic HTTP, delete, asset replacement, config, or schema tools.
- Strict OpenAI function schemas; arbitrary field data is passed as JSON text and decoded server-side; output tokens, tool rounds, resource payload size, and request rates are bounded.
- Per-resource allowlists, real blueprint handles, editable-field checks, Statamic validation, and native content policies.
- Canonical-path and symlink checks before any content write.
- Working-copy drafts for live entries; database-staged drafts for terms, globals, and navigation; explicit application-side publication for both.
- Separate draft and live-baseline fingerprints prevent a draft from overwriting or publishing over newer human edits, including direct live-file changes while a working copy exists.
- Postmark Basic Auth or installation-scoped relay HMAC, native Statamic-user authorization, optional sender allowlist, author-domain DKIM checks, spam threshold, and unique provider message IDs.
- All returned content is treated as untrusted data to reduce prompt-injection risk.
- Asset access is container-allowlisted, permission-filtered, image-only, size-bounded, and append-only. Visual content is explicitly treated as untrusted.

See [docs/architecture.md](docs/architecture.md) for the detailed trust boundaries and release limits.

## Developer API

Secretary supports config-as-code editorial guidance, read-only application tools, opt-in execution traces, Laravel events, and signed outgoing webhooks. Mutation extensions are intentionally not supported: content writes continue through the built-in audited change-set workflow.

See [docs/developer-api.md](docs/developer-api.md) for the extension contract, event payloads, webhook verification, developer mode, and CI dry-run examples.

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

The stable `0.1.0` package is available through [Packagist](https://packagist.org/packages/axelferdinand/statamic-secretary) and installs through Composer. Package metadata, compiled assets, support/privacy/security policies, diagnostics, CI, Marketplace copy, release checks, and 1200×800 Marketplace media are in place. Marketplace seller approval and publication remain separate from the Composer release.
