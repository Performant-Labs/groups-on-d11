#!/usr/bin/env bash
#
# assemble-config.sh — assemble the full groups-on-d11 config + custom modules.
#
# Single source of truth shared by the RUNBOOK (manual bring-up) and CI. The
# committed `config/sync` is only the Phase-1 baseline; the phase-2..7 config
# lives under `docs/groups/config/` and the custom `do_*` modules live under
# `docs/groups/modules/`. This script replicates the runbook assembly:
#
#   1. Copy every `docs/groups/config/*.yml` into `config/sync/`, EXCLUDING the
#      seven env-specific entries that carry drupal.org-environment dependencies
#      (TEST_PLAN.md §3.6 / RUNBOOK Step 190). Those are placed in the real
#      environment per Step 600h and must NOT land in a clean-room `config/sync`.
#   2. Copy the custom `do_*` modules from `docs/groups/modules/` into
#      `web/modules/custom/` so they can be enabled / tested.
#
# It is idempotent (plain copies, overwrite in place) and makes no DB changes —
# `config:import` / `drush en` are the caller's responsibility.
#
# Usage:  scripts/ci/assemble-config.sh [REPO_ROOT]
#   REPO_ROOT defaults to the git top-level (or the script's grandparent dir).

set -euo pipefail

# Resolve the repository root.
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd -P)"
REPO_ROOT="${1:-}"
if [[ -z "${REPO_ROOT}" ]]; then
  if REPO_ROOT="$(git -C "${SCRIPT_DIR}" rev-parse --show-toplevel 2>/dev/null)"; then
    :
  else
    REPO_ROOT="$(cd -- "${SCRIPT_DIR}/../.." >/dev/null 2>&1 && pwd -P)"
  fi
fi

CONFIG_SRC="${REPO_ROOT}/docs/groups/config"
CONFIG_DST="${REPO_ROOT}/config/sync"
MODULES_SRC="${REPO_ROOT}/docs/groups/modules"
MODULES_DST="${REPO_ROOT}/web/modules/custom"

# The seven env-specific configs to EXCLUDE from a clean-room config/sync.
# (TEST_PLAN.md §3.6 — three do_* blocks need the `bluecheese` theme,
#  pathauto.pattern.group_relationship needs pathauto, and user.role.community
#  + its two role actions reference Contact / drupal.org-only node types.)
EXCLUDE=(
  "block.block.do_contribution_stats.yml"
  "block.block.do_group_mission.yml"
  "block.block.do_profile_completeness.yml"
  "pathauto.pattern.group_relationship.yml"
  "user.role.community.yml"
  "system.action.user_add_role_action.community.yml"
  "system.action.user_remove_role_action.community.yml"
)

is_excluded() {
  local name="$1"
  for e in "${EXCLUDE[@]}"; do
    [[ "${name}" == "${e}" ]] && return 0
  done
  return 1
}

echo "==> assemble-config: repo root = ${REPO_ROOT}"

# --- 1. Assemble config/sync -------------------------------------------------
if [[ ! -d "${CONFIG_SRC}" ]]; then
  echo "ERROR: config source not found: ${CONFIG_SRC}" >&2
  exit 1
fi
mkdir -p "${CONFIG_DST}"

copied=0
skipped=0
for src in "${CONFIG_SRC}"/*.yml; do
  [[ -e "${src}" ]] || continue
  name="$(basename -- "${src}")"
  if is_excluded "${name}"; then
    skipped=$((skipped + 1))
    continue
  fi
  cp -f -- "${src}" "${CONFIG_DST}/${name}"
  copied=$((copied + 1))
done
echo "==> config: copied ${copied} file(s), excluded ${skipped} env-specific file(s)"

if [[ "${skipped}" -ne "${#EXCLUDE[@]}" ]]; then
  echo "WARNING: excluded ${skipped} file(s) but expected ${#EXCLUDE[@]}; the env-specific set may have drifted from TEST_PLAN.md §3.6" >&2
fi

# --- 2. Copy custom do_* modules --------------------------------------------
if [[ ! -d "${MODULES_SRC}" ]]; then
  echo "ERROR: modules source not found: ${MODULES_SRC}" >&2
  exit 1
fi
mkdir -p "${MODULES_DST}"

modcount=0
for dir in "${MODULES_SRC}"/*/; do
  [[ -d "${dir}" ]] || continue
  name="$(basename -- "${dir}")"
  rm -rf -- "${MODULES_DST:?}/${name}"
  cp -R -- "${dir%/}" "${MODULES_DST}/${name}"
  modcount=$((modcount + 1))
done
echo "==> modules: copied ${modcount} custom module(s) into web/modules/custom/"

echo "==> assemble-config: done"
