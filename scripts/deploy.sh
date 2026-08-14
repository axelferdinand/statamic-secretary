#!/usr/bin/env bash
set -Eeuo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RELAY_EXCLUDES_FILE="${PROJECT_ROOT}/scripts/deploy-relay-excluded.txt"
ADDON_EXCLUDES_FILE="${PROJECT_ROOT}/scripts/deploy-addon-excluded.txt"

REMOTE_HOST="prototypen.sircon.net"
REMOTE_USER="statamic"
REMOTE_HOME="/home2/statamic"
REMOTE_TARGET="${REMOTE_USER}@${REMOTE_HOST}"
SSH_KEY="${SECRETARY_DEPLOY_SSH_KEY:-${HOME}/.ssh/codex_statamic_deploy}"
# cPanel's documented, version-specific PHP CLI symlink. It is executed by the
# verified, unprivileged statamic account.
REMOTE_PHP="/usr/local/bin/ea-php85"
REMOTE_COMPOSER="${REMOTE_HOME}/secretary-relay-private/composer.phar"
KNOWN_HOSTS_FILE="${PROJECT_ROOT}/scripts/deploy-known-hosts"
EXPECTED_DEPLOY_KEY_FINGERPRINT="SHA256:/LdBGJm+IghdraK0pGKf61bgmIlrhq4GualHCyH5pt4"
EXPECTED_HOST_KEY_FINGERPRINT="SHA256:E7FT1LJWhFOOWTrQVZi0Pq38DqNDdpACCt4DEm2pPRE"
RELEASE_REMOTE_URL="https://github.com/axelferdinand/statamic-secretary.git"
SSH_CONTROL_DIR=""
SSH_CONTROL_PATH=""
SSH_AGENT_DIR=""
DEPLOY_SSH_AGENT_PID=""
ORIGINAL_SSH_AUTH_SOCK="${SSH_AUTH_SOCK:-}"
DEPLOY_IDENTITY_AGENT=""
RELAY_POST_CHECK=""
ADDON_POST_CHECK=""

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
  scripts/deploy.sh --audit     Verify the remote account and stop
  scripts/deploy.sh             Preview relay and live-demo changes
  scripts/deploy.sh --apply     Deploy, refresh the demo, and verify production
  scripts/deploy.sh --help      Show this help

Optional environment variables:
  SECRETARY_DEPLOY_SSH_KEY      Dedicated private SSH key
  SECRETARY_DEPLOY_RELAY_URL    Relay URL used by production checks
  SECRETARY_DEPLOY_DEMO_URL     Demo URL used by production checks

Dry-run may preview the current working tree. Apply requires a clean, tagged
main commit that is already published as the same main commit and tag on
GitHub. The script never deploys or deletes production .env files, databases,
logs, demo content, user data, or other runtime-owned files.
EOF
}

fail() {
  printf 'Deploy aborted: %s\n' "$*" >&2
  exit 1
}

agent_has_deploy_key() {
  local agent_socket="$1"

  [[ -n "$agent_socket" && -S "$agent_socket" ]] || return 1
  SSH_AUTH_SOCK="$agent_socket" ssh-add -l 2>/dev/null |
    rg --fixed-strings "$KEY_FINGERPRINT" >/dev/null
}

cleanup() {
  if [[ -n "$SSH_CONTROL_PATH" && -S "$SSH_CONTROL_PATH" ]]; then
    ssh -F /dev/null -S "$SSH_CONTROL_PATH" -O exit "$REMOTE_TARGET" >/dev/null 2>&1 || true
  fi

  if [[ -n "$DEPLOY_SSH_AGENT_PID" ]]; then
    kill "$DEPLOY_SSH_AGENT_PID" >/dev/null 2>&1 || true
    wait "$DEPLOY_SSH_AGENT_PID" >/dev/null 2>&1 || true
  fi

  [[ -z "$RELAY_POST_CHECK" ]] || rm -f "$RELAY_POST_CHECK"
  [[ -z "$ADDON_POST_CHECK" ]] || rm -f "$ADDON_POST_CHECK"
  [[ -z "$SSH_CONTROL_DIR" ]] || rmdir "$SSH_CONTROL_DIR" 2>/dev/null || true
  [[ -z "$SSH_AGENT_DIR" ]] || rmdir "$SSH_AGENT_DIR" 2>/dev/null || true
}

trap cleanup EXIT

for argument in "$@"; do
  case "$argument" in
    --audit)
      MODE="audit"
      ;;
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

for command_name in rsync ssh ssh-add ssh-agent ssh-keygen curl git php rg find awk sed sleep stat; do
  command -v "$command_name" >/dev/null 2>&1 ||
    fail "Missing command: ${command_name}."
done

[[ -f "$RELAY_EXCLUDES_FILE" ]] || fail "Missing ${RELAY_EXCLUDES_FILE}."
[[ -f "$ADDON_EXCLUDES_FILE" ]] || fail "Missing ${ADDON_EXCLUDES_FILE}."
[[ -f "$KNOWN_HOSTS_FILE" ]] || fail "Missing the pinned Secretary host key."
[[ -f "$SSH_KEY" ]] || fail "Missing the dedicated SSH key ${SSH_KEY}. See docs/deployment.md."
[[ ! -L "$SSH_KEY" ]] || fail "The deployment key must not be a symbolic link."
[[ -d "$LOCAL_RELAY" ]] || fail "Missing the local relay directory."
[[ -f "${LOCAL_ADDON}composer.json" ]] || fail "The addon source directory is invalid."
[[ "$REMOTE_HOST" == "prototypen.sircon.net" ]] || fail "Unexpected SSH host."
[[ "$REMOTE_USER" == "statamic" ]] || fail "Unexpected SSH user."
[[ "$REMOTE_HOME" == "/home2/statamic" ]] || fail "Unexpected remote home directory."
[[ "$REMOTE_RELAY" == "/home2/statamic/secretary-relay/" ]] || fail "Unexpected relay target."
[[ "$REMOTE_ADDON" == "/home2/statamic/secretary-demo/vendor/axelferdinand/statamic-secretary/" ]] ||
  fail "Unexpected demo addon target."

for required_exclusion in ".env" "error_log"; do
  rg --fixed-strings --line-regexp "$required_exclusion" "$RELAY_EXCLUDES_FILE" >/dev/null ||
    fail "The relay exclusions must contain ${required_exclusion}."
done

for required_exclusion in ".env" "/.git/" "/.playwright-mcp/" "/.secretary-deploy/" "/relay/" "/sandbox/" "/vendor/"; do
  rg --fixed-strings --line-regexp "$required_exclusion" "$ADDON_EXCLUDES_FILE" >/dev/null ||
    fail "The addon exclusions must contain ${required_exclusion}."
done

KEY_FINGERPRINT="$(ssh-keygen -lf "$SSH_KEY" | awk '{ print $2 }')"
[[ "$KEY_FINGERPRINT" == "$EXPECTED_DEPLOY_KEY_FINGERPRINT" ]] ||
  fail "The deployment key fingerprint does not match the pinned Secretary key."

KEY_PERMISSIONS="$(stat -f '%Lp' "$SSH_KEY")"
[[ "$KEY_PERMISSIONS" == "400" || "$KEY_PERMISSIONS" == "600" ]] ||
  fail "The deployment key must have mode 0400 or 0600."

HOST_KEY_FINGERPRINT="$(ssh-keygen -lf "$KNOWN_HOSTS_FILE" | awk '{ print $2 }')"
[[ "$HOST_KEY_FINGERPRINT" == "$EXPECTED_HOST_KEY_FINGERPRINT" ]] ||
  fail "The pinned server host key is missing, duplicated, or unexpected."
rg --line-regexp 'prototypen\.sircon\.net ssh-ed25519 [A-Za-z0-9+/=]+' "$KNOWN_HOSTS_FILE" >/dev/null ||
  fail "The pinned server host key has an unexpected host or key type."

if [[ "$MODE" != "audit" ]]; then
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
fi

if [[ "$MODE" == "apply" ]]; then
  [[ "$(git branch --show-current)" == "main" ]] ||
    fail "Apply requires the main branch."
  [[ -z "$(git status --porcelain=v1 --untracked-files=all)" ]] ||
    fail "Apply requires a clean working tree."

  RELEASE_TAG="$(git tag --points-at HEAD | rg '^v[0-9]+\.[0-9]+\.[0-9]+(-beta\.[0-9]+)?$' || true)"
  [[ -n "$RELEASE_TAG" && "$RELEASE_TAG" != *$'\n'* ]] ||
    fail "Apply requires exactly one semantic release tag on HEAD."

  HEAD_SHA="$(git rev-parse HEAD)"
  REMOTE_REFS="$(git ls-remote --exit-code "$RELEASE_REMOTE_URL" \
    refs/heads/main "refs/tags/${RELEASE_TAG}" "refs/tags/${RELEASE_TAG}^{}")" ||
    fail "Could not verify the release on GitHub."
  REMOTE_MAIN_SHA="$(printf '%s\n' "$REMOTE_REFS" | awk '$2 == "refs/heads/main" { print $1 }')"
  REMOTE_TAG_SHA="$(printf '%s\n' "$REMOTE_REFS" | awk -v ref="refs/tags/${RELEASE_TAG}" '
    $2 == ref { sha = $1 }
    $2 == ref "^{}" { sha = $1 }
    END { print sha }
  ')"

  [[ "$REMOTE_MAIN_SHA" == "$HEAD_SHA" ]] ||
    fail "GitHub main does not match the local release commit."
  [[ "$REMOTE_TAG_SHA" == "$HEAD_SHA" ]] ||
    fail "The GitHub release tag does not match the local release commit."
fi

if agent_has_deploy_key "$ORIGINAL_SSH_AUTH_SOCK"; then
  DEPLOY_IDENTITY_AGENT="$ORIGINAL_SSH_AUTH_SOCK"
  printf 'Using the already-unlocked, pinned Secretary deployment key …\n'
else
  SSH_AGENT_DIR="$(mktemp -d -t secretary-ssh-agent.XXXXXX)"
  DEPLOY_IDENTITY_AGENT="${SSH_AGENT_DIR}/agent.sock"
  SSH_AUTH_SOCK="$DEPLOY_IDENTITY_AGENT"
  export SSH_AUTH_SOCK
  ssh-agent -a "$DEPLOY_IDENTITY_AGENT" -D >/dev/null 2>&1 &
  DEPLOY_SSH_AGENT_PID="$!"

  for _ in 1 2 3 4 5 6 7 8 9 10; do
    [[ -S "$DEPLOY_IDENTITY_AGENT" ]] && break
    sleep 0.05
  done
  [[ -S "$DEPLOY_IDENTITY_AGENT" ]] || fail "Could not start the private deployment key agent."

  printf 'Loading the dedicated Secretary deployment key into a private, short-lived agent …\n'
  if [[ -t 0 ]]; then
    ssh-add -t 600 --apple-use-keychain "$SSH_KEY" 2>/dev/null || ssh-add -t 600 "$SSH_KEY"
  else
    ssh-add -t 600 --apple-use-keychain "$SSH_KEY" </dev/null 2>/dev/null || true
  fi
  agent_has_deploy_key "$DEPLOY_IDENTITY_AGENT" ||
    fail "The dedicated deployment key could not be unlocked."
fi

SSH_AUTH_SOCK="$DEPLOY_IDENTITY_AGENT"
export SSH_AUTH_SOCK

SSH_OPTIONS=(
  -F /dev/null
  -p 22
  -i "$SSH_KEY"
  -o Hostname=prototypen.sircon.net
  -o "IdentityAgent=${DEPLOY_IDENTITY_AGENT}"
  -o IdentitiesOnly=yes
  -o AddKeysToAgent=no
  -o BatchMode=yes
  -o NumberOfPasswordPrompts=0
  -o PreferredAuthentications=publickey
  -o PubkeyAuthentication=yes
  -o PasswordAuthentication=no
  -o KbdInteractiveAuthentication=no
  -o GSSAPIAuthentication=no
  -o HostbasedAuthentication=no
  -o ForwardAgent=no
  -o ForwardX11=no
  -o ClearAllForwardings=yes
  -o PermitLocalCommand=no
  -o KnownHostsCommand=none
  -o ProxyCommand=none
  -o ProxyJump=none
  -o RemoteCommand=none
  -o RequestTTY=no
  -o StrictHostKeyChecking=yes
  -o "UserKnownHostsFile=${KNOWN_HOSTS_FILE}"
  -o GlobalKnownHostsFile=/dev/null
  -o HostKeyAlgorithms=ssh-ed25519
  -o UpdateHostKeys=no
  -o VerifyHostKeyDNS=no
  -o CheckHostIP=no
  -o CanonicalizeHostname=no
  -o ConnectionAttempts=1
  -o ConnectTimeout=10
)

SSH_CONTROL_DIR="$(mktemp -d /tmp/secretary-ssh.XXXXXX)"
SSH_CONTROL_PATH="${SSH_CONTROL_DIR}/ctl"
SSH_OPTIONS+=(
  -o "ControlPath=${SSH_CONTROL_PATH}"
)

printf -v RSYNC_SSH_COMMAND \
  'ssh -F /dev/null -p 22 -i %q -o Hostname=prototypen.sircon.net -o IdentityAgent=%q -o IdentitiesOnly=yes -o AddKeysToAgent=no -o BatchMode=yes -o NumberOfPasswordPrompts=0 -o PreferredAuthentications=publickey -o PubkeyAuthentication=yes -o PasswordAuthentication=no -o KbdInteractiveAuthentication=no -o GSSAPIAuthentication=no -o HostbasedAuthentication=no -o ForwardAgent=no -o ForwardX11=no -o ClearAllForwardings=yes -o PermitLocalCommand=no -o KnownHostsCommand=none -o ProxyCommand=none -o ProxyJump=none -o RemoteCommand=none -o RequestTTY=no -o StrictHostKeyChecking=yes -o UserKnownHostsFile=%q -o GlobalKnownHostsFile=/dev/null -o HostKeyAlgorithms=ssh-ed25519 -o UpdateHostKeys=no -o VerifyHostKeyDNS=no -o CheckHostIP=no -o CanonicalizeHostname=no -o ConnectionAttempts=1 -o ConnectTimeout=10 -o ControlMaster=no -o ControlPath=%q' \
  "$SSH_KEY" "$DEPLOY_IDENTITY_AGENT" "$KNOWN_HOSTS_FILE" "$SSH_CONTROL_PATH"

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

printf 'Opening one shared SSH connection to %s …\n' "$REMOTE_TARGET"
ssh "${SSH_OPTIONS[@]}" -o ControlMaster=yes -o ControlPersist=no -MNf "$REMOTE_TARGET" ||
  fail "Could not open the dedicated Secretary deployment connection."

printf 'Checking SSH target %s …\n' "$REMOTE_TARGET"
REMOTE_PROBE="$(ssh "${SSH_OPTIONS[@]}" "$REMOTE_TARGET" '
  set -eu
  printf "%s\n%s\n%s\n%s\n%s\n" "$(id -un)" "$(id -ru)" "$(id -u)" "$HOME" "$(pwd)"
  test -d /home2/statamic/secretary-relay
  test ! -L /home2/statamic/secretary-relay
  test -d /home2/statamic/secretary-demo/vendor/axelferdinand/statamic-secretary
  test ! -L /home2/statamic/secretary-demo/vendor/axelferdinand/statamic-secretary
  test "$(stat -c %u /home2/statamic/secretary-relay)" = "$(id -u)"
  test "$(stat -c %u /home2/statamic/secretary-demo/vendor/axelferdinand/statamic-secretary)" = "$(id -u)"
  test -x /usr/local/bin/ea-php85
  test ! -u /usr/local/bin/ea-php85
')" || fail "Could not log in with the dedicated Secretary deployment key."

PROBE_USER="$(printf '%s\n' "$REMOTE_PROBE" | sed -n '1p')"
PROBE_REAL_UID="$(printf '%s\n' "$REMOTE_PROBE" | sed -n '2p')"
PROBE_EFFECTIVE_UID="$(printf '%s\n' "$REMOTE_PROBE" | sed -n '3p')"
PROBE_HOME="$(printf '%s\n' "$REMOTE_PROBE" | sed -n '4p')"
PROBE_PWD="$(printf '%s\n' "$REMOTE_PROBE" | sed -n '5p')"

[[ "$PROBE_USER" == "statamic" && "$PROBE_REAL_UID" =~ ^[0-9]+$ &&
  "$PROBE_EFFECTIVE_UID" =~ ^[0-9]+$ && "$PROBE_REAL_UID" -ne 0 &&
  "$PROBE_EFFECTIVE_UID" -ne 0 && "$PROBE_REAL_UID" == "$PROBE_EFFECTIVE_UID" &&
  "$PROBE_HOME" == "/home2/statamic" && "$PROBE_PWD" == "/home2/statamic" ]] ||
  fail "The SSH target did not identify itself as the expected account and home directory."

if [[ "$MODE" == "audit" ]]; then
  printf 'Verified remote account: user=%s real_uid=%s effective_uid=%s home=%s\n' \
    "$PROBE_USER" "$PROBE_REAL_UID" "$PROBE_EFFECTIVE_UID" "$PROBE_HOME"
  printf 'No deployed file contents were read; no files were transferred, changed, or deleted.\n'
  exit 0
fi

printf '\nRelay dry-run: %s:%s\n' "$REMOTE_TARGET" "$REMOTE_RELAY"
rsync "${RELAY_RSYNC[@]}" --dry-run --itemize-changes "$LOCAL_RELAY" "${REMOTE_TARGET}:${REMOTE_RELAY}"

printf '\nLive-demo addon dry-run: %s:%s\n' "$REMOTE_TARGET" "$REMOTE_ADDON"
rsync "${ADDON_RSYNC[@]}" --dry-run --itemize-changes "$LOCAL_ADDON" "${REMOTE_TARGET}:${REMOTE_ADDON}"

if [[ "$MODE" == "dry-run" ]]; then
  printf '\nNo files were changed. Review the deletions, then run scripts/deploy.sh --apply.\n'
  exit 0
fi

printf '\nDeploying the verified tagged release …\n'
git status --short
rsync "${RELAY_RSYNC[@]}" "$LOCAL_RELAY" "${REMOTE_TARGET}:${REMOTE_RELAY}"
rsync "${ADDON_RSYNC[@]}" "$LOCAL_ADDON" "${REMOTE_TARGET}:${REMOTE_ADDON}"

printf '\nMigrating the relay and refreshing the live demo …\n'
ssh "${SSH_OPTIONS[@]}" "$REMOTE_TARGET" "
  set -eu
  test \"\$(id -un)\" = 'statamic'
  test \"\$(id -ru)\" = \"\$(id -u)\"
  test \"\$(id -u)\" -ne 0

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

  test \"\$(id -un)\" = 'statamic'
  test \"\$(id -ru)\" = \"\$(id -u)\"
  test \"\$(id -u)\" -ne 0
"

RELAY_POST_CHECK="$(mktemp -t secretary-relay-post-check.XXXXXX)"
ADDON_POST_CHECK="$(mktemp -t secretary-addon-post-check.XXXXXX)"

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
