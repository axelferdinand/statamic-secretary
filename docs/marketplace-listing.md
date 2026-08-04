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
- Existing Statamic image search plus safe JPEG/PNG/WebP email attachments, imported append-only with native asset permissions.
- Optional email-verified connection to the hosted `secretary@statamic.no` address, without a customer Postmark account.
- No code, template, blueprint, config, shell, generic HTTP, asset replacement/deletion, or arbitrary filesystem tools.

## Requirements

- Statamic 6 Pro for safe working-copy revisions of published entries.
- PHP 8.3 or newer.
- A database supported by Laravel.
- An OpenAI API project with access to the configured model.
- A persistent Laravel queue for production email processing.
- Optional Postmark server for email conversations.

For a site-controlled mailbox, email setup requires only a Postmark Server API Token, which may be entered in the Control Panel or supplied through the environment. The addon includes the Postmark transport, discovers the server's inbound address, registers its secured webhook, and leaves the site's normal mailer untouched. The optional hosted address instead pairs an existing permitted Statamic user by a short-lived email code; the customer does not create a mailbox or Postmark server.

## Installation command

```shell
composer require axelferdinand/statamic-secretary
```

That is the complete standard installation. Open **Content → Secretary** to add the OpenAI key and choose **Easy setup** (Secretary Relay) or **Advanced setup** (your own Postmark server). Secretary runs its package migrations automatically through Statamic's addon installation hook. `secretary:doctor` remains available as an optional deployment diagnostic.

## Marketplace assets still needed

- [x] Square addon icon: `docs/assets/statamic-secretary-icon.png` (1024×1024) and `docs/assets/statamic-secretary-icon-512.png` (512×512).
- CP desktop conversation screenshot.
- CP mobile conversation screenshot.
- Before/after change-card screenshot.
- Postmark email-thread screenshot with private information removed.
- Optional short demo video.

## Commercial license

Statamic Secretary is paid software at **USD 49 per production site**. It may be installed and evaluated without a license during local development and testing, but production use requires a valid license purchased through the Statamic Marketplace and attached to the corresponding Statamic Site.

The optional hosted `secretary@statamic.no` inbox is a separate **USD 49/year** service. OpenAI usage, customer-managed Postmark usage, and the Statamic license are not included.
