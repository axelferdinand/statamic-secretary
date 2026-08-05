#!/usr/bin/env bash
set -Eeuo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RELAY_EXCLUDES_FILE="${PROJECT_ROOT}/scripts/deploy-relay-excluded.txt"
ADDON_EXCLUDES_FILE="${PROJECT_ROOT}/scripts/deploy-addon-excluded.txt"

REMOTE_HOST="${SECRETARY_DEPLOY_HOST:-prototypen.sircon.net}"
REMOTE_USER="statamic"
REMOTE_HOME="/home2/statamic"
REMOTE_TARGET="${REMOTE_USER}@${REMOTE_HOST}"
SSH_KEY="${SECRETARY_DEPLOY_SSH_KEY:-${HOME}/.ssh/codex_statamic_deploy}"
REMOTE_PHP="/opt/cpanel/ea-php85/root/usr/bin/php"
REMOTE_COMPOSER="${REMOTE_HOME}/secretary-relay-private/composer.phar"

LOCAL_RELAY="${PROJECT_ROOT}/relay/"
LOCAL_ADDON="${PROJECT_ROOT}/"
REMOTE_RELAY="${REMOTE_HOME}/secretary-relay/"
REMOTE_ADDON="${REMOTE_HOME}/secretary-demo/vendor/axelferdinand/statamic-secretary/"
REMOTE_DEMO="${REMOTE_HOME}/secretary-demo"

RELAY_URL="${SECRETARY_DEPLOY_RELAY_URL:-https://secretary.statamic.no}"
DEMO_URL="${SECRETARY_DEPLOY_DEMO_URL:-https://secretary-demo.statamic.no}"

MODE="dry-run"

usage() {
  cat <<'EOF'
Usage:
  scripts/deploy.sh             Preview relay and live-demo changes
  scripts/deploy.sh --apply     Deploy, refresh the demo, and verify production
  scripts/deploy.sh --help      Show this help

Optional environment variables:
  SECRETARY_DEPLOY_HOST         SSH host (default: prototypen.sircon.net)
  SECRETARY_DEPLOY_SSH_KEY      Dedicated private SSH key
  SECRETARY_DEPLOY_RELAY_URL    Relay URL used by production checks
  SECRETARY_DEPLOY_DEMO_URL     Demo URL used by production checks

The script deploys the current working tree, including uncommitted changes.
It never deploys or deletes production .env files, databases, logs, demo
content, user data, or other runtime-owned files.
EOF
}

fail() {
  printf 'Deploy aborted: %s\n' "$*" >&2
  exit 1
}

for argument in "$@"; do
  case "$argument" in
    --apply)
      MODE="apply"
      ;;
    --dry-run)
      MODE="dry-run"
      ;;
    --help|-h)
      usage
      exit 0
      ;;
    *)
      fail "Unknown argument: ${argument}"
      ;;
  esac
done

cd "$PROJECT_ROOT"

for command_name in rsync ssh ssh-add ssh-keygen curl git php rg find awk; do
  command -v "$command_name" >/dev/null 2>&1 ||
    fail "Missing command: ${command_name}."
done

[[ -f "$RELAY_EXCLUDES_FILE" ]] || fail "Missing ${RELAY_EXCLUDES_FILE}."
[[ -f "$ADDON_EXCLUDES_FILE" ]] || fail "Missing ${ADDON_EXCLUDES_FILE}."
[[ -f "$SSH_KEY" ]] || fail "Missing the dedicated SSH key ${SSH_KEY}. See docs/deployment.md."
[[ -d "$LOCAL_RELAY" ]] || fail "Missing the local relay directory."
[[ -f "${LOCAL_ADDON}composer.json" ]] || fail "The addon source directory is invalid."
[[ "$REMOTE_USER" == "statamic" ]] || fail "Unexpected SSH user."
[[ "$REMOTE_HOME" == "/home2/statamic" ]] || fail "Unexpected remote home directory."
[[ "$REMOTE_RELAY" == "/home2/statamic/secretary-relay/" ]] || fail "Unexpected relay target."
[[ "$REMOTE_ADDON" == "/home2/statamic/secretary-demo/vendor/axelferdinand/statamic-secretary/" ]] ||
  fail "Unexpected demo addon target."

for required_exclusion in ".env" "error_log"; do
  rg --fixed-strings --line-regexp "$required_exclusion" "$RELAY_EXCLUDES_FILE" >/dev/null ||
    fail "The relay exclusions must contain ${required_exclusion}."
done

for required_exclusion in ".env" "/.git/" "/.secretary-deploy/" "/relay/" "/sandbox/" "/vendor/"; do
  rg --fixed-strings --line-regexp "$required_exclusion" "$ADDON_EXCLUDES_FILE" >/dev/null ||
    fail "The addon exclusions must contain ${required_exclusion}."
done

KEY_FINGERPRINT="$(ssh-keygen -lf "$SSH_KEY" | awk '{ print $2 }')"

if ! ssh-add -l 2>/dev/null | rg --fixed-strings "$KEY_FINGERPRINT" >/dev/null; then
  if [[ -t 0 ]]; then
    printf 'Loading the dedicated Secretary deployment key …\n'
    ssh-add --apple-use-keychain "$SSH_KEY" 2>/dev/null || ssh-add "$SSH_KEY"
  else
    fail "The SSH key is not loaded. Run: ssh-add --apple-use-keychain ${SSH_KEY}"
  fi
fi

git diff --check

if find resources/js resources/css -type f \( -name '*.js' -o -name '*.vue' -o -name '*.css' \) \
  -newer resources/dist/build/manifest.json -print -quit | rg -q .; then
  fail "The addon source is newer than resources/dist/build. Run npm run build first."
fi

RELAY_AUTOLOAD="relay/vendor/composer/autoload_classmap.php"

[[ -f "$RELAY_AUTOLOAD" ]] ||
  fail "The relay autoloader is missing. Run composer dump-autoload --working-dir=relay --no-dev --classmap-authoritative --no-scripts."

if find relay/src -type f -name '*.php' -newer "$RELAY_AUTOLOAD" -print -quit | rg -q .; then
  fail "The relay source is newer than its authoritative autoloader. Run composer dump-autoload --working-dir=relay --no-dev --classmap-authoritative --no-scripts."
fi

SSH_OPTIONS=(
  -i "$SSH_KEY"
  -o IdentitiesOnly=yes
  -o BatchMode=yes
  -o ConnectTimeout=10
)

printf -v RSYNC_SSH_COMMAND 'ssh -i %q -o IdentitiesOnly=yes -o BatchMode=yes -o ConnectTimeout=10' "$SSH_KEY"

RSYNC_COMMON=(
  -avz
  --numeric-ids
  --delete
  --stats
  -e "$RSYNC_SSH_COMMAND"
)

RELAY_RSYNC=(
  "${RSYNC_COMMON[@]}"
  --exclude-from="$RELAY_EXCLUDES_FILE"
)

ADDON_RSYNC=(
  "${RSYNC_COMMON[@]}"
  --exclude-from="$ADDON_EXCLUDES_FILE"
)

printf 'Checking SSH target %s …\n' "$REMOTE_TARGET"
REMOTE_PROBE="$(ssh "${SSH_OPTIONS[@]}" "$REMOTE_TARGET" '
  set -eu
  printf "%s\n%s\n" "$(id -un)" "$(pwd)"
  test -d /home2/statamic/secretary-relay
  test -d /home2/statamic/secretary-demo/vendor/axelferdinand/statamic-secretary
')" || fail "Could not log in with the dedicated Secretary deployment key."

[[ "$REMOTE_PROBE" == $'statamic\n/home2/statamic' ]] ||
  fail "The SSH target did not identify itself as the expected account and home directory."

printf '\nRelay dry-run: %s:%s\n' "$REMOTE_TARGET" "$REMOTE_RELAY"
rsync "${RELAY_RSYNC[@]}" --dry-run --itemize-changes "$LOCAL_RELAY" "${REMOTE_TARGET}:${REMOTE_RELAY}"

printf '\nLive-demo addon dry-run: %s:%s\n' "$REMOTE_TARGET" "$REMOTE_ADDON"
rsync "${ADDON_RSYNC[@]}" --dry-run --itemize-changes "$LOCAL_ADDON" "${REMOTE_TARGET}:${REMOTE_ADDON}"

if [[ "$MODE" == "dry-run" ]]; then
  printf '\nNo files were changed. Review the deletions, then run scripts/deploy.sh --apply.\n'
  exit 0
fi

printf '\nDeploying the current working tree …\n'
git status --short
rsync "${RELAY_RSYNC[@]}" "$LOCAL_RELAY" "${REMOTE_TARGET}:${REMOTE_RELAY}"
rsync "${ADDON_RSYNC[@]}" "$LOCAL_ADDON" "${REMOTE_TARGET}:${REMOTE_ADDON}"

printf '\nMigrating the relay and refreshing the live demo …\n'
ssh "${SSH_OPTIONS[@]}" "$REMOTE_TARGET" "
  set -eu

  cd '${REMOTE_RELAY%/}'
  '${REMOTE_PHP}' bin/migrate.php
  '${REMOTE_PHP}' bin/provision-public-aliases.php

  cd '${REMOTE_DEMO}'
  COMPOSER_HOME='${REMOTE_HOME}/secretary-relay-private' \\
    '${REMOTE_PHP}' '${REMOTE_COMPOSER}' dump-autoload --no-dev --classmap-authoritative --no-scripts
  '${REMOTE_PHP}' artisan optimize:clear --no-interaction
  '${REMOTE_PHP}' artisan vendor:publish --tag=statamic-secretary --force --no-interaction
  '${REMOTE_PHP}' artisan secretary:install --no-interaction
  '${REMOTE_PHP}' artisan statamic:stache:refresh --no-interaction
  '${REMOTE_PHP}' artisan secretary:doctor --json --no-interaction
"

RELAY_POST_CHECK="$(mktemp -t secretary-relay-post-check.XXXXXX)"
ADDON_POST_CHECK="$(mktemp -t secretary-addon-post-check.XXXXXX)"
trap 'rm -f "$RELAY_POST_CHECK" "$ADDON_POST_CHECK"' EXIT

printf '\nChecking that both code targets are synchronized …\n'
rsync "${RELAY_RSYNC[@]}" --dry-run --itemize-changes "$LOCAL_RELAY" "${REMOTE_TARGET}:${REMOTE_RELAY}" > "$RELAY_POST_CHECK"
rsync "${ADDON_RSYNC[@]}" --dry-run --itemize-changes "$LOCAL_ADDON" "${REMOTE_TARGET}:${REMOTE_ADDON}" > "$ADDON_POST_CHECK"

for post_check in "$RELAY_POST_CHECK" "$ADDON_POST_CHECK"; do
  rg --fixed-strings 'Number of regular files transferred: 0' "$post_check" >/dev/null ||
    fail "Production is not fully synchronized after deployment."
  rg --fixed-strings 'Number of deleted files: 0' "$post_check" >/dev/null ||
    fail "The post-deployment check found pending deletions."
done

ADDON_ASSET="$(php -r '
  $manifest = json_decode(file_get_contents("resources/dist/build/manifest.json"), true, 512, JSON_THROW_ON_ERROR);
  $entry = $manifest["resources/js/addon.js"]["file"] ?? "";
  if (! is_string($entry) || $entry === "") { exit(1); }
  echo $entry;
')" || fail "Could not resolve the built addon asset from the Vite manifest."

LIVE_URLS=(
  "${RELAY_URL%/}/"
  "${RELAY_URL%/}/health"
  "${RELAY_URL%/}/privacy"
  "${DEMO_URL%/}/"
  "${DEMO_URL%/}/cp/auth/login"
  "${DEMO_URL%/}/vendor/statamic-secretary/build/${ADDON_ASSET}"
)

printf '\nChecking production URLs …\n'
for live_url in "${LIVE_URLS[@]}"; do
  http_code="$(curl --connect-timeout 10 --max-time 20 --location --silent --show-error \
    --output /dev/null --write-out '%{http_code}' "$live_url")"
  [[ "$http_code" == "200" ]] || fail "${live_url} returned HTTP ${http_code}."
  printf '  200 %s\n' "$live_url"
done

printf '\nSecretary deployment completed.\n'
