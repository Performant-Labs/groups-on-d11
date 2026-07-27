# Handoff-F: Phase 5 - #253 preprocess_node N+1 tableExists()

**Date:** 2026-07-26
**Branch:** 253-preprocess-node-n1
**Issue:** #253

## What was done

- `web/themes/custom/groups_chrome/groups_chrome.theme` — wrapped the existing
  `$database->schema()->tableExists('comment_entity_statistics')` check inside
  `groups_chrome_preprocess_node()` (Comment count block, was lines 204-219,
  now lines 204-226) with a `drupal_static(__FUNCTION__ . '_has_comment_stats')`
  memo, so the schema check runs at most once per request instead of once per
  `stream_card` node render. No other file was modified.

## Diff summary

```diff
--- a/web/themes/custom/groups_chrome/groups_chrome.theme
+++ b/web/themes/custom/groups_chrome/groups_chrome.theme
@@ -201,10 +201,22 @@ function groups_chrome_preprocess_node(array &$variables): void {
   // --- Comment count ------------------------------------------------------
   // Read the aggregate from comment_entity_statistics (populated for any
   // comment-enabled entity), independent of which bundle owns a comment field.
+  //
+  // #253: `comment_entity_statistics`'s existence cannot change mid-request,
+  // so the schema check is memoized per request with drupal_static() —
+  // without this, it was re-issued as a fresh `information_schema.tables`
+  // query on EVERY stream_card node render (an N+1: ~10x/page cold on `/`,
+  // `/showcase`, `/stream` — docs/planning/perf/query-audit-2026-07-24.md).
+  // Scoped to THIS function via __FUNCTION__ so the key can't collide with
+  // an unrelated drupal_static() elsewhere in the codebase.
   $comment_count = 0;
   try {
     $database = \Drupal::database();
-    if ($database->schema()->tableExists('comment_entity_statistics')) {
+    $has_stats_table = &drupal_static(__FUNCTION__ . '_has_comment_stats');
+    if ($has_stats_table === NULL) {
+      $has_stats_table = $database->schema()->tableExists('comment_entity_statistics');
+    }
+    if ($has_stats_table) {
       $comment_count = (int) $database->select('comment_entity_statistics', 'ces')
         ->fields('ces', ['comment_count'])
         ->condition('entity_type', 'node')
```

`git diff --stat`: `1 file changed, 13 insertions(+), 1 deletion(-)`.

## Design decisions

- **Kept the memo guard INSIDE the existing try/catch**, not hoisted above it.
  If `tableExists()` throws on the memoized first run, the pre-existing
  `catch (\Exception $e)` must still absorb it exactly as before (comment
  count stays 0 for that render, and — since the exception happens before
  `$has_stats_table` is assigned — it stays `NULL` and the check is retried
  on the next node render in the same request, rather than being permanently
  poisoned to a stale value). This preserves both existing behaviors: (a) the
  try/catch's fail-safe contract is untouched, and (b) a transient DB error
  doesn't get "memoized" as a false negative for the rest of the request.
- **No new helper function.** `drupal_static(__FUNCTION__ . '_has_comment_stats')`
  is the full abstraction; there is no pre-existing static-cache helper in
  this theme file to extend (confirmed via grep — zero prior `drupal_static`
  usage), and A's Phase-3 review already rejected a service-level cache for
  this exact reason ("would over-engineer a per-request boolean").
- **Key scoping via `__FUNCTION__`** (`groups_chrome_preprocess_node_has_comment_stats`)
  is the brief's literal, A-approved key — defensive against a future
  `drupal_static()` call anywhere else in the codebase, even though none
  exists today.
- **No behavioral change to the fetchField path or the exact existing-table /
  missing-table outcomes.** When the table exists: the same `select()` /
  `fields()` / `condition()` / `execute()` / `fetchField()` chain runs,
  unchanged, byte-for-byte. When the table does not exist: `$has_stats_table`
  memoizes to `FALSE` and `$comment_count` stays `0`, exactly as before.

## Reuse / extend-vs-new

Extended the existing `groups_chrome_preprocess_node()` function in place —
the brief's Reuse map ("Recommendation: extend in place... Wrap the existing
`if ($database->schema()->tableExists(...))` line with a `drupal_static`
guard. No new function, no new service.") and A's Phase-3 PASS both call for
exactly this. No new object, function, or service was created.

## Architecture notes for A

- Single-hook, single-block change. No new dependencies, no schema/contract
  changes, no shared component touched. `drupal_static()` is Drupal core API
  already used pervasively across the platform; this is its first use in
  `groups_chrome.theme` but not a new pattern to the codebase.
- The memoization is per-PHP-request (drupal_static's normal semantics,
  reset between requests and explicitly by `drupal_static_reset()` in test
  teardowns) — matches the brief's stated invariant that the table's
  existence "cannot change mid-request."

## Deviations from spec / wireframe

None. Implemented exactly the shape specified in the brief and confirmed by
A's Phase-3 PASS.

## Tier 1 self-check (incl. tests now GREEN)

1. **`bash scripts/ci/assemble-config.sh`** — failed on the host with
   `php: command not found` (no host PHP on this Windows workstation) at its
   final `core.extension.yml`-patching step; this exact failure mode and its
   resolution are already documented precedent in
   `docs/planning/handoffs/191-seed-cascade-fix/handoff-F.md`. Re-ran via
   `ddev exec "bash scripts/ci/assemble-config.sh"` — full success:
   ```
   ==> assemble-config: repo root = /var/www/html
   ==> config: copied 139 file(s), excluded 7 env-specific file(s)
   ==> modules: copied 16 custom module(s) into web/modules/custom/
   ==> core.extension: registered custom do_* modules + flag/geofield/language/message/message_notify as enabled
   ==> assemble-config: done
   ```

2. **T's authored kernel test (both tests) — GREEN:**
   ```
   $ ddev exec "SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://web php vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/do_chrome/tests/src/Kernel/PreprocessNodeTableExistsCacheTest.php --testdox"

   PHPUnit 11.5.56 by Sebastian Bergmann and contributors.

   Runtime:       PHP 8.4.22
   Configuration: /var/www/html/web/core/phpunit.xml.dist

   ..                                                                  2 / 2 (100%)

   Time: 00:03.484, Memory: 10.00 MB

   Preprocess Node Table Exists Cache (Drupal\Tests\do_chrome\Kernel\PreprocessNodeTableExistsCache)
    ✔ Table exists checked once across ten stream card renders
    ✔ Comment count still populates from existing statistics row

   OK (2 tests, 24 assertions)
   ```

3. **Full `do_chrome` kernel suite (regression check for existing tests) — no
   regressions:**
   ```
   $ ddev exec "SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://web php vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/do_chrome/tests/src/Kernel --testdox"

   .....DDDD..                                                       11 / 11 (100%)

   Time: 00:08.807, Memory: 10.00 MB

   Help Text Consumer Coverage (...) — 2 tests pass
   Help Text Stream Keys (...) — 3 tests pass
   Page Help Route Map (...) — 4 tests pass
   Preprocess Node Table Exists Cache (...) — 2 tests pass

   4 tests triggered 1 deprecation: [pre-existing #[RunTestsInSeparateProcesses]
   deprecation on PageHelpRouteMapTest.php — a file this change does not touch]

   OK, but there were issues!
   Tests: 11, Assertions: 94, Deprecations: 1, PHPUnit Deprecations: 5.
   ```
   All 11 tests pass. The one "issue" flagged is a pre-existing deprecation
   warning on `PageHelpRouteMapTest.php`, unrelated to and unmodified by this
   change — confirmed not a regression this diff introduced.

4. **PHP syntax lint:**
   ```
   $ ddev exec "php -l web/themes/custom/groups_chrome/groups_chrome.theme"
   No syntax errors detected in web/themes/custom/groups_chrome/groups_chrome.theme
   ```

5. **phpcs (Self-check requirement) — zero new warnings/errors, three
   invocations, all exit code 0:**
   ```
   $ ddev exec "php vendor/bin/phpcs web/themes/custom/groups_chrome/groups_chrome.theme"
   (no output — clean)

   $ ddev exec "php vendor/bin/phpcs --standard=Drupal web/themes/custom/groups_chrome/groups_chrome.theme"
   (no output — clean)

   $ ddev exec "php vendor/bin/phpcs --standard=Drupal,DrupalPractice web/themes/custom/groups_chrome/groups_chrome.theme"
   (no output — clean)
   ```
   No project-root `phpcs.xml` pins a ruleset for this file; checked all
   three plausible standards (bare default, `Drupal`, `Drupal,DrupalPractice`
   — `drupal/coder` is installed per `phpcs -i`). The file was already
   phpcs-clean before this change (no pre-existing-violation baseline to
   compare against, unlike the `docs/groups/scripts/` precedent in #191's
   handoff-F) — this diff introduces zero new findings because the file had
   zero findings to begin with.

6. **Diff scope:** `git diff --stat` → `1 file changed, 13 insertions(+),
   1 deletion(-)`. `git status --porcelain` on the target file →
   `M web/themes/custom/groups_chrome/groups_chrome.theme` (only file
   touched). Line endings preserved (LF-only, confirmed before and after).

## Tests that look wrong (for T)

None. Both authored tests are correct, well-scoped, and pass against the
implementation without any test-authoring concerns surfaced during
implementation.

## Known issues

None. All three acceptance criteria are met:
- `tableExists('comment_entity_statistics')` is called at most once per
  request regardless of stream_card render count — proven by
  `testTableExistsCheckedOnceAcrossTenStreamCardRenders` (1 observed across
  10 renders).
- Comment count still populates correctly — proven by
  `testCommentCountStillPopulatesFromExistingStatisticsRow`
  (`gc_stream.comment_count === 4` for a seeded row).
- The kernel regression test exists at the specified path and both its
  assertions pass.

## Files changed

- `web/themes/custom/groups_chrome/groups_chrome.theme`
