# Handoff-S: Issue #190 — Harness Gaps

**Date:** 2026-07-24
**Branch:** 190-harness-gaps
**Worktree:** C:/Users/aange/Projects/_worktrees/groups-harness-gaps-190
**Handoff-T-green reviewed:** docs/handoffs/190-harness-gaps/handoff-T-green.md
**Handoff-T-red reviewed:** docs/handoffs/190-harness-gaps/handoff-T-red.md
**Handoff-F reviewed:** none written (T-green flagged as advisory; not a blocker)

## Preconditions
- A: prior-approved plan (Gap-1 permission grant; Gap-2 FileStorage view install).
- T: T-red confirmed all 5 tests failed for the right reason; T-green reports zero failures across the full 12 + 6 + 65 + 16 suites with only PHPUnit-11 deprecation notices.

## Scope check (production code untouched)
Diff of `docs/groups/modules/**` restricted to `tests/` — no `src/`, no `.module`, no production files. `git diff -- docs/groups/modules/` returns changes ONLY in:
- `tests/src/Kernel/PrivacyAccessTest.php` (setUp + 2 skip-line removals)
- `tests/src/Functional/PrivacyDirectoryTest.php` (setUp + 3 skip-line removals + module list additions)
- `tests/fixtures/config/{views.view.all_groups,field.storage…,field.field…}.yml` (new fixtures, untracked)

All other worktree modifications (config/sync/*, web/*, editorconfig, gitattributes) are pre-existing build artifacts from other stories in the shared checkout, not this PR — corroborated by T-red's environment notes. `.ddev/config.yaml` rename (gm145 → gm190) is scaffolding.

## Root-cause fix quality
- **Gap 1 (Kernel):** `installConfig(['user'])` + `user_role_grant_permissions(AUTHENTICATED_ID, ['access content'])`. Mirrors the cited `ActivityFeedKernelTestBase` pattern; correctly identifies that `NodeAccessControlHandler` short-circuits before Group's hooks fire. The docblock is exceptionally thorough — notes both the empirical debug-test verification AND why the two "forbidden" tests were previously passing for the wrong reason (over-restrictive base perm). Not a tautology.
- **Gap 2 (Functional):** `FileStorage` install of reduced view fixture + `field_group_location_text` field, matching `DirectoryFiltersTest`'s pattern verbatim. Fixture correctly drops geofield/field_group_location. Extra modules (`text`, `language`, `do_group_language`, `do_chrome`) each documented with a specific runtime symptom + mirrored precedent. The `router.builder->rebuild()` call is called out against PROJECT_CONTEXT.md gotcha #4.

## Assertion-body integrity
All 5 test bodies untouched — only `$this->markTestSkipped(...)` lines removed. Kernel spot-check confirms `assertFalse(isForbidden)` semantics preserved for both AC-4 cases; functional tests still GET the same URLs and assert the same status/text conditions cited in the issue.

## Anti-pattern scan
- No trivially-passing tests introduced.
- No test-only production shims (no `src/` touched).
- No lingering skip commands (`grep markTestSkipped` → 0 on both files; `grep "issue #190"` → 0 in tests/).
- Fixture reduction is disclosed inline, not hidden.

## Verdict

**PASS** — harness-only fix, correctly scoped, root-causes both gaps, mirrors established project patterns, zero regressions. Ready for O to commit + PR.

## Advisory notes
- F did not write a handoff-F.md. Non-blocking, since the fix rationale is now permanently embedded in the two test files' inline docblocks (better location than a handoff for future maintainers). Recommend O simply reference this S handoff + T-green in the PR body.
- The two previously-passing "forbidden" node-view kernel tests were passing for the wrong reason before this fix (as F's docblock notes). After the fix they now pass for the RIGHT reason. This is a latent-correctness improvement worth mentioning in the PR body.
