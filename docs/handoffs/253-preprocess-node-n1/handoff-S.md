# Handoff-S: Phase 6 — #253 preprocess_node N+1 tableExists()

**Date:** 2026-07-26
**Branch:** 253-preprocess-node-n1
**Issue:** #253
**Handoff-A reviewed:** docs/handoffs/253-preprocess-node-n1/handoff-A.md
**Handoff-T-red reviewed:** docs/handoffs/253-preprocess-node-n1/handoff-T-red.md
**Handoff-F reviewed:** docs/handoffs/253-preprocess-node-n1/handoff-F.md
**Brief / survey reviewed:** brief.md, survey.md, decisions.md
**Operator-facing report:** N/A (no UI surface — POC lean per brief)

## A precondition
Confirmed: A returned PASS with one non-blocking warn (test-observation strategy) that T explicitly resolved by verifying the project has a single MySQL-only kernel-test lane.

## T precondition
Confirmed: T reported valid RED (10 observed / 1 expected — exact N+1 shape from PERF-4 audit), then GREEN in F's Tier-1 self-check (`OK (2 tests, 24 assertions)`). Zero blocking issues.

## Visual-diff-tool precondition
N/A — no visual surface changes.

## Acceptance-criteria audit

| # | Criterion | Verified by | Result |
|---|---|---|---|
| 1 | `tableExists('comment_entity_statistics')` called ≤1 per request across N renders | `testTableExistsCheckedOnceAcrossTenStreamCardRenders` (10 renders → assertCount(1)) plus `assertGreaterThanOrEqual(1, …)` vacuous-pass guard | PASS |
| 2 | Comment count still populates for nodes with comments | `testCommentCountStillPopulatesFromExistingStatisticsRow` (seeded `comment_count=4` → asserts `gc_stream.comment_count === 4`) | PASS |
| 3 | Kernel regression test at `docs/groups/modules/do_chrome/tests/src/Kernel/PreprocessNodeTableExistsCacheTest.php`, asserts count == 1 after 10 renders | File exists at exact path; assertCount(1, …) after 10 render loop | PASS |
| 4 | No unrelated changes / scope creep | `git status` functional entries: exactly two — theme file (M) + new kernel test (A). All other status entries are assemble-config build artifacts (config/sync/*, web/modules/custom/, etc.) matching the project's documented source-vs-assembled distinction | PASS |
| 5 | Fix matches the issue's suggested memoization pattern | Diff uses `static`-equivalent (`drupal_static(__FUNCTION__ . '_has_comment_stats')`) with `=== NULL` first-run check + subsequent `if ($has_stats_table)` — semantically identical to the issue's suggested snippet, scoped defensively via `__FUNCTION__` per brief/A | PASS |
| 6 | No drift from "extend in place" reuse recommendation | Single existing hook body extended; no new function, no new service, no new file (other than the mandated test) | PASS |

## Quality audit
| Area | Result | Notes |
|------|--------|-------|
| API consistency | N/A | No API surface |
| Error handling | PASS | Memo guard kept INSIDE existing try/catch — preserves fail-safe (`$comment_count` stays 0 on throw) AND avoids poisoning the static with a false negative (static stays NULL on exception, retried next call). Well-reasoned per F handoff. |
| UI/UX | N/A | |
| Accessibility | N/A | |
| Architecture gate | PASS | A returned PASS with only a warn; T resolved it. |
| Code organization | PASS | 12 lines of comment + 4 functional lines added. Comment cites #253, invariant, and PERF-4 report — future-reader-friendly. No dead code, no TODO markers, no commented-out blocks. |
| Security | N/A | |
| Performance | PASS | This IS the perf fix — collapses 10 `information_schema.tables` queries/page to 1. |
| Visual regression | N/A | |
| Naming consistency | PASS | `$has_stats_table` matches Drupal snake_case; drupal_static key `groups_chrome_preprocess_node_has_comment_stats` is fully scoped and self-describing. |
| Test quality (§7) | PASS | Two tests, both name one behavior each. Test 1 fails in isolation for the right reason (proven by RED: exact "10 observed / 1 expected" with the actual N+1 shape, not a setup/fixture false-negative). Test 2 is the deliberate anti-vacuous-pass guard (blocks a broken "always FALSE" memoization from passing test 1 silently). Both at cheapest sufficient tier (kernel — the only tier that can observe real DB query traffic against the real hook body). No assertion-free, tautological, snapshot-everything, or mock-shaped smells. Suite is proportionate — one bug story = two tests (the query-count contract + the behavior-preservation guard). Nothing to delete or merge. |

## Scope check
Delivered exactly the brief's scope: memoize one call site in one function, add one kernel test at the specified path, no drift. `git diff --stat` on tracked files: 1 theme file (+13/-1) + 1 new test file. Optional `.ddev/config.gm253.yaml` present (worktree-scoped DDEV project name to avoid collision with primary checkout) — legitimate infra, not scope creep, and gitignored per project convention.

## Pipeline stage notes
POC lean per brief (`Pipeline: O → A → T(RED) → F → T(GREEN) → diff-gate → S → PR → CI-green → self-merge`). All preceding stages returned clean. Ready for PR.

## Verdict

**PASS** — all six acceptance criteria met, RED→GREEN cycle valid and evidenced, spec-compliant, quality acceptable, scope minimal. Ready for O to create PR and rely on CI + self-merge per the uranus-wider-autonomy standing rule.

## Advisory notes
None. Textbook mechanical memoization change; the extensive handoff paper trail (survey → A warn → T resolution of warn → F preserving-try/catch reasoning) is a good template for future POC-lean single-file fixes.
