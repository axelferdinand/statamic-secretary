# Changelog

All notable changes will be documented in this file. Releases follow Semantic Versioning.

## Unreleased

## 0.1.3 - 2026-08-07

- Update `league/commonmark` to 2.9.0 to include the upstream denial-of-service security fixes published on 2026-08-06.
- Add Stripe Sandbox billing for the hosted Secretary Relay at USD 49 per year, including signed and idempotent webhook handling, customer Checkout activation, subscription-state enforcement, and complimentary demo installations.
- Add operator-safe Relay billing commands, migration and deployment checks, and a complete activation guide that keeps existing installations available until billing is deliberately enabled.
- Add an in-Control-Panel live OpenAI check that verifies API-key access, the selected model, and available credits without exposing provider secrets.
- Replace opaque OpenAI and background-job failures with safe, actionable editor messages while preserving detailed diagnostics for administrators.
- Keep the contextual Secretary panel open through normal desktop navigation, label its close control, and make Escape reliably close the panel and restore keyboard focus on desktop and mobile.

## 0.1.2 - 2026-08-06

- Install the standalone hosted relay dependencies in every PHP CI job so its isolated autoload boundary is verified consistently across the supported PHP and Laravel matrix.

## 0.1.1 - 2026-08-06

- Rebuild the Marketplace and GitHub README around the product value, a three-step installation, the email-first workflow, new-page and structured-module creation, and concise links to the detailed technical documentation.

## 0.1.0 - 2026-08-06

- First stable release of Secretary for Statamic.
- Link every completed email change directly to its native Statamic editor, including entries, taxonomy terms, globals, and navigation; multi-resource replies link every affected resource separately.
- Keep setup, pairing, and one-time-code email in English by default while matching the language of each editor instruction in acknowledgements and completed-work replies.
- Add Marketplace-ready documentation, product icons, a 1200×800 concept cover, and genuine Control Panel screenshots.
- Ship the complete guarded email and Control Panel workflow proven through the `0.1.0` beta series: blueprint-aware drafts, new pages and structured modules, explicit publication, contextual chat, attachments, hosted relay, private storage, and native Statamic permissions.

## 0.1.0-beta.6 - 2026-08-06

- Restore hosted relay acknowledgements and replies by making the relay language layer fully standalone from the Composer addon.
- Recover safely when the model attempts an entry or localized-content update before reading the exact resource, while preserving fingerprint and blueprint guards.
- Load only the visible preview frame first, show explicit loading and retry states, and keep stale preview requests from updating a closed or changed context.
- Add a clear “Publish this” action to both the Secretary draft and side-by-side preview views on desktop and mobile.
- Avoid rewriting an unchanged Statamic working copy when an editor simply marks a field as kept during review.

## 0.1.0-beta.5 - 2026-08-06

- Rename the public addon from “Statamic Secretary” to “Secretary”, while keeping the Composer package and repository at `axelferdinand/statamic-secretary` and using “Secretary for Statamic” where explanatory context is useful.

## 0.1.0-beta.4 - 2026-08-06

- Reuse the original relay installation and domain address when the same public site and exact sender set complete onboarding again, while retaining collision-safe aliases for genuinely different installations.
- Align the onboarding card with the Control Panel page heading and give success and error messages a dedicated, spaced notice area.
- Send an immediate, lightly human acknowledgement when an inbound instruction is accepted, without duplicating acknowledgements on provider retries.
- Keep system email in English while matching the sender's language in acknowledgements, results, publication messages, attachment labels, and relay replies.
- Make suggested prompts more useful and playful, and submit them directly instead of copying them into the composer.
- Replace implementation-oriented recovery copy with a clear “Edit and try again” action.
- Give the relay pairing action a clear primary state only after a complete one-time code is present.
- Increase the default inspection budget and return a safe, useful response or focused clarification when the tool budget is exhausted.

## 0.1.0-beta.3 - 2026-08-05

- Added a dedicated, automatically created SQLite store under `storage/statamic-secretary` so Secretary no longer depends on the host site's database credentials or migrations.
- Added safe first-request database recovery and a friendly Control Panel error when the private store cannot be prepared.
- Refined onboarding around the public site URL, the generated relay address, email-first activation, revision readiness, and in-panel system checks.
- Improved the contextual Control Panel conversation experience, queued follow-ups, change review, publication refresh, and responsive preview controls.
- Improved email continuity and attachment handling, including subject-aware context and direct links to imported Statamic assets.

## 0.1.0-beta.2 - 2026-08-04

- Added a focused first-run wizard that connects OpenAI, then offers recommended Secretary Relay, advanced private Postmark, or Control Panel chat only.
- Added encrypted Control Panel storage for OpenAI and Postmark API keys while retaining environment configuration as the higher-priority option.
- Enabled relay pairing by default so new installations can choose the easy setup without developer configuration.
- Reduced the standard installation to one Composer command by running package-scoped migrations through Statamic's official addon installation hook.
- Clarified every email field and removed Postmark warnings and hidden requirements from the relay setup path.
- Added installation, encryption, onboarding, and Postmark regression coverage and verified a clean Composer install against an empty database.

## 0.1.0-beta.1 - 2026-08-04

- Changed the addon from MIT to a commercial Statamic Marketplace license: free to evaluate during development and USD 49 per licensed production site.
- Added public source, issue, security, and support metadata to the Composer package.
- Added guarded GPT-5.5 Responses API orchestration for entries, terms, globals, and navigation.
- Added permission-filtered existing-image search and visual inspection, plus authenticated JPEG/PNG/WebP email attachments imported append-only through Statamic assets.
- Added a signed relay payload version 2 for checksummed image attachments while retaining version 1 for ordinary email compatibility.
- Verified the hosted relay image path live: signed forwarding, one Statamic asset, multimodal model recognition, no unintended change set, outbound reply, and duplicate-provider idempotency.
- Added CP chat, multi-turn Postmark email through an isolated mailer, and explicit publication.
- Added one-screen Postmark onboarding that discovers the inbound address and registers a secured webhook from the Server API Token.
- Added the disabled addon-side protocol for a future shared `secretary@statamic.no` relay: installation/route/conversation-bound HMAC delivery, replay protection, strict normalized payloads, and signed idempotent replies without exposing a shared Postmark token.
- Added a Composer-excluded hosted relay core with exact single-site routing, ambiguity rejection, compact RFC-valid aliases, Postmark inbound/outbound adapters, signed site delivery, and atomic idempotency interfaces.
- Bound hosted-relay provider and reply idempotency claims to exact SHA-256 request fingerprints so a reused identity with changed content is rejected.
- Added a durable encrypted SQLite relay store with atomic cross-process claims, expiring crash leases, replay nonces, sender memberships, conversation bindings, and retention pruning.
- Added a DNS-pinned cURL transport that rejects private/reserved destinations, redirects, header smuggling, oversized responses, and non-TLS endpoints.
- Locked shared Postmark delivery to the official `https://api.postmarkapp.com/email` endpoint so the server token cannot be redirected by configuration.
- Added a standalone zero-dependency hosted-relay Composer project with a readiness endpoint, authenticated Postmark webhook, signed reply endpoint, migration/provision/prune commands, safe error responses, and retry-aware HTTP status handling.
- Added retry-safe, one-time relay pairing codes and a control-panel onboarding flow that stores each site's relay credentials encrypted while never persisting or exposing the pairing code.
- Added retry-safe relay operator controls for redacted status, immediate enable/disable, and exact sender membership changes without exposing installation signing secrets.
- Added encrypted two-phase relay signing-secret rotation with retry-safe prepare/install/promote steps, dual-secret grace periods, hidden site input, and stacked-rotation rejection.
- Added retry-safe relay route rotation with atomic pending/current/retired aliases, bounded handover, retired-alias new-thread rejection, and permanent exact bindings for existing email threads.
- Added durable cross-worker relay endpoint rate limits with hashed client identities, standard `429` retry responses, retention pruning, and structured security events that omit exception messages and request identities.
- Added an idempotent ambiguous-sender selection notice that forwards the original instruction to no site and lists only the sender's exact site-bound aliases for resubmission.
- Added a package-managed, isolated Postmark mailer so installations need only `OPENAI_API_KEY` and `POSTMARK_API_KEY` and do not replace the site's default mailer.
- Added native revisions/unpublished entry drafts and database-staged non-revision content drafts.
- Added canonical content-boundary checks, native authorization, optimistic conflict detection, sender authentication, spam limits, audit records, diagnostics, and retention pruning.
- Added asynchronous CP processing, unified CP/email conversation history, per-conversation queue ordering, unique replies, and retry recovery across model, publication, and mail failures.
- Verified a live GPT-5.5 CP continuation that recalled the prior draft without creating another content change.
- Added a clean Composer distribution archive, Laravel 12/13 CI coverage, compiled-asset verification, and Marketplace icon assets.
- Verified a clean non-symlink Composer artifact installation with Statamic addon discovery, route registration, and asset publication.
- Added archive CI guards that exclude the hosted relay service while requiring the addon's signed relay receiver and reply client.
- Fixed zero-argument function schemas so strict OpenAI tools serialize `properties` as `{}` instead of the invalid `[]`.
- Fixed hosted email replies losing their conversation when a forwarding server strips Postmark's top-level `MailboxHash`; the relay now recovers only an exact, validated per-recipient hash from `ToFull` and rejects mismatched or ambiguous recipient data.
- Included the normalized email subject in stored and stateless agent context so a subject such as “Forsiden” can identify the target page while the message body describes the requested field change.
- Fixed entry updates containing nested Bard fields with `save_html` enabled by validating their editor representation and restoring the exact storage representation of every unchanged module and grid row.
- Contained failures from sync after-response jobs so local CP and Postmark requests do not attempt to modify already-sent HTTP headers, while persistent queues still retry normally.
