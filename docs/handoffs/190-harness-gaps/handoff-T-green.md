# Handoff-T-green: Phase 6 - Issue #190 Harness Gaps

**Date:** 2026-07-24
**Branch:** 190-harness-gaps
**Issue:** #190
**Handoff-F reviewed:** none written yet at time of verification; verified directly against
worktree diff (only `PrivacyAccessTest.php` and `PrivacyDirectoryTest.php` under `tests/` changed,
uncommitted in working tree)
**Handoff-T-red:** docs/handoffs/190-harness-gaps/handoff-T-red.md

## GREEN confirmation

All 5 previously-skipped tests, plus every other test in both files, pass. `⚠` marks in testdox
output are PHPUnit 11 deprecation notices (core `getOriginal()` API), not failures — confirmed
`Failures: 0, Errors: 0` in every run.

Spot-checked the fix: F's `setUp()` change adds `installConfig(['user'])` +
`user_role_grant_permissions(AUTHENTICATED_ID, ['access content'])`, addressing the real cause
(Drupal's base `NodeAccessControlHandler` forbids view outright without `access content`,
pre-empting Group's own access hooks). This is a genuine behavior gate, not a tautology — removing
that grant reproduces the original RED (verified conceptually against the diff; the two "not
forbidden" node-view assertions depend on it).

## Tier 1 / suite results

1. **PrivacyAccessTest.php** (Kernel, full file): `Tests: 12, Assertions: 283, Failures: 0, Errors: 0` (2 deprecations only). GREEN.
2. **PrivacyDirectoryTest.php** (Functional, full file): `Tests: 6, Assertions: 45, Failures: 0, Errors: 0` (7 deprecations only). GREEN.
3. **Tier 2 Kernel** (`do_group_extras` + `do_group_membership`, all Kernel tests): `Tests: 65, Assertions: 1731, Failures: 0, Errors: 0` (5 deprecations, 30 PHPUnit deprecations). GREEN — no regressions.
4. **Tier 2 Functional** (`do_group_extras`, all Functional tests): `Tests: 16, Assertions: 104, Failures: 0, Errors: 0` (18 PHPUnit deprecations). GREEN — no regressions.

Note: had to supply `SIMPLETEST_DB=mysql://db:db@db:3306/db` and, for functional runs,
`SIMPLETEST_BASE_URL=http://gm190-harness.ddev.site` explicitly via `ddev exec sh -c`, since
neither is exported by default in this DDEV container.

## Tier 2 results (structural)

- **Skip-message cleanup:** `grep -rn "issue #190" docs/groups/modules/do_group_extras/tests/` → zero matches. `grep -rn markTestSkipped` on both changed files → zero matches. Confirmed clean.
- **Production code untouched:** `git diff --stat -- docs/groups/modules/` (working tree) shows only `tests/src/Functional/PrivacyDirectoryTest.php` and `tests/src/Kernel/PrivacyAccessTest.php` changed (158 lines total). No `src/` files touched. Confirmed F fixed the harness (test `setUp()`), not production code — correct scope for a harness-gap issue.
- **Test quality:** the `setUp()` addition is asserted against (not merely scaffolding) — it grants a real permission the built-in access-control layer requires; removing it would re-fail the two node-view assertions for the documented reason. No new tests were added, no redundant tests introduced — pure un-skip + minimal fixture fix, proportionate to a harness-gap issue.

## Acceptance criteria status

- AC-4 (member not forbidden from viewing node in private group): PASS — `testMemberNotForbiddenFromViewingNodeInPrivateGroup`
- AC-4 negative (non-member not forbidden, public group): PASS — `testNonMemberNotForbiddenFromViewingNodeInPublicGroup`
- AC-3 (`/all-groups` omits "Security Team" for anonymous): PASS — `testAnonymousAllGroupsOmitsSecurityTeamLiterally`
- Negative baseline (anonymous still sees public group): PASS — `testAnonymousStillSeesPublicGroup`
- AC-5 (member sees private group in directory + canonical 200): PASS — `testMemberSeesPrivateGroupInDirectoryAndCanonical`

## Blocking issues

None.

## Advisory notes

- F has not yet written `handoff-F.md` — recommend F add one documenting the `setUp()` fix and
  rationale (mirrors what's already captured in the test file's own updated docblock) before O
  advances the pipeline, for the audit trail's sake. Not a blocker to this verification.
- No UI surface (harness-only test fix, no wireframe) — U is N/A.

**Verdict: GREEN — ready for diff-gate.**
