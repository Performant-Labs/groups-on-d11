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
MODULE_NAMES=()
for dir in "${MODULES_SRC}"/*/; do
  [[ -d "${dir}" ]] || continue
  name="$(basename -- "${dir}")"
  rm -rf -- "${MODULES_DST:?}/${name}"
  cp -R -- "${dir%/}" "${MODULES_DST}/${name}"
  MODULE_NAMES+=("${name}")
  modcount=$((modcount + 1))
done
echo "==> modules: copied ${modcount} custom module(s) into web/modules/custom/"

# --- 3. Register enabled modules in core.extension --------------------------
# The committed baseline `core.extension.yml` predates the do_* modules, so it
# does not list them (nor `flag`, which the assembled flag.* config depends on).
# The runbook enables these with `drush en` and re-exports; here we patch
# core.extension so the assembled config/sync is self-consistent — the do_tests
# kernel suite asserts do_group_extras / do_multigroup are enabled, and the
# clean-room `config:import` needs every module its config references present.
CORE_EXT="${CONFIG_DST}/core.extension.yml"
if [[ -f "${CORE_EXT}" ]]; then
  # Enable the copied custom modules (auto-discovered above into MODULE_NAMES,
  # which now includes do_chrome — the CH-F1 community-chrome/tooltip module,
  # #79) plus their non-custom hard dependencies that the assembled config
  # references but the baseline core.extension omits:
  #   - flag:             required by composer.json and the assembled flag.* config.
  #   - language:         hard dependency of do_group_language (do_group_language.info:
  #                       `drupal:language`); the assembled language.types.yml config
  #                       depends on it, so a clean-room `config:import` fails to install
  #                       do_group_language unless `language` is also enabled here.
  #   - message,
  #     message_notify:   hard dependencies of do_activity (#116); the assembled
  #                       do_activity config now references Message / Message Notify
  #                       templates, so a clean-room `config:import` fails to install
  #                       do_activity ("requires Message, Message Notify modules")
  #                       unless both are also enabled here.
  # `pathauto` is intentionally NOT added: its one config entry is in the
  # excluded env-specific set (§3.6).
  ENABLE_MODULES="$(printf '%s\n' "${MODULE_NAMES[@]}" flag language message message_notify)"
  export ENABLE_MODULES
  AUTOLOAD="${REPO_ROOT}/vendor/autoload.php"
  if [[ ! -f "${AUTOLOAD}" ]]; then
    echo "ERROR: ${AUTOLOAD} missing — run 'composer install' before assemble-config" >&2
    exit 1
  fi
  php -r '
    require $argv[2];
    $file = $argv[1];
    $ext = \Symfony\Component\Yaml\Yaml::parseFile($file);
    if (!isset($ext["module"]) || !is_array($ext["module"])) { $ext["module"] = []; }
    foreach (preg_split("/\s+/", trim(getenv("ENABLE_MODULES"))) as $m) {
      if ($m !== "" && !array_key_exists($m, $ext["module"])) { $ext["module"][$m] = 0; }
    }
    // Keep the module list sorted by weight then name, as Drupal exports it.
    uksort($ext["module"], function ($a, $b) use ($ext) {
      return [$ext["module"][$a], $a] <=> [$ext["module"][$b], $b];
    });
    file_put_contents($file, \Symfony\Component\Yaml\Yaml::dump($ext, 4, 2));
  ' "${CORE_EXT}" "${AUTOLOAD}" \
    || { echo "ERROR: failed to patch core.extension.yml" >&2; exit 1; }
  echo "==> core.extension: registered custom do_* modules + flag as enabled"
else
  echo "WARNING: ${CORE_EXT} not found; skipping module registration" >&2
fi

echo "==> assemble-config: done"
