# Handoff-T-red: Phase 4 - #253 preprocess_node N+1 tableExists()

**Date:** 2026-07-26
**Branch:** 253-preprocess-node-n1
**Brief / wireframe reviewed:** docs/handoffs/253-preprocess-node-n1/brief.md, docs/handoffs/253-preprocess-node-n1/survey.md, docs/handoffs/253-preprocess-node-n1/handoff-A.md (no wireframe — no UI surface)

## A precondition

Confirmed: A returned PASS on the plan (Phase 3), with one non-blocking `warn` (Finding #1) on
the test's query-observation strategy — flagged as T's call, not a block. See "Strategy" below
for how that warn was resolved.

## Tests authored

Both tests live in one new file:
`docs/groups/modules/do_chrome/tests/src/Kernel/PreprocessNodeTableExistsCacheTest.php`
(assembled path: `web/modules/custom/do_chrome/tests/src/Kernel/PreprocessNodeTableExistsCacheTest.php`).

1. **`testTableExistsCheckedOnceAcrossTenStreamCardRenders`** — pins the brief's literal
   acceptance criterion #3 ("asserts count of `information_schema.tables ...
   comment_entity_statistics` queries == 1 after 10 renders") and AC #1 ("tableExists(...) is
   called at most once per request regardless of how many stream_card nodes render"). Renders 10
   nodes through `groups_chrome_preprocess_node()` in `stream_card` view mode and asserts the
   `tableExists('comment_entity_statistics')` query count is exactly 1, plus a
   `assertGreaterThanOrEqual(1, ...)` guard so a vacuous "0 queries because the check was skipped
   entirely" can never masquerade as a pass. **Tier: kernel** — the cheapest tier that can invoke
   the real hook body and observe real DB query traffic; a unit test cannot exercise Drupal's
   `Database` query log, and functional/e2e would add HTTP/routing overhead this contract does
   not need.
2. **`testCommentCountStillPopulatesFromExistingStatisticsRow`** — pins AC #2 ("Comment count
   still populates correctly for nodes with comments"). Seeds one `comment_entity_statistics` row
   with `comment_count = 4` and asserts `gc_stream.comment_count === 4` after one render. This is
   the guard against the RED-test's own assertion being satisfiable by a broken/no-op
   memoization (e.g. a static guard that always short-circuits to `FALSE`). **Tier: kernel**, same
   rationale as above; also serves as the sanity baseline showing today's (pre-F) behavior for
   comment-count resolution is already correct, so the ONLY thing F's change may regress is the
   count assertion in test 1.

## RED confirmation

Command (from the worktree, via `ddev exec`, matching the project's own dev-wrapper pattern —
`SIMPLETEST_DB`/`SIMPLETEST_BASE_URL` copied from `scripts/dev/run-kernel.sh`):

```
bash scripts/ci/assemble-config.sh
ddev exec "SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://web php vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/do_chrome/tests/src/Kernel/PreprocessNodeTableExistsCacheTest.php --testdox"
```

Output:

```
PHPUnit 11.5.56 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.22
Configuration: /var/www/html/web/core/phpunit.xml.dist

F.                                                                  2 / 2 (100%)

Time: 00:04.198, Memory: 10.00 MB

Preprocess Node Table Exists Cache (Drupal\Tests\do_chrome\Kernel\PreprocessNodeTableExistsCache)
 ✘ Table exists checked once across ten stream card renders
   │
   │ Expected exactly 1 tableExists('comment_entity_statistics') query across 10 stream_card node renders (per-request memoization), but observed 10. Queries seen: SELECT 1 FROM information_schema.tables WHERE ("table_schema" = :db_condition_placeholder_102) AND ("table_name" = :db_condition_placeholder_103) AND ("table_type" = :db_condition_placeholder_104) | [... 9 more identical-shaped queries ...]
   │ Failed asserting that actual size 10 matches expected size 1.
   │
   │ /var/www/html/web/modules/custom/do_chrome/tests/src/Kernel/PreprocessNodeTableExistsCacheTest.php:219
   │
 ✔ Comment count still populates from existing statistics row

FAILURES!
Tests: 2, Assertions: 22, Failures: 1.
```

**This is a valid RED for the right reason.** Test 1 fails because the production code issues
**10** `tableExists('comment_entity_statistics')` queries — exactly one per rendered node, with no
memoization — against an expectation of **1**. That is precisely the N+1 the brief describes, not
a setup/import/typo failure. Test 2 (the comment-count sanity baseline) already passes today,
confirming the RED is isolated to the missing memoization, not a broader fixture defect.

No deprecation warnings, no PHP notices/errors outside the one intended assertion failure.

## Strategy notes (resolving A's Finding #1 warn)

A flagged the dialect-portability risk of `Database::startLog()` pattern-matching
(`information_schema.tables` is MySQL/MariaDB-only; SQLite/PgSQL use different system-catalog
shapes) and left the choice — dialect-matching vs. a schema-decorator observer — to T.

**Chosen: `Database::startLog()` + MySQL/MariaDB-dialect filter.** Verified, not assumed, that
this project has no SQLite/PgSQL kernel-test lane at all:
- `.github/workflows/test.yml` sets `SIMPLETEST_DB: 'mysql://root:root@mysql:3306/drupal'`
  unconditionally for both kernel-test jobs.
- `scripts/dev/run-kernel.sh` (the local dev wrapper) sets
  `SIMPLETEST_DB=mysql://db:db@db/db` unconditionally when running via `ddev exec`.

So the dialect concern doesn't apply here — there is exactly one dialect this suite will ever
run against. A full `database`-service Connection decorator was evaluated and rejected as
disproportionately fragile: `Connection` is `abstract` with a driver-specific constructor (no
clean subclass point), and query-builder methods (`select()`, `query()`) return objects bound to
`$this` — a `__call`-forwarding proxy risks silently diverging from the real connection identity
the moment anything does `instanceof Connection` or calls a method the proxy doesn't forward.
`Database::startLog()`/`getLog()` is core's own supported query-observation API and needs no such
shim.

**Query-shape gotcha found while authoring (documented inline in the test):** the base
`Schema::tableExists()` binds the table name as a **placeholder argument**, not inline in the
query string, and that bound value is **prefixed** with the kernel test's random table prefix
(e.g. `test53310640comment_entity_statistics`). The working filter matches the query SHAPE
(`information_schema.tables` present in `$log_entry['query']`) AND a bound arg in
`$log_entry['args']` that `str_ends_with()`s the bare table name — not a `str_contains()` on the
query string alone (which can never match, since the value is never inlined).

## Ready for F

RED is valid. F may implement against `PreprocessNodeTableExistsCacheTest`. Expected fix (per the
brief): wrap the existing `if ($database->schema()->tableExists('comment_entity_statistics'))`
check (line ~207 of `web/themes/custom/groups_chrome/groups_chrome.theme`) with
`drupal_static(__FUNCTION__ . '_has_comment_stats')`, preserving the surrounding try/catch and the
fetchField path. No other files require changes.
