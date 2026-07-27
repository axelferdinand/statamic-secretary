# Changelog

All notable changes will be documented in this file. Releases follow Semantic Versioning.

## Unreleased

- Added public source, issue, security, and support metadata to the Composer package.
- Added guarded GPT-5.5 Responses API orchestration for entries, terms, globals, and navigation.
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
- Contained failures from sync after-response jobs so local CP and Postmark requests do not attempt to modify already-sent HTTP headers, while persistent queues still retry normally.
