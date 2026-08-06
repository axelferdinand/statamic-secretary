# Secretary documentation

Secretary for Statamic turns plain-language requests into structured, reviewable Statamic drafts. Editors can update existing content, create new pages, reuse the Bard and Replicator modules already defined by the site, continue the same conversation by email or in the Control Panel, and publish only after an explicit approval.

## Requirements

- Statamic 6 Pro.
- PHP 8.3 or newer.
- A writable Laravel `storage` directory.
- An OpenAI API project and API key.
- Outbound HTTPS access to OpenAI and, when enabled, Secretary Relay or Postmark.

Statamic revisions are required for safe drafts of existing published entries. Secretary can enable revisions during onboarding without changing or publishing any content.

## Install

Install Secretary from the project root:

```shell
composer require axelferdinand/statamic-secretary
```

Then open **Content → Secretary** in the Statamic Control Panel. Secretary creates and migrates its own private SQLite store automatically. The standard installation does not require an external database, an Artisan migration, a Stache command, or a queue worker.

## First-time setup

Secretary guides an administrator through three short steps:

1. **Connect OpenAI.** Paste an OpenAI API key. It is encrypted with the site's `APP_KEY` and never returned to the browser. `OPENAI_API_KEY` may be used instead when the site manages secrets through environment configuration.
2. **Enable safe drafts.** Secretary enables Statamic revisions for current collections so proposed changes remain separate from published content.
3. **Choose how to work.** Use Control Panel chat only, connect the hosted Secretary Relay, or connect a private Postmark server.

After setup, editors with the `use secretary` permission can open Secretary from **Content → Secretary** or from the floating contextual panel throughout the Control Panel.

## What you can ask Secretary to do

Write as you would to a colleague. For example:

- “Make the homepage introduction shorter and friendlier.”
- “Create a new Services page beneath the current Services section.”
- “Build the new page with our existing hero, feature-grid, testimonial, FAQ, and call-to-action modules.”
- “Find the About page and suggest three stronger titles.”
- “Use a suitable image that already exists in the asset library.”
- “Translate this entry for the Norwegian site and keep the same module structure.”

Before writing, Secretary reads the site's real collections, blueprints, fields, hierarchy, localization, and permitted asset containers. New pages use existing blueprints and supported Bard or Replicator sets; Secretary cannot invent a field or module that is not part of the site's content model.

Secretary currently supports collection entries, structured page trees, taxonomy terms, globals, navigation trees, multisite content, existing Statamic assets, and authenticated JPEG, PNG, or WebP email attachments.

## Review and publish

Secretary prepares drafts; it does not silently publish them.

For an existing published entry, Secretary writes to a Statamic working copy. For a new entry, it creates an unpublished entry. The conversation shows what changed and provides direct actions to:

- open the native Statamic draft;
- compare published content with the Secretary draft;
- keep or reject individual fields and Bard/Replicator modules;
- refine the result in the same conversation; and
- publish when an authorized editor is satisfied.

Secretary rechecks the live content before publishing. If another editor has changed the entry since the draft was prepared, publication stops instead of overwriting the newer work.

## Contextual Control Panel chat

The floating Secretary panel follows the entry or page currently open in the Control Panel. Navigating to another entry switches context; returning restores that entry's conversation and current status. A conversation started by email can be continued from the linked Statamic draft without losing its history.

Use `@` in the composer to reference another entry explicitly. Secretary still applies the signed-in user's native Statamic permissions when searching, reading, drafting, and publishing.

## Email

### Secretary Relay

Relay is the quickest setup. Verify an existing permitted Statamic user and receive a site-specific address such as `example.com@statamic.no`. It requires no customer mailbox, Postmark account, webhook, or queue worker.

Send an instruction from the verified Statamic user's address. Secretary acknowledges the request, works in the background, replies in the language used by the sender, and links directly to the resulting draft. Reply to continue the same conversation.

The hosted Relay is a separate optional service and is not included in the Marketplace license.

### Private Postmark server

Advanced setup keeps email delivery in the site owner's Postmark account. Paste a Postmark Server API Token in Secretary and follow the one forwarding instruction shown by onboarding. Secretary discovers and secures the remaining Postmark configuration automatically without replacing the site's normal Laravel mailer.

## Images and assets

Secretary can search and inspect existing images in permitted Statamic asset containers. It does not fetch images from the public web. When no suitable image exists, an email sender can attach a JPEG, PNG, or WebP file; Secretary validates and imports it append-only through Statamic's asset API.

Secretary cannot delete, replace, rename, or edit asset files or metadata.

## Permissions

Super users have access automatically. For other Statamic users, assign the relevant addon permissions:

- `use secretary` — open Secretary, send requests, and review permitted content;
- `publish with secretary` — publish an approved Secretary change;
- `configure secretary` — manage OpenAI and email connections.

Native collection, entry, taxonomy, global, navigation, site, and asset permissions are always enforced in addition to Secretary's permissions. Email senders must resolve to an existing Statamic user with access to Secretary.

## Configuration

Most installations need no environment configuration beyond the normal Laravel setup. Teams that manage secrets and policy as code can publish `config/secretary.php` and use the documented `SECRETARY_*` environment variables.

Useful optional restrictions include:

```dotenv
SECRETARY_COLLECTIONS=pages,articles
SECRETARY_TAXONOMIES=topics,tags
SECRETARY_GLOBAL_SETS=company,footer
SECRETARY_NAVIGATIONS=main,footer
SECRETARY_ASSET_CONTAINERS=assets
SECRETARY_RETENTION_DAYS=90
```

An empty content-source allowlist permits every source of that type that the requesting user may access. All content writes remain confined to the configured Statamic `content` directory.

## System status and troubleshooting

Open **Content → Secretary → Settings and status** to run the same checks available to deployments through:

```shell
php please secretary:doctor --json
```

The Control Panel explains any required action for OpenAI, safe drafts, private storage, email, assets, or background processing. Secretary uses Laravel's normal synchronous queue by default, so a standard installation does not require a queue worker.

For a safe model and authorization check that prevents entry writes:

```shell
php please secretary:dry-run "Make the homepage introduction clearer" \
  --user=editor@example.com \
  --entry=home \
  --json
```

## Data and security

Secretary is self-hosted. Conversations and audit records stay in a private site-local store. Requests and the minimum relevant content are sent to the OpenAI project configured by the site owner. Email additionally uses the selected Postmark or Relay path.

The model receives no shell, generic filesystem, arbitrary HTTP, templates, blueprints, configuration, or code-writing tools. Every content operation passes through typed Statamic tools, native permissions, blueprint validation, content-boundary checks, optimistic locking, and an audit trail.

See [PRIVACY.md](PRIVACY.md), [SECURITY.md](SECURITY.md), and [LICENSE.md](LICENSE.md) for the full policies.

## Updates and support

Update Secretary with Composer:

```shell
composer update axelferdinand/statamic-secretary --with-all-dependencies
```

- Product site: <https://secretary.statamic.no>
- Changelog: [CHANGELOG.md](CHANGELOG.md)
- Support: <https://github.com/axelferdinand/statamic-secretary/issues>
- Security reports: <https://github.com/axelferdinand/statamic-secretary/security/policy>

