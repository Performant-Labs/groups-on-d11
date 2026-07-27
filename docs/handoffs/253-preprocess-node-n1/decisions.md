# Decisions — #253

## O — Phase 1 (survey + brief)
- **Decided:** memoize via `drupal_static(__FUNCTION__ . '_has_comment_stats')` inside the existing try/catch. Idiomatic; no new abstraction.
- **Decided:** kernel test in `do_chrome` module (theme can't host kernel tests); count `tableExists`-shaped queries via `Database::startLog()` / `getLog()`.
- **Assumed:** Drupal's schema check produces a query matchable by `information_schema.tables` + `'comment_entity_statistics'` on MySQL/MariaDB (DDEV stack). If test env uses SQLite, pattern differs — T must verify against the actual test DB and adjust.
- **Note:** issue body says file is theme-owned and tracked directly (correct). Prompt hint mentioned `docs/groups/themes/...` which does not exist; ignored.
- **Review-rigor:** `none`.

## A — Phase 3 (up-front plan review) — 2026-07-26
- **Decided:** PASS. `drupal_static` scoped key is the correct idiom; no prior static-cache helper exists to extend (verified via grep across `docs/groups/`).
- **Decided:** Test location in `do_chrome/tests/src/Kernel/` is correct — `do_chrome` is the established companion module for the `groups_chrome` theme and already hosts kernel tests. `do_streams` would be a layering drift (owns view-mode config, not per-card render enrichment).
- **Hedged (warn, not block):** `Database::startLog()` regex `information_schema.tables%...` is MySQL/MariaDB-only; SQLite/PostgreSQL kernel-test drivers use different system-catalog shapes. T should either pin the DB dialect, match multiple dialect fragments, or wrap `$database->schema()` with a counting decorator in `register()`. Any of the three is a valid PASS.
- **Evidence:** `web/themes/custom/groups_chrome/groups_chrome.theme:207`; `docs/groups/modules/do_chrome/tests/src/Kernel/HelpTextStreamKeysTest.php` (kernel-test conventions); `docs/groups/modules/do_discovery/src/Hook/DoDiscoveryHooks.php:31,52,128` (existing one-shot `tableExists` usages, correctly un-memoized).

## T — Phase 4 (RED) — 2026-07-26
- **Decided:** `Database::startLog()` + a MySQL/MariaDB-dialect query-log filter (option (b) from A's Finding #1), not a `database` service decorator (option (c)). A full `Connection` decorator was evaluated and rejected: `Connection` is abstract with a driver-specific constructor (no clean subclass point), and query-builder methods (`select()`, `query()`) return objects bound to `$this` — a `__call`-forwarding proxy risks silently diverging from the real connection identity. `Database::startLog()` is core's own supported observation API.
- **Decided:** the SQLite/PostgreSQL dialect-portability concern A raised does not apply to this project — verified, not assumed, by reading `.github/workflows/test.yml` and `scripts/dev/run-kernel.sh`, both of which set `SIMPLETEST_DB=mysql://...` unconditionally for every kernel-test run (CI and local dev wrapper). There is no SQLite/PgSQL kernel-test lane anywhere in this repo, so a single-dialect filter is not a portability risk here.
- **Decided:** call `groups_chrome_preprocess_node()` directly (via a guarded `require_once` of the `.theme` file) rather than installing the full `groups_chrome` theme as active and rendering through the real theme/render pipeline. The function is a plain global PHP function with no theme-negotiation dependency in its body; installing Olivero + regions + theme negotiation machinery would add real fragility to pin a database-call-count contract that has nothing to do with theme negotiation. Cheapest tier that still exercises the real, unmodified hook body.
- **Decided:** lightweight `KernelTestBase` (not `GroupsKernelTestBase`) — no group/gnode scaffolding is needed; the hook's group-lookup path is wrapped in try/catch and fails safe without the `group` module.
- **Evidence (empirical, found during authoring):** `Schema::tableExists()`'s bound query is `SELECT 1 FROM information_schema.tables WHERE ("table_schema" = ?) AND ("table_name" = ?) AND ("table_type" = ?)` — the table name is a BOUND ARG, not inlined in the query string, and it is PREFIXED with the kernel test's random table prefix (e.g. `test53310640comment_entity_statistics`). A naive `str_contains($log_entry['query'], 'comment_entity_statistics')` filter always returns 0 matches; the working filter matches the query shape (`information_schema.tables`) AND a bound arg ending in the bare table name (`str_ends_with`). Confirmed via a temporary debug dump of the raw query log before finalizing the filter.
- **Decided:** `#[RunTestsInSeparateProcesses]` is required (Drupal 11.3+ deprecation without it) — confirmed the deprecation warning disappears once added, with no behavior change to the RED result (still 10 observed / 1 expected).
- **RED confirmed:** 10 observed, 1 expected — `groups_chrome_preprocess_node()` issues one `tableExists('comment_entity_statistics')` query per node render today, exactly matching the brief's N+1 diagnosis. `testCommentCountStillPopulatesFromExistingStatisticsRow` (baseline sanity) passes today, confirming the RED is isolated to the memoization behavior, not a setup defect.

## F — Phase 5 (implementation) — 2026-07-26
- **Decided:** implemented exactly the shape the brief/O prescribed —
  `$has_stats_table = &drupal_static(__FUNCTION__ . '_has_comment_stats');` with
  a `=== NULL` first-run check, kept INSIDE the existing `try { ... } catch
  (\Exception $e) { ... }` block (not hoisted above it). Rationale: if
  `\Drupal::database()->schema()->tableExists()` itself throws on the memoized
  first run, the pre-existing catch must still absorb it exactly as it did
  before this change (comment_count stays 0) — moving the static-read/write
  outside the try block would not change that (the static var itself can't
  throw), but keeping the whole memoized check inside the try block preserves
  the reviewer-visible invariant "this whole schema-touching operation is
  still one contiguous try/catch unit" with zero behavioral surface added
  outside it, matching A's "no new defensive pattern" preference documented on
  the sibling #121 rework in this same file.
- **Decided:** did not extract a separate memoized boolean into its own named
  static-cache helper function (e.g. `_groups_chrome_comment_stats_table_exists()`).
  The brief's shape is a 5-line inline guard, `drupal_static` is already the
  full abstraction Drupal offers for this, and the survey (A-confirmed) found
  zero pre-existing static-cache helpers in this theme to be consistent with —
  extracting a wrapper function for a single call site would be the
  over-engineering A's Phase-3 review explicitly rejected ("A service-level
  cache would over-engineer a per-request boolean").
- **Decided:** the `drupal_static()` key is `__FUNCTION__ . '_has_comment_stats'`
  (i.e. `groups_chrome_preprocess_node_has_comment_stats`), verbatim per the
  brief and A's PASS — scoped to this one function so it cannot collide with
  any other `drupal_static()` call anywhere else in the codebase (there are
  none currently, confirmed by grep, but the scoping is defensive against a
  future one).
- **Verified, not assumed:** `bash scripts/ci/assemble-config.sh` fails on the
  host (`php: command not found` — no host PHP on this Windows workstation,
  only DDEV's containerized PHP) at its final `core.extension.yml`-patching
  step. This exact failure mode and its resolution (`ddev exec "bash
  scripts/ci/assemble-config.sh"`) is already documented as precedent in
  `docs/planning/handoffs/191-seed-cascade-fix/handoff-F.md`'s Tier-1
  self-check — re-ran the same way here rather than treating it as a BLOCK.
  The config/module-copy steps that matter for the kernel test (steps 1–2)
  had already succeeded on the host run before the PHP step failed, but the
  full script (steps 1–3) was still re-run via `ddev exec` for a clean,
  fully-successful assembly rather than relying on a partial host run.
- **Verified, not assumed:** phpcs reports ZERO errors/warnings against the
  modified file under three invocations — bare `phpcs` (no repo-root
  `phpcs.xml`, so the installed default standard applies), `--standard=Drupal`,
  and `--standard=Drupal,DrupalPractice` (both installed via `drupal/coder`
  per `phpcs -i`). Exit code 0 in all three. Confirmed the file was ALREADY
  phpcs-clean before this change (no pre-existing-tolerance caveat needed,
  unlike the `docs/groups/scripts/step_640.php` precedent in #191's
  handoff-F) — this diff introduces zero new findings because there were zero
  findings to begin with.
- **Evidence:** `web/themes/custom/groups_chrome/groups_chrome.theme:204-226`
  (the modified block); kernel test run —
  `web/modules/custom/do_chrome/tests/src/Kernel/PreprocessNodeTableExistsCacheTest.php`
  — `OK (2 tests, 24 assertions)`; full `do_chrome` kernel suite —
  `OK, but there were issues!` / `Tests: 11, Assertions: 94` (the one
  "issue" is a pre-existing `#[RunTestsInSeparateProcesses]` deprecation on
  `PageHelpRouteMapTest.php`, a file this change does not touch — no
  regression introduced); `git diff --stat` — 1 file changed, 13
  insertions(+), 1 deletion(-).

## S — Phase 6 (spec audit) — 2026-07-26
- **Verdict:** PASS. All six acceptance criteria met with test-backed evidence:
  AC #1/3 (query count == 1 after 10 renders) proven by `testTableExistsCheckedOnceAcrossTenStreamCardRenders`
  with an anti-vacuous-pass guard; AC #2 (comment count still populates) proven by
  `testCommentCountStillPopulatesFromExistingStatisticsRow`; AC #4-6 (no scope creep, matches
  suggested pattern, extends in place) confirmed via `git status`/`git diff` — exactly two
  functional file changes (theme +13/-1, new kernel test), no drift.
- **Verified, not assumed:** the functional diff on the theme is 28 lines total (13 insertions
  including comment, 1 deletion, plus context) — the F handoff's `1 file changed, 13
  insertions(+), 1 deletion(-)` claim reproduces exactly. All other `git status` entries are
  assemble-config build artifacts consistent with the source-vs-assembled distinction, not
  scope creep.
- **Test-quality (rubric §7):** two tests, both name one behavior each, both at cheapest
  sufficient tier (kernel). Test 2 is a deliberate anti-vacuous-pass guard for test 1 —
  proportionate, nothing to delete or merge. RED evidence in T-red handoff shows test 1 fails
  for the right reason (10 observed / 1 expected, exact N+1 shape) — not a setup/fixture
  false-negative.
- **No advisory notes.** Handoff written to `handoff-S.md`. Cleared for PR + CI-green + self-merge.
