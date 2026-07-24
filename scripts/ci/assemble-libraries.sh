#!/usr/bin/env bash
#
# assemble-libraries.sh — assemble vendored front-end libraries into web/libraries/.
#
# Sibling of assemble-config.sh (same structure, same REPO_ROOT resolution, same
# echo/logging style). #125 (SC-6): `/web/libraries/` is gitignored (.gitignore:9),
# so Leaflet 1.9.4 is vendored as tracked SOURCE under `docs/groups/libraries/`
# and copied into the gitignored `web/libraries/` TARGET here — mirroring the
# existing `docs/groups/config/` -> `config/sync/` and
# `docs/groups/modules/` -> `web/modules/custom/` source-then-assemble
# discipline this script's sibling already establishes (handoff-A-plan.md
# Finding #1 resolution, docs/handoffs/0125-directory-map/decisions.md Phase 3).
#
# Plain recursive copy, idempotent (overwrite in place), no DB changes — unlike
# assemble-config.sh, this script has NO dependency on `composer install` /
# `vendor/autoload.php`, since the vendored library tree is committed to git
# directly (no autoload/module-registration step needed for a static JS/CSS
# asset copy).
#
# Usage:  scripts/ci/assemble-libraries.sh [REPO_ROOT]
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

LIBRARIES_SRC="${REPO_ROOT}/docs/groups/libraries"
LIBRARIES_DST="${REPO_ROOT}/web/libraries"

echo "==> assemble-libraries: repo root = ${REPO_ROOT}"

if [[ ! -d "${LIBRARIES_SRC}" ]]; then
  echo "ERROR: libraries source not found: ${LIBRARIES_SRC}" >&2
  exit 1
fi
mkdir -p "${LIBRARIES_DST}"

libcount=0
for dir in "${LIBRARIES_SRC}"/*/; do
  [[ -d "${dir}" ]] || continue
  name="$(basename -- "${dir}")"
  mkdir -p "${LIBRARIES_DST}/${name}"
  cp -R -- "${dir%/}"/. "${LIBRARIES_DST}/${name}/"
  libcount=$((libcount + 1))
  echo "==> libraries: assembled ${name}/"
done
echo "==> libraries: copied ${libcount} librar$([[ ${libcount} -eq 1 ]] && echo y || echo ies) into web/libraries/"

echo "==> assemble-libraries: done"
