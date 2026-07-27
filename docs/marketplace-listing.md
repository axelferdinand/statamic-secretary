# Marketplace listing draft

## Product name

**Statamic Secretary**

Recommended alternatives if the final brand should be less literal:

- **Content Steward** — emphasizes guarded responsibility rather than autonomous control.
- **Statamic Concierge** — friendly and service-oriented, but less explicit about editing.
- **Draftmate** — concise and clearly draft-first, though less tied to Statamic.

`Statamic Secretary` remains the clearest working name because the email address, CP label, and human-assistant metaphor all reinforce each other.

## Short description

Ask for Statamic content changes in plain language from the Control Panel or by email. Secretary inspects your real blueprints, prepares guarded drafts, and publishes only after explicit approval.

## Overview

Statamic Secretary is a content-only AI assistant for live Statamic sites. Editors can write the same instruction they would send to a colleague — “update the opening hours”, “create a page beneath Services”, or “change the footer phone number” — and receive a reviewable draft.

Secretary never receives shell access or a generic file-writing tool. It can only use narrowly typed Statamic operations. Every content path is verified against the configured `content/` root, every field is checked against the real blueprint, and every action runs with the requesting editor's native Statamic permissions.

## Key features

- Global Control Panel chat panel with persistent multi-turn conversations and automatic in-place updates.
- One-screen Postmark setup with threaded inbound email and an isolated outbound mailer.
- Configurable GPT-5.5 Responses API integration.
- Entries/pages, taxonomy terms, globals, and navigation trees.
- Native Statamic revisions for published entries.
- Database-staged drafts for content types without revisions.
- Explicit publication from the CP or an authenticated email command.
- Per-resource allowlists, native permissions, optimistic locking, and full audit records.
- Author-domain DKIM checks, spam threshold, webhook Basic Auth, idempotency, and queue retries.
- No code, template, blueprint, config, shell, generic HTTP, asset, delete, or arbitrary filesystem tools.

## Requirements

- Statamic 6 Pro for safe working-copy revisions of published entries.
- PHP 8.3 or newer.
- A database supported by Laravel.
- An OpenAI API project with access to the configured model.
- A persistent Laravel queue for production email processing.
- Optional Postmark server for email conversations.

Email setup requires only the Postmark Server API Token in the environment. The addon includes the Postmark transport, discovers the server's inbound address, registers its secured webhook, and leaves the site's normal mailer untouched. The operator only adds the forwarding address shown in the Control Panel.

## Installation command

```shell
composer require axelferdinand/statamic-secretary
php artisan migrate --force
php please secretary:doctor
```

## Marketplace assets still needed

- [x] Square addon icon: `docs/assets/statamic-secretary-icon.png` (1024×1024) and `docs/assets/statamic-secretary-icon-512.png` (512×512).
- CP desktop conversation screenshot.
- CP mobile conversation screenshot.
- Before/after change-card screenshot.
- Postmark email-thread screenshot with private information removed.
- Optional short demo video.

## Release decision still needed

The repository currently uses the MIT license. Before creating the Marketplace product, decide whether the first release remains free/open source or switches to a commercial Statamic edition and license. Do not advertise paid licensing while the distributed package remains MIT.
