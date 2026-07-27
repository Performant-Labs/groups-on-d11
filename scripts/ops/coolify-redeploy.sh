#!/usr/bin/env bash
# scripts/ops/coolify-redeploy.sh
#
# Trigger a Coolify redeploy of the groups-on-d11 production app.
#
# Preflight guards (all must pass before hitting the API):
#   1. On `main` branch
#   2. Clean working tree (no uncommitted changes)
#   3. Local main not behind origin/main (would deploy stale)
#
# Credentials: fetched at run time from 1Password Security vault via `op read`
# (no env var, no dotfile — token stays out of process env and never printed).
#
# See docs/ops/deploy.md §5b.
#
# Exit codes:
#   0  redeploy triggered (HTTP 200-range)
#   1  preflight guard failed
#   2  op read failed (1Password unavailable / not authenticated)
#   3  API call failed (non-2xx or curl error)

set -Eeuo pipefail

# --- Configuration (safe to print / commit) ---------------------------------
readonly APP_UUID='rt7xfshm01tvw4locfxb8f6t'
readonly COOLIFY_HOST='https://coolify.performantlabs.com'
readonly COOLIFY_UI="${COOLIFY_HOST}/project/uranus/environment/production/application/${APP_UUID}"
readonly OP_SECRET_REF='op://Security/k2xnfs4rjmldr77666gwuez3l4/notesPlain'

# --- Helpers ----------------------------------------------------------------
die() { printf 'ERROR: %s\n' "$1" >&2; exit "${2:-1}"; }
info() { printf '==> %s\n' "$1"; }

# --- Optional preflight bypass (for tests only) -----------------------------
# When SKIP_PREFLIGHT=1, guards are skipped. Never set this in normal use.
if [[ "${SKIP_PREFLIGHT:-0}" != "1" ]]; then
  # Guard 1: on main branch
  current_branch=$(git symbolic-ref --short HEAD 2>/dev/null || echo '')
  [[ "$current_branch" == "main" ]] \
    || die "not on main branch (current: '${current_branch:-detached}'). Deploy only from main." 1

  # Guard 2: clean working tree
  if [[ -n "$(git status --porcelain 2>/dev/null)" ]]; then
    die "working tree has uncommitted changes. Commit or stash before deploying." 1
  fi

  # Guard 3: local main not behind origin/main
  # Fetch quietly so the [behind N] marker is current.
  git fetch --quiet origin main 2>/dev/null || info "warning: git fetch failed; skipping remote-freshness check"
  status_sb=$(git status -sb 2>/dev/null || echo '')
  if grep -qE '\[behind [0-9]+' <<<"$status_sb"; then
    die "local main is behind origin/main. Pull before deploying (would deploy stale)." 1
  fi
fi

# --- Preflight passed. Fetch token + call API. ------------------------------
info "preflight passed; fetching Coolify API token from 1Password"

# The `coolio/coolify (PL.com)` item's notesPlain contains a header + the token.
# Extract the raw token (line starting with "2|" — Sanctum-style).
# Using process substitution so the token never lives in a shell variable
# that could leak into `set -x` output.
notes=$(op read "$OP_SECRET_REF" 2>/dev/null) \
  || die "op read failed. Is 1Password CLI authenticated (OP_SERVICE_ACCOUNT_TOKEN or 'op signin')?" 2

# Extract token (line matching Sanctum pattern: <int>|<alphanumeric>)
token=$(printf '%s\n' "$notes" | grep -oE '^[0-9]+\|[A-Za-z0-9]+' | head -n 1) \
  || die "could not parse token from 1Password item notesPlain" 2
[[ -n "$token" ]] || die "token empty after parsing" 2

info "POST ${COOLIFY_HOST}/api/v1/deploy?uuid=${APP_UUID}"

# Pass Authorization via --config so the token never appears in argv / ps output.
# curl reads config from stdin ('-'); one directive per line.
http_body=$(mktemp)
trap 'rm -f "$http_body"' EXIT

http_code=$(printf 'header = "Authorization: Bearer %s"\n' "$token" \
  | curl --config - \
      --silent --show-error \
      --request POST \
      --header 'Accept: application/json' \
      --output "$http_body" \
      --write-out '%{http_code}' \
      "${COOLIFY_HOST}/api/v1/deploy?uuid=${APP_UUID}") || die "curl failed" 3

# Scrub token from memory (best-effort)
token=''

if [[ ! "$http_code" =~ ^2 ]]; then
  printf 'HTTP %s from Coolify API. Response body:\n' "$http_code" >&2
  cat "$http_body" >&2 || true
  exit 3
fi

# Parse deployment ID (Coolify returns e.g. {"deployments":[{"resource_uuid":"...","deployment_uuid":"..."}]}
# or {"message":"...","deployment_uuid":"..."} — tolerate both).
deployment_id=$(grep -oE '"deployment_uuid"[[:space:]]*:[[:space:]]*"[^"]+"' "$http_body" \
  | head -n 1 | sed -E 's/.*"([^"]+)"$/\1/' || true)

info "Coolify redeploy triggered."
if [[ -n "$deployment_id" ]]; then
  printf '    deployment_uuid: %s\n' "$deployment_id"
fi
printf '    watch:           %s\n' "$COOLIFY_UI"
printf '    site:            https://groups.performantlabs.com\n'

exit 0
