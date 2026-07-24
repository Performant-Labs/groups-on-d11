#!/usr/bin/env bash
#
# run-kernel-test.sh — regression test for scripts/dev/run-kernel.sh.
#
# Asserts wrapper contract WITHOUT invoking phpunit (no DB needed):
#   - no arg          → exit 2, usage printed to stderr, module list shown
#   - unknown module  → exit 2, stderr mentions the bad name
#   - known module    → in DRY_RUN mode, exit 0 and print the phpunit command
#   - "all"           → in DRY_RUN mode, exit 0 and print one command per module
#
# Run from repo root: bash scripts/dev/tests/run-kernel-test.sh
#
# Exits 0 on success, 1 on first failure (fail-fast; prints which case failed).
set -uo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
WRAPPER="$REPO_ROOT/scripts/dev/run-kernel.sh"

fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "  ok — $*"; }

[[ -f "$WRAPPER" ]] || fail "wrapper missing at $WRAPPER"
[[ -x "$WRAPPER" || -r "$WRAPPER" ]] || fail "wrapper not readable"

# Force offline mode so tests don't try to invoke ddev / phpunit.
export SKIP_DDEV=1
export DRY_RUN=1

echo "test 1: no arg → exit 2 + usage on stderr"
out="$(bash "$WRAPPER" 2>&1 1>/dev/null)" ; rc=$?
[[ $rc -eq 2 ]] || fail "expected exit 2 on no-arg, got $rc"
grep -qi "usage" <<<"$out" || fail "no-arg output missing 'usage': $out"
grep -q "do_streams" <<<"$out" || fail "no-arg output missing module list (do_streams): $out"
pass "no-arg exits 2 and lists modules"

echo "test 2: unknown module → exit 2, mentions bad name"
bad="__definitely_not_a_module__"
out="$(bash "$WRAPPER" "$bad" 2>&1 1>/dev/null)" ; rc=$?
[[ $rc -eq 2 ]] || fail "expected exit 2 on unknown module, got $rc"
grep -q "$bad" <<<"$out" || fail "unknown-module stderr missing bad name '$bad': $out"
pass "unknown module exits 2 and cites bad name"

echo "test 3: known module in DRY_RUN → exit 0, prints phpunit command"
out="$(bash "$WRAPPER" do_streams 2>&1)" ; rc=$?
[[ $rc -eq 0 ]] || fail "expected exit 0 on known module in DRY_RUN, got $rc: $out"
grep -q "phpunit" <<<"$out" || fail "DRY_RUN output missing 'phpunit': $out"
grep -q "web/core/phpunit.xml.dist" <<<"$out" || fail "DRY_RUN output missing config path: $out"
grep -q "do_streams" <<<"$out" || fail "DRY_RUN output missing module name: $out"
pass "known module DRY_RUN prints phpunit command"

echo "test 4: 'all' in DRY_RUN → exit 0, prints multiple phpunit commands"
out="$(bash "$WRAPPER" all 2>&1)" ; rc=$?
[[ $rc -eq 0 ]] || fail "expected exit 0 on 'all' in DRY_RUN, got $rc: $out"
count=$(grep -c "phpunit" <<<"$out" || true)
[[ $count -ge 2 ]] || fail "expected >=2 phpunit lines from 'all', got $count: $out"
pass "'all' DRY_RUN prints per-module commands ($count lines)"

echo
echo "PASS: 4/4 wrapper contract tests"
