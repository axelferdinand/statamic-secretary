# Release checklist

## Product proof

- [x] Verify the full suite against Laravel 12 and Laravel 13 with Statamic 6.
- [x] Run the addon migrations in the local Statamic 6 sandbox.
- [ ] Run `php please secretary:doctor` with production-like configuration.
- [ ] Complete desktop, narrow, mobile, light, and dark CP browser QA.
- [x] Complete a real GPT-5.5 inspect → new unpublished structured-page draft flow with a rotated key.
- [x] Complete a real GPT-5.5 multi-turn CP follow-up without creating an unintended change set.
- [ ] Publish the live-test draft through Secretary and verify its live URI.
- [ ] Complete a real Postmark new thread → reply → publish flow.
- [ ] Verify failed mail transport retry and queue-worker restart behavior.
- [ ] Verify a non-super editor's collection/site restrictions in a real CP.
- [ ] Verify a direct human edit blocks a stale Secretary publication.
- [x] Implement and adversarially test the addon-side shared-relay receiver and signed reply client.
- [x] Implement and adversarially test the framework-independent hosted relay routing/security/Postmark core.
- [x] Add encrypted durable relay persistence with atomic cross-worker claims, crash leases, nonces, and retention.
- [x] Add a DNS-pinned public-HTTPS transport and lock shared Postmark requests to the official API endpoint.
- [x] Add the standalone authenticated HTTP application plus migration, manual provisioning, health, and retention commands.
- [x] Add an idempotent ambiguous-sender selection notice that forwards the original instruction to neither site.
- [x] Add retry-safe one-time pairing plus the encrypted control-panel setup flow.
- [x] Add redacted operator status, enable/disable, and exact sender-membership controls.
- [x] Add encrypted, retry-safe two-phase signing-secret rotation with bounded dual-secret grace periods.
- [x] Add retry-safe route rotation that rejects new retired-route threads while preserving exact existing conversation bindings.
- [x] Add cross-worker endpoint rate limits with hashed identities, retry headers, pruning, and secret-free security records.
- [x] Add email-verified customer-facing code issuance without browser-visible codes or secrets.
- [x] Deploy `secretary@statamic.no` and verify HTTPS health, authenticated Postmark ingress, plain forwarding, and preserved plus-address tags.
- [ ] Complete the two-public-installation X/Y/random-sender isolation proof.

## Package

- [x] Build and inspect a clean Composer archive containing runtime files and compiled assets only.
- [x] Install the clean archive as a non-symlink Composer artifact in a separate Statamic 6 app and verify discovery, routes, and published assets.
- [x] Use a proprietary commercial license for a USD 49 per-production-site Statamic Marketplace addon, with development/testing use permitted.
- [x] Set the public repository and support URLs in `composer.json`.
- [ ] Run the GitHub Actions PHP matrix and asset reproducibility job.
- [ ] Send a real JPEG/PNG/WebP attachment through the hosted address, verify one append-only Statamic asset, visual model context, draft use, retry idempotency, and no relay-body persistence/logging.
- [ ] Tag a semantic `v0.1.0` prerelease only after live proof.
- [ ] Publish the package on Packagist.
- [ ] Confirm `composer require axelferdinand/statamic-secretary` in a fresh site.

## Marketplace

- [x] Prepare square 1024×1024 and 512×512 addon icons.
- [ ] Create or confirm the Statamic seller account.
- [ ] Create the draft Marketplace product and connect the Packagist package.
- [ ] Add product copy from `docs/marketplace-listing.md`.
- [ ] Add icon, screenshots, compatibility, privacy, support, and security links.
- [ ] Test the Marketplace installation command before publishing the listing.
