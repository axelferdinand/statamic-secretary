# Release and Marketplace roadmap

This document preserves the remaining work required to turn Statamic Secretary from a working product into a production-proven Marketplace addon. It is a reminder list, not evidence that the release gates have passed.

## Product proof still required

- Run `php please secretary:doctor` with the same persistent queue, OpenAI, and mail configuration customers will use in production.
- Complete a real inspect → draft → publish flow and verify the resulting public URL.
- Complete a real Postmark conversation from a new email thread through follow-up, draft, explicit publication, and the final reply.
- Stop and restart the queue worker during a mail failure, then verify that retries remain idempotent.
- Verify that a normal non-super editor can access only the collections, sites, and actions granted by native Statamic permissions.
- Verify that a direct human edit prevents Secretary from publishing a stale proposal.
- Complete the remaining Control Panel QA: dark mode, keyboard and focus cycle, loading, empty, error, pending, and published states.
- Pair two public installations and prove tenant isolation:
  - Customer X can reach only site X.
  - Customer Y can reach only site Y.
  - An unknown sender reaches no site and receives no sensitive response.
  - An ambiguous sender reaches no site until the intended site is selected.
- Verify operational monitoring, backup, retention, key rotation, rate limits, and recovery for the hosted relay.
- Finish the hosted service privacy terms, data-processing description, support policy, and incident procedure.

The product must not be described as production-proven until these gates pass.

## Distribution cleanup

- Keep `/relay` and local deployment helpers excluded through `.gitattributes`, and verify both Composer-built and GitHub/Packagist-style archives in CI.
- Run the complete PHP and asset CI matrix and resolve every warning as well as every failure.
- Build and inspect a clean release archive.
- Install that archive as a non-symlink Composer artifact in a fresh Statamic 6 project.
- Confirm that no `.env` files, API keys, deployment helpers, databases, logs, test fixtures, or hosted-relay runtime files are present.
- Keep the commercial license, Composer metadata, Marketplace price, and production-site licensing language aligned before every release.
- Prepare a changelog, version support policy, privacy page, security policy, and support contact.

## Packagist and Composer release

1. Finish the product-proof and distribution gates above.
2. Commit the intended release files to the public GitHub repository.
3. Create an annotated semantic version tag, initially `v0.1.0` if this remains a prerelease.
4. Submit `axelferdinand/statamic-secretary` to [Packagist](https://packagist.org/).
5. Connect Packagist to GitHub so new tags update automatically.
6. Wait for Packagist to index the tagged version and inspect the published dependency constraints.
7. In a completely fresh Statamic 6 site, run:

   ```shell
   composer require axelferdinand/statamic-secretary
   php artisan migrate --force
   php please stache:refresh
   php please secretary:doctor
   ```

8. Verify addon discovery, published assets, CP navigation, permissions, chat, draft creation, email onboarding, and uninstall behavior.
9. Repeat this install test using the exact command shown by the Marketplace product.

## Statamic Marketplace release

1. Create or confirm the seller profile on [Statamic Marketplace](https://statamic.com/sell).
2. Connect GitHub and Stripe Connect. Statamic currently pays the seller a 75% commission and retains 25% for transaction fees, taxes, and Marketplace operations.
3. Create the addon product and link its GitHub repository and Packagist package.
4. Set the addon price to **USD 49**.
5. Keep the optional hosted `secretary@statamic.no` plan separate at **USD 49 per year**. Document whether it is purchased in the Marketplace, through a hosted-service checkout, or by license upgrade.
6. Add the product copy from `docs/marketplace-listing.md`.
7. Upload:
   - 1024×1024 and 512×512 icons.
   - Control Panel desktop and mobile screenshots.
   - A before/after change-card screenshot.
   - A redacted email-thread screenshot.
   - A short demo video or animated walkthrough.
8. List exact requirements: Statamic 6 Pro, PHP 8.3+, database, persistent Laravel queue, and an OpenAI API project.
9. Explain the two email options clearly:
   - Bring your own Postmark server and address.
   - Add the hosted `secretary@statamic.no` inbox for USD 49/year.
10. Link privacy, security, support, documentation, changelog, and license pages.
11. Test purchase, license validation, installation, update, renewal, cancellation, and support handoff with a non-owner account.
12. Publish only after the Marketplace listing and the exact Composer install command have passed the final end-to-end test.

## Launch assets and communication

- Replace generic Marketplace links on `secretary.statamic.no` with the final product URL when it exists.
- Capture landing-page analytics without sending content instructions, email addresses, or prompt text to analytics providers.
- Prepare a short launch post, documentation quick start, onboarding email, and support FAQ.
- State clearly that OpenAI usage, the customer's own Postmark fees, and the Statamic license are not included in the addon price.
- Never market the hosted inbox as generally available until the X/Y/random-sender isolation proof has passed.

## Final go/no-go

The first public paid release is a **go** only when:

- all required live gates pass;
- the distributed license matches the paid product;
- the Composer artifact is clean and reproducible;
- Packagist and Marketplace installs work from scratch;
- the hosted plan has tenant-isolation proof and operational ownership;
- support, privacy, security, and billing terms are public.
