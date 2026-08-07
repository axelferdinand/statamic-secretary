# Secretary

<img src="docs/assets/statamic-secretary-icon-v2-512.png" alt="Secretary icon" width="104">

**Email your site. Get a safe Statamic draft back.**

Secretary for Statamic turns plain-language instructions into structured, reviewable content. Editors can update existing content, create complete new pages, and use the Bard and Replicator modules already built into the site — by email or from a contextual chat panel in the Control Panel.

Secretary reads the site's real collections, blueprints, fields, hierarchy, localizations, and permitted assets before it writes. It prepares a draft, shows what changed, and leaves publication to you.

## Install and start in three steps

### 1. Install Secretary

From the root of a Statamic 6 site, run:

```shell
composer require axelferdinand/statamic-secretary
```

That is the complete standard installation. Secretary registers its Control Panel assets and creates its own private store automatically. You do **not** need to run migrations, configure a database, refresh the Stache, or set up a queue worker.

### 2. Complete the guided setup

Open **Content → Secretary** in the Statamic Control Panel. Onboarding guides an administrator through:

1. connecting an OpenAI API key;
2. enabling safe Statamic drafts; and
3. choosing Control Panel chat, the easy hosted email relay, or a private Postmark server.

The OpenAI key is encrypted with the site's `APP_KEY`. Safe drafts use Statamic revisions so live entry content stays unchanged until an authorized editor publishes it.

### 3. Ask for a change

Write as you would to a colleague:

> Make the homepage introduction shorter and friendlier. Then create a Services page using our existing hero, feature grid, testimonial, FAQ, and call-to-action modules.

Secretary acknowledges the request, checks the site's content model, and prepares the work. When it is ready, you get a direct link to the actual Statamic draft — not a mystery dashboard three corridors away.

Review the result, continue the conversation, keep or reject individual changes, and publish when you are happy.

## More than a text rewriter

Secretary can work with the content model your developers have already built:

- update published entries as safe working copies;
- create new, unpublished entries in structured collections;
- build pages with existing Bard and Replicator sets;
- update taxonomy terms, localized globals, and navigation trees;
- search and inspect permitted images in Statamic asset containers;
- import authenticated JPEG, PNG, and WebP email attachments;
- work across multisite content and localizations; and
- preserve the requesting user's native Statamic permissions throughout.

It cannot invent fields or page modules that do not exist. That constraint is a feature: Secretary works with your site instead of slowly turning it into soup.

## Email or Control Panel — one conversation

### Email-first editing

Send instructions from an authorized Statamic user's email address. Secretary replies immediately to confirm the request, works in the background, and emails again when the draft is ready. Reply to continue the same thread.

Completed-work emails link directly to the affected entry, taxonomy term, global set, or navigation in Statamic. If a request changes several resources, every resource gets its own editor link.

Secretary replies in the language used by the editor. Setup, verification, and other system email remains in English by default.

### Contextual Control Panel chat

The floating Secretary panel follows the content currently open in the Control Panel. Move to another entry and the context changes with you; return later and its conversation and status are restored.

A conversation started by email can be continued from the linked draft in the Control Panel. Editors can refine the result without copying context between tools or starting over.

## Choose your email setup

### Easy setup: Secretary Relay

Verify an existing Statamic user and receive a site-specific address such as `example.com@statamic.no`. No customer mailbox, Postmark account, inbound webhook, or queue worker is required.

The hosted Relay is an optional service and is separate from the Marketplace addon license.
It is free to try on the public demo. A live site activates Relay through Stripe
Checkout inside Secretary for **USD 49/year per site**. Control Panel chat and a
customer-managed Postmark server do not require a Relay subscription.

### Advanced setup: your Postmark server

Use a Postmark server controlled by the site owner. Paste its Server API Token in onboarding and follow the single mailbox-forwarding instruction Secretary displays. Secretary configures the isolated inbound and outbound path without replacing the site's normal Laravel mailer.

You can also use Secretary entirely from the Control Panel without connecting email.

## Draft first. You publish.

Secretary never needs shell access, arbitrary filesystem access, generic web access, or permission to edit templates, blueprints, or application code.

Every content operation passes through:

- the signed-in Statamic user's native permissions;
- real collection, blueprint, field, site, and asset rules;
- validation and strict content-boundary checks;
- working copies or separately staged drafts;
- optimistic locking that stops stale drafts overwriting newer human work; and
- an auditable change set with explicit publication.

Existing assets are read-only. Email attachments are imported append-only, and Secretary does not fetch unknown images from the public web.

## Requirements

- Statamic 6 Pro
- PHP 8.3 or newer
- Laravel 12 or 13
- An OpenAI API key
- A writable Laravel `storage` directory
- A public HTTPS URL when email is enabled

## Permissions

Super users have access automatically. For other users, assign the permissions they need:

- `use secretary` — request and review changes;
- `publish with secretary` — publish approved changes; and
- `configure secretary` — manage OpenAI and email connections.

Native Statamic permissions are always enforced in addition to these addon permissions. An email sender must resolve to an existing Statamic user who may use Secretary.

## Documentation and support

- [Full documentation](DOCUMENTATION.md)
- [Privacy](PRIVACY.md)
- [Security](SECURITY.md)
- [Changelog](CHANGELOG.md)
- [Developer API](docs/developer-api.md)
- [Product site](https://secretary.statamic.no)
- [Support and issues](https://github.com/axelferdinand/statamic-secretary/issues)
- [Security reports](https://github.com/axelferdinand/statamic-secretary/security/policy)

The same installation checks available through `php please secretary:doctor --json` are presented with plain-language actions under **Content → Secretary → Settings and status**. Most editors never need the command line after installation.

## License

Secretary is a commercial addon priced at **USD 49 per production site** through the Statamic Marketplace. It may be used without a license during local development and testing. The optional hosted Relay is billed separately at **USD 49/year per live site**. See [LICENSE.md](LICENSE.md).

Update an installed copy with:

```shell
composer update axelferdinand/statamic-secretary --with-all-dependencies
```
