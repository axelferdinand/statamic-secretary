# Release and Marketplace checklist

Last verified: 2026-08-05.

## Already in place

- [x] Public GitHub repository: `axelferdinand/statamic-secretary`.
- [x] Public Composer package on Packagist with three installable beta tags.
- [x] `statamic-addon` metadata, provider discovery, compiled CP assets, and automatic private-store installation.
- [x] Commercial license for **USD 49 per production site**; Statamic handles Marketplace license validation.
- [x] README, changelog, privacy, support, security, architecture, relay, and developer documentation.
- [x] 1024×1024 and 512×512 product icons.
- [x] Copy-ready Marketplace listing in `docs/marketplace-listing.md`.
- [x] PHP 8.3/8.4/8.5, Laravel 12/13, asset reproducibility, archive inspection, and clean-install CI.
- [x] Latest published `main` workflow completed successfully on 2026-08-05.
- [x] Current local source passes strict Composer validation, Pint, and 261 tests / 1,999 assertions.
- [x] Current Composer candidate is a clean 1.2 MB / 122-file runtime archive with private relay operations and development files excluded.
- [x] Current candidate installs as a non-symlink Composer artifact in a fresh Statamic 6.27 / Laravel 13.24 site, publishes CP assets, creates its private store, runs its own migrations, registers routes, and exposes `secretary:doctor` without manual setup.

## Final product gates

- [ ] Complete one clean public-site flow: install → onboarding → email acknowledgement → draft → CP refinement → publish → live URL.
- [ ] Pair two public sites and prove X reaches only X, Y reaches only Y, and an unknown sender reaches neither.
- [ ] Verify a normal non-super editor is limited by native Statamic permissions.
- [ ] Verify a direct human edit blocks publication of a stale Secretary draft.
- [ ] Complete final desktop/mobile, light/dark, keyboard/focus, loading, empty, error, draft, and published-state browser QA.
- [ ] Capture the five clean screenshots listed in `docs/marketplace-listing.md`.

## Stable package gate

- [ ] Commit the intended release source and generated `resources/dist` assets.
- [ ] Confirm the release CI matrix repeats the archive and clean-install checks successfully from the committed source.
- [ ] Repeat the human onboarding flow in that fresh site without terminal-only setup.
- [ ] Move the `Unreleased` changelog entries to `0.1.0` with the release date.
- [ ] Create and push stable tag `v0.1.0`; confirm Packagist indexes `0.1.0`.
- [ ] Verify the final public command: `composer require axelferdinand/statamic-secretary`.

## Owner-only Marketplace steps

- [ ] Open a Creator shop at https://statamic.com/creator/begin.
- [ ] Connect GitHub and Stripe Connect. Statamic currently pays 75% of each sale: **USD 36.75 from a USD 49 license**.
- [ ] Create the addon draft and connect `axelferdinand/statamic-secretary` from Packagist.
- [ ] Set price to **USD 49**, compatibility to Statamic 6 / PHP 8.3+, and paste `docs/marketplace-listing.md`.
- [ ] Upload the icon and final screenshots, preview the listing, and submit/publish it.
- [ ] Decide and approve checkout, cancellation, privacy, and support terms for the separate **USD 49/year** hosted Relay plan before charging for it.

## After publication

- [ ] Replace generic Marketplace CTAs on `secretary.statamic.no` with the direct product URL.
- [ ] Test purchase, site-license attachment, install, update, and support handoff with a non-owner account.
- [ ] Monitor the first installs, Postmark delivery, relay isolation, model failures, and support requests without logging prompts or customer content to analytics.
