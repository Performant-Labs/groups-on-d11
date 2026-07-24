#!/usr/bin/env bash
#
# run-kernel.sh — developer helper to run a single custom module's PHPUnit
# Kernel suite locally, or every module in a per-process loop.
#
# Why this exists (issue #205 / #210):
#   Running the full aggregated kernel suite (all 15 do_* modules in one
#   phpunit invocation, as CI does per-job) reliably exhausts memory on a
#   dev workstation and exits 137. This wrapper runs ONE module at a time,
#   which fits comfortably in a normal PHP memory_limit.
#
#   CI itself is unaffected — each module runs in its own GitHub Actions
#   job under `.github/workflows/test.yml` — this script exists solely so
#   local developers don't hit exit 137 and mistake OOM for infra failure.
#
# Usage:
#   bash scripts/dev/run-kernel.sh                 # print usage + module list
#   bash scripts/dev/run-kernel.sh <module>        # run one module's Kernel dir
#   bash scripts/dev/run-kernel.sh all             # run every module, one at a time
#
# Environment:
#   SKIP_DDEV=1   Skip the `ddev exec` wrapper even when ddev is detected;
#                 run phpunit against the host PHP directly. Useful in
#                 sub-shells / CI-like local runs / regression tests.
#   DRY_RUN=1     Print the phpunit command(s) that WOULD run and exit 0
#                 without invoking phpunit. Used by
#                 scripts/dev/tests/run-kernel-test.sh.
#
# Exit codes:
#   0   all requested modules passed (or DRY_RUN succeeded)
#   2   usage error: no arg, unknown module, or Kernel dir missing
#   *   propagated from phpunit on the first failing module
#
# Notes:
#   - Module discovery prefers the source-of-truth tree
#     `docs/groups/modules/*/tests/src/Kernel`; if that yields nothing
#     (e.g. an unusual checkout), falls back to the assembled tree
#     `web/modules/custom/*/tests/src/Kernel`. Actual test execution
#     always runs against `web/modules/custom/` because that's where the
#     Drupal autoloader expects them (per `scripts/ci/assemble-config.sh`).
#   - Kernel tests need the SIMPLETEST_* env vars from
#     `docs/groups/TEST_PLAN.md` §3.2; those are set when running via ddev.

set -uo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SRC_MODULES_DIR="$REPO_ROOT/docs/groups/modules"
BUILD_MODULES_DIR="$REPO_ROOT/web/modules/custom"
PHPUNIT_CONFIG="web/core/phpunit.xml.dist"

# --- Discover module names that have a Kernel test dir. -----------------
discover_modules() {
  local base
  local modules=()
  for base in "$SRC_MODULES_DIR" "$BUILD_MODULES_DIR"; do
    [[ -d "$base" ]] || continue
    while IFS= read -r -d '' dir; do
      # dir = .../<module>/tests/src/Kernel — extract module name.
      local m
      m="$(basename "$(dirname "$(dirname "$(dirname "$dir")")")")"
      modules+=("$m")
    done < <(find "$base" -maxdepth 4 -type d -path '*/tests/src/Kernel' -print0 2>/dev/null)
    if [[ ${#modules[@]} -gt 0 ]]; then
      # Source-of-truth wins; don't append fallback if primary yielded results.
      break
    fi
  done
  printf '%s\n' "${modules[@]}" | sort -u
}

# --- Build the Kernel path used by phpunit (always resolves under -------
# web/modules/custom because that's where Drupal autoloads from). --------
kernel_dir_for() {
  local module="$1"
  echo "web/modules/custom/${module}/tests/src/Kernel"
}

usage() {
  local modules
  modules="$(discover_modules)"
  {
    echo "usage: bash scripts/dev/run-kernel.sh <module|all>"
    echo
    echo "Run one custom module's PHPUnit Kernel suite, or 'all' to run each"
    echo "module in its own process (avoids the aggregate OOM — issue #205)."
    echo
    echo "Discovered modules:"
    if [[ -n "$modules" ]]; then
      echo "$modules" | sed 's/^/  /'
    else
      echo "  (none found under $SRC_MODULES_DIR or $BUILD_MODULES_DIR)"
    fi
    echo
    echo "Env: SKIP_DDEV=1 to bypass ddev, DRY_RUN=1 to print commands only."
  } >&2
}

# --- Emit the phpunit command for a single module. If DRY_RUN, print ----
# and return 0. Otherwise exec it (through ddev unless SKIP_DDEV=1). -----
run_one() {
  local module="$1"
  local kdir; kdir="$(kernel_dir_for "$module")"

  # In DRY_RUN we only assert on the source-of-truth name, so don't require
  # the assembled dir to exist on disk.
  if [[ "${DRY_RUN:-0}" != "1" && ! -d "$REPO_ROOT/$kdir" ]]; then
    echo "error: kernel dir missing (did you run scripts/ci/assemble-config.sh?): $kdir" >&2
    return 2
  fi

  local phpunit_cmd="php vendor/bin/phpunit -c ${PHPUNIT_CONFIG} --testdox ${kdir}"

  local use_ddev=0
  if [[ "${SKIP_DDEV:-0}" != "1" ]] && command -v ddev >/dev/null 2>&1; then
    # Heuristic: if we're already inside a ddev container, $IS_DDEV_PROJECT
    # is set; skip wrapping in that case too.
    if [[ -z "${IS_DDEV_PROJECT:-}" ]]; then
      use_ddev=1
    fi
  fi

  local full_cmd
  if [[ $use_ddev -eq 1 ]]; then
    full_cmd="ddev exec 'SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://web ${phpunit_cmd}'"
  else
    full_cmd="$phpunit_cmd"
  fi

  if [[ "${DRY_RUN:-0}" == "1" ]]; then
    echo "$full_cmd"
    return 0
  fi

  echo "→ $module: $full_cmd" >&2
  ( cd "$REPO_ROOT" && eval "$full_cmd" )
}

# --- Main. --------------------------------------------------------------
main() {
  if [[ $# -lt 1 ]]; then
    usage
    return 2
  fi

  local arg="$1"
  local modules; modules="$(discover_modules)"

  if [[ "$arg" == "all" ]]; then
    if [[ -z "$modules" ]]; then
      echo "error: no modules with Kernel tests discovered" >&2
      return 2
    fi
    local rc=0
    while IFS= read -r m; do
      [[ -z "$m" ]] && continue
      run_one "$m" || { rc=$?; echo "→ FAILED at module: $m (exit $rc)" >&2; return "$rc"; }
    done <<<"$modules"
    return 0
  fi

  # Single-module case.
  if ! grep -qx "$arg" <<<"$modules"; then
    echo "error: unknown module: $arg" >&2
    echo "known modules:" >&2
    echo "$modules" | sed 's/^/  /' >&2
    return 2
  fi

  run_one "$arg"
}

main "$@"
