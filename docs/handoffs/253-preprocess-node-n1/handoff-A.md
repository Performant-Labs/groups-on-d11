# Handoff-A: Phase 3 — #253 preprocess_node N+1 tableExists() (up-front plan review)

**Date:** 2026-07-26
**Branch:** 253-preprocess-node-n1
**Brief reviewed:** docs/handoffs/253-preprocess-node-n1/brief.md
**Reuse map:** docs/handoffs/253-preprocess-node-n1/survey.md
**Wireframe:** N/A (no UI surface)
**Verdict:** PASS

## Summary
Plan is consistent with the existing codebase. `drupal_static(__FUNCTION__ . '_has_comment_stats')` is the correct idiom for memoizing an idempotent schema check inside a preprocess hook; there is no pre-existing static-cache helper in this repo to extend. Locating the regression test under `do_chrome/tests/src/Kernel/` is right — themes cannot host kernel tests, `do_chrome` is the established companion module for `groups_chrome` (four existing kernel tests live there), and the enrichment being memoized is chrome/theme-adjacent, not stream-view-mode-config-owned by `do_streams`. One `warn` on the test's query-observation strategy: `Database::startLog()` pattern-matching on `information_schema.tables` is DB-driver-dependent and the survey/decisions already flag this; T should verify against the actual kernel-test DB driver and fall back to a schema-decorator observer if the log pattern is unreliable.

## Findings

| # | Severity | Plan element | Drift dimension | Finding | Suggested fix |
|---|---|---|---|---|---|
| 1 | warn | Test strategy: `Database::startLog()` + regex on `information_schema.tables%comment_entity_statistics%` | pattern consistency / cross-cutting | The information_schema-query shape only appears on MySQL/MariaDB. On SQLite (a common KernelTest default) the driver uses `sqlite_master`; on PostgreSQL it uses `pg_class`/`pg_tables`. Pattern-matching one shape silently under-asserts on the others (test could pass because zero matches on both call counts). Decisions.md already calls this out as an "Assumed", but the acceptance criterion in the brief is phrased in terms of the query count, not the semantically-correct "tableExists called at most once". | T should either (a) pin the kernel test DB driver explicitly and match its dialect, (b) match all three dialect fragments (`information_schema.tables`, `sqlite_master`, `pg_class`) with an OR-pattern, or (c) wrap `$database->schema()` with a counting decorator via `$container->set('database', ...)` in the test's `register()`. Option (c) is dialect-agnostic and matches the true acceptance criterion ("tableExists called ≤1"). No `block` because any of the three is acceptable — T's call. |

## Answers to evaluation questions

1. **`drupal_static(__FUNCTION__ . '_has_comment_stats')`** — correct. A service-level cache would over-engineer a per-request boolean that lives entirely inside one theme hook; a container parameter is wrong because the answer is a runtime schema fact, not build-time config. `drupal_static` with a scoped key is the canonical Drupal pattern for exactly this shape.
2. **Test location `docs/groups/modules/do_chrome/tests/src/Kernel/`** — correct. `do_chrome` already hosts kernel tests (`HelpTextStreamKeysTest`, `PageHelpRouteMapTest`, etc.), owns the chrome/help concerns for the `groups_chrome` theme, and is the closest module with a `tests/src/Kernel/` directory. `do_streams` owns stream_content-model + view routes/config, not per-card render enrichment, so co-locating there would be a layering drift.
3. **No prior static-cache helper to extend.** Grep for `drupal_static` under `docs/groups/` returns zero source-code hits; the only `tableExists` uses outside this hook (in `do_discovery`) are one-shot per hook invocation and correctly not memoized. Hand-rolling `drupal_static` is the reuse-map-consistent path.
4. **`Database::startLog()`** — acceptable but flagged (see Finding #1). A schema-decorator observer would be more robust; either is a valid PASS.
5. **No architectural drift or duplication risk.** ~20-line, single-hook, single-purpose change; acceptance criteria are complete for the memoization contract.

## Notes for O
None — plan proceeds to T (RED).

## Patterns referenced
- `web/themes/custom/groups_chrome/groups_chrome.theme` (target, lines 106–219)
- `docs/groups/modules/do_chrome/tests/src/Kernel/HelpTextStreamKeysTest.php` (kernel-test conventions in this companion module)
- `docs/groups/modules/do_discovery/src/Hook/DoDiscoveryHooks.php` (existing `tableExists()` usage — one-shot, no memoization needed)
- `docs/handoffs/253-preprocess-node-n1/survey.md` (reuse map)
- `docs/planning/perf/query-audit-2026-07-24.md` (PERF-4 context, per prompt)
