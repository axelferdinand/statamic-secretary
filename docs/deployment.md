# Secretary deployment

Secretary uses the same deployment pattern as the Virke project: a dedicated
SSH identity, a one-way `rsync`, a mandatory dry-run, narrowly scoped remote
maintenance, a second synchronization check, and live HTTP checks.

## Repository and deliverables

One source repository, `axelferdinand/statamic-secretary`, owns three tracked
deliverables:

1. **The Composer/Marketplace addon** lives at the repository root (`src/`,
   `routes/`, `config/`, `database/`, `resources/` and `composer.json`). A
   tagged GitHub release is the source for the Composer package. Its archive
   deliberately excludes the hosted service, local sandbox, deployment
   scripts, tests and development dependencies.
2. **The hosted relay** lives under `relay/` and is deployed to
   `secretary.statamic.no`. It is a separate runtime and is never installed on
   customer sites through Composer.
3. **The public addon landing page** is part of the relay application under
   `relay/resources/landing.php`, with public assets under
   `relay/public/assets/`. The landing page and relay therefore deploy
   together.

The **live demo application itself is not stored in this repository**. It is a
separate Statamic installation at `/home2/statamic/secretary-demo`. This
routine synchronizes only the addon source into that installation and then
publishes the addon's compiled assets. Demo content, blueprints owned by the
demo site, users, assets, configuration, environment and runtime data remain
owned by the live demo installation. The local `sandbox/` is an ignored test
installation; it is neither a GitHub deliverable nor a deployment source.

Deploying the working tree to the hosted relay and live demo does not publish
a Composer release. Current addon changes reach customers only after they are
committed, pushed, tagged, made available through Packagist/Marketplace, and
installed or updated with Composer on the customer's site.

The routine updates two code targets:

- the hosted relay at `/home2/statamic/secretary-relay`;
- the addon installed by the live demo at
  `/home2/statamic/secretary-demo/vendor/axelferdinand/statamic-secretary`.

It deliberately does not synchronize the demo application, demo content,
users, assets, environment files, logs, relay database, or other production
runtime data.

## One-time server setup

The `statamic` cPanel account uses **Jailed Shell**. Its public deploy key is
imported and authorized in cPanel; the matching private key remains on the
local Mac. Normal shell access is not required.

Create a dedicated key at:

```bash
ssh-keygen -t ed25519 -a 100 \
  -f ~/.ssh/codex_statamic_deploy \
  -C codex-statamic-secretary-deploy
```

Import and authorize only its `.pub` file for the `statamic` account in cPanel,
then store the passphrase in the macOS keychain:

```bash
ssh-add --apple-use-keychain ~/.ssh/codex_statamic_deploy
```

The private key remains on the local Mac. It must not be uploaded to cPanel or
added to this repository.

## Normal deployment

In this project, an unqualified request to **Deploy** means a complete beta
release, not only a server synchronization. Unless the request is explicitly
limited to a target, the release owner must:

1. validate the addon and compiled assets;
2. move the current changelog entries into the next beta version;
3. commit and push the release to `main`;
4. create and push the matching `v0.1.0-beta.N` tag so Composer/Packagist can
   distribute the same code;
5. run the dry-run and production deployment below; and
6. verify the synchronized targets, production URLs, and published Composer
   version.

Use **Deploy live only** when the intended scope is deliberately limited to
the hosted relay, landing page, and live demo without publishing a new
Composer version.

Preview the exact upload and deletions:

```bash
scripts/deploy.sh
```

If the dry-run contains no unexpected deletion, deploy:

```bash
scripts/deploy.sh --apply
```

The apply step uploads the current working tree, including uncommitted work.
It then migrates the relay, provisions any missing friendly aliases, refreshes
the demo autoloader and published addon assets, prepares Secretary's private
database, clears caches, refreshes the Stache, runs Secretary Doctor, verifies
that both remote code targets exactly match the local sources, and checks the
relay, landing page, demo, login, and current compiled addon asset over HTTPS.

The deployment does not run `composer install` or an application build.
`composer dump-autoload` is used only for the live demo because its optimized
autoload map must learn about new addon classes.

The relay ships its generated authoritative autoloader. When relay classes are
newer than that artifact, the preflight stops and asks for:

```bash
composer dump-autoload --working-dir=relay \
  --no-dev --classmap-authoritative --no-scripts
```

Run the command locally, review the next dry-run, and deploy again. The relay
sync preserves server-managed `.well-known`, `cgi-bin` and `php.ini` paths in
addition to environment files, databases, logs and backups.
