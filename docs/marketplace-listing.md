# Marketplace listing — ready-to-paste copy

Use this document as the source of truth when creating the Statamic Marketplace product.

## Product details

- **Name:** Statamic Secretary
- **Type:** Addon
- **Price:** USD 49 per production site
- **Package:** `axelferdinand/statamic-secretary`
- **Compatibility:** Statamic 6, PHP 8.3+
- **Website:** https://secretary.statamic.no
- **Support:** https://github.com/axelferdinand/statamic-secretary/issues
- **Security:** https://github.com/axelferdinand/statamic-secretary/security/policy

## Short description

Ask for Statamic content changes by email or in the Control Panel. Secretary follows your blueprints, prepares safe drafts, and leaves publishing to you.

## Overview

Statamic Secretary gives editors a plain-language way to work with real Statamic content. Send the instruction you would normally send to a colleague — “update the opening hours”, “create a page beneath Services”, or “make this introduction less corporate” — and Secretary finds the right content, follows its blueprint, and prepares a reviewable draft.

Use the contextual chat panel anywhere in the Control Panel, or send an email and carry the same conversation back into Statamic. Secretary acknowledges email requests immediately, replies in the sender's language, and links directly to the resulting draft. Nothing is published until an authorized human explicitly approves it.

Secretary is deliberately content-only. The model receives no shell, generic filesystem, template, blueprint, configuration, or arbitrary web access. Every operation goes through typed Statamic tools, native permissions, blueprint validation, content-root checks, optimistic locking, and an audit trail.

## Highlights

- Contextual Control Panel chat that follows the page or entry you are editing.
- Multi-turn email conversations with immediate receipt acknowledgements and language-matched replies.
- Safe working-copy drafts for published entries using Statamic revisions.
- New unpublished entries, taxonomy terms, globals, and navigation changes.
- Real blueprint, Bard/Replicator, collection, hierarchy, multisite, and permission awareness.
- Existing Statamic asset search and safe JPEG, PNG, or WebP email attachments.
- Before/after review, field-level keep/reject controls, preview, and explicit publication.
- Automatic conversation continuity when moving between email, drafts, and the Control Panel.
- Guardrails against stale drafts, duplicate webhooks, sender spoofing, unsafe files, and content-boundary escapes.
- Private site-local SQLite storage created automatically; no customer database setup or install command.

## Email options

**Secretary Relay** is the quickest setup. Verify an existing permitted Statamic user and receive a site-specific address such as `example.com@statamic.no`. No mailbox, Postmark account, webhook, or queue worker is required. The hosted relay is a separate optional service at **USD 49/year** after its beta period.

**Your own Postmark server** keeps email delivery under the site owner's account. Paste the Postmark Server API Token in Secretary, then create the one forwarding rule shown by onboarding. Secretary discovers and secures the remaining Postmark configuration automatically.

Control Panel chat works without either email option.

## Requirements

- Statamic 6 Pro. Revisions are required to keep changes to published entries safely separated from live content.
- PHP 8.3 or newer.
- A writable Laravel `storage` directory.
- An OpenAI API project and API key for usage billed by OpenAI.
- Outbound HTTPS access to OpenAI and, when selected, Secretary Relay or Postmark.

Secretary creates and migrates its own private SQLite store. The standard installation does not require an external database, a migration command, Stache maintenance, `secretary:doctor`, or a queue worker. Sites that already operate persistent Laravel queues may opt into one for additional durability and throughput.

## Installation

```shell
composer require axelferdinand/statamic-secretary
```

Then open **Content → Secretary**. The guided setup connects OpenAI, turns on safe drafts, and offers Relay, private Postmark, or Control Panel-only use.

## Pricing and included services

The Marketplace license costs **USD 49 for each production site**. Local development, testing, and staging use are permitted without another license. The Marketplace license does not include OpenAI usage, a Statamic license, customer-managed Postmark charges, or the optional hosted Relay subscription.

## Support and data handling

Secretary is self-hosted. Conversation and audit records stay in a private store on the Statamic installation. Requests and the minimum relevant content are sent to the customer's configured OpenAI project. Email customers additionally use Postmark directly or through the optional hosted relay.

- Documentation: https://github.com/axelferdinand/statamic-secretary#readme
- Privacy: https://github.com/axelferdinand/statamic-secretary/blob/main/PRIVACY.md
- Support policy: https://github.com/axelferdinand/statamic-secretary/blob/main/SUPPORT.md
- Security policy: https://github.com/axelferdinand/statamic-secretary/blob/main/SECURITY.md
- Changelog: https://github.com/axelferdinand/statamic-secretary/blob/main/CHANGELOG.md
- Commercial license: https://github.com/axelferdinand/statamic-secretary/blob/main/LICENSE.md

## Marketplace media

Ready:

- `docs/assets/statamic-secretary-icon.png` — 1024×1024.
- `docs/assets/statamic-secretary-icon-512.png` — 512×512.

Capture from the final stable build:

1. **Email to safe draft** — a redacted email instruction beside the resulting Statamic draft.
2. **Contextual chat** — the floating panel open on a real entry with a concise conversation.
3. **Review before publishing** — a change card with before/after or field-level review.
4. **Editor workspace** — conversation list and current result on desktop.
5. **Mobile Control Panel** — the same conversation at a narrow viewport.
6. Optional 30–45 second video: email request → acknowledgement → draft → refinement in CP → publish.

Use only demo content and demo identities. Do not show API keys, pairing codes, private email addresses, provider IDs, or production customer content.
