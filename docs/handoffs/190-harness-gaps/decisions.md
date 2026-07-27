# Decisions: Issue #190 Harness Gaps

## Phase 4 (T-red) — 2026-07-24

- **Decided:** Removed only the 5 named `markTestSkipped()` calls; left all other test body code,
  docblocks, and `setUp()` untouched, per task scope (F owns setUp/production-code fixes in Phase 5).
- **Decided:** Renamed the worktree's stale `.ddev/config.yaml` project name from `gm145-wcag`
  (leftover from being copied off another worktree) to `gm190-harness`, matching the namespace
  specified in the task, to avoid colliding with the already-running `gm134-private` project that
  claims `gm145-wcag`.
- **Assumed:** DDEV default DB credentials (`db:db@db:3306/db`) apply since this project uses a
  standard DDEV `drupal10`/mariadb setup with no custom `SIMPLETEST_DB` override in `.ddev/`.
- **Evidence:** Kernel suite run via `ddev exec` with `SIMPLETEST_DB=mysql://db:db@db:3306/db`
  after `ddev composer install` + `ddev exec bash scripts/ci/assemble-config.sh`; both target
  kernel tests failed on the real assertion (`Failed asserting that true is false` on
  `isForbidden()`), confirming RED for the right reason.
- **Evidence:** Functional suite (`phpunit.functional.xml`) ran successfully against the DDEV web
  server; all 3 target tests failed exactly as their former skip messages predicted (404 on
  `/all-groups`), confirming the harness gap is real, current, and not stale documentation.
- **Hedged:** Did not attempt to diagnose *why* `/all-groups` 404s in the functional harness — that
  root-cause investigation belongs to F in Phase 5 (Gap-2 fix). Similarly did not investigate the
  node_access realm gap's fix — only confirmed the RED baseline for Gap-1.

## Phase 6 (T-green) — 2026-07-24

- **Decided:** Treated `⚠`/"OK, but there were issues!" testdox output as PASS since it reflects
  PHPUnit deprecation notices (core `getOriginal()` API) with `Failures: 0, Errors: 0` in every
  run — not a red signal.
- **Evidence:** Diffed against `main` first, which included ~200 unrelated files from prior merged
  stories (misleading for a "did F touch production code" check); corrected by diffing the
  uncommitted working tree instead (`git diff --stat -- docs/groups/modules/`), which correctly
  isolates F's actual change to the two named test files (158 lines, tests/ only).
- **Evidence:** All 4 required runs (2 targeted + 2 Tier-2 broader) green: PrivacyAccessTest
  12/12, PrivacyDirectoryTest 6/6, combined do_group_extras+do_group_membership Kernel 65/65,
  do_group_extras Functional 16/16 — zero regressions.
- **Assumed:** `SIMPLETEST_BASE_URL` needed explicit override to `http://gm190-harness.ddev.site`
  (not set by default in this container) — inferred from `ddev describe` project URL.
- **Hedged:** F had not yet written `handoff-F.md` at verification time; proceeded by inspecting
  the working-tree diff directly since the fix (setUp() permission grant) was self-evident and
  matched the Gap-1 root cause T-red anticipated. Flagged as an advisory note, not a blocker.
