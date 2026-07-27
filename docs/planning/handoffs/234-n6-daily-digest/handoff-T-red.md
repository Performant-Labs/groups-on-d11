# Handoff-T-red: Phase 4 - #234 N-6 Daily digest worker

**Date:** 2026-07-26
**Branch:** 234-n6-daily-digest
**Brief / wireframe reviewed:** `docs/planning/handoffs/234-n6-daily-digest/brief.md`, `docs/planning/handoffs/234-n6-daily-digest/handoff-A.md`, `docs/planning/handoffs/234-n6-daily-digest/survey.md` (no wireframe — no UI surface, N/A per brief).

## A precondition

Confirmed: A returned PASS on the plan (Phase 3), 3 `warn` findings, 0 `block`. All 3 warns are baked into the tests below (see "A's warns locked" section).

## Tests authored

### `docs/groups/modules/do_notifications/tests/src/Kernel/DailyDigestCommandTest.php` (NEW)

Extends `GroupsKernelTestBase`. 4 tests, covering AC1-AC9 via one integrated scenario plus 3 focused tests:

| Test | Criterion / behavior pinned | Tier | Why this tier |
|---|---|---|---|
| `testDigestDailyAggregatesPerUserAndConsumesSourceRows` | AC1 (command callable), AC2 (10 items/3 users -> 3 rows, window='daily', send_at=now), AC3 (10 source rows deleted, 6 untouched remain), AC4 (user A's digest traces to exactly 7 rendered fragments via subject count), AC5 (in-window daily item untouched), AC6 (immediately/weekly items untouched), AC8 (summary shape `['users_digested'=>3,'items_consumed'=>10,'digests_enqueued'=>3]`) | Kernel | Needs a real DB, real Message entities, real `DigestRenderer`/`EmailRenderer` rendering pipeline, and the full claim/render/enqueue/delete transaction — cannot be unit-tested without stubbing away exactly the integration this story is about. One integrated scenario (not one test per AC) because these behaviors are only observable together from a single command invocation; splitting them would duplicate the expensive fixture setup (16 queue rows, 1 group, multiple users) for no additional signal. |
| `testWindowSecondsStateOverrideNarrowsWindow` | AC9 (`state.set('do_notifications.digest_daily.window_seconds', N)` narrows the window) | Kernel | Isolated from the main scenario because it needs its OWN before/after comparison (same row, two different state values) — folding it into the main scenario would conflate "window respected" with "window is live-configurable". |
| `testOrphanedMidIsDroppedAndDeletedWithoutBlockingOtherMessages` | A's locked omission: a queue row whose `mid` has no Message is dropped from the digest AND its queue row is deleted, without blocking the user's other valid messages | Kernel | Needs the real claim -> `loadMultiple` -> render pipeline to prove the orphan doesn't throw and doesn't block sibling rows; a mock-based test would not exercise F's actual `loadMultiple`-skip logic. |
| `testUserWithNoInWindowItemsProducesNoDigestRow` | AC7 (zero items -> zero digest rows), isolated as its own minimal test | Kernel | Given its own test (not left solely to the main scenario's incidental zero-item user) so this specific guard has a test that fails in isolation if removed. |

### `docs/groups/modules/do_notifications/tests/src/Kernel/DatabaseQueueBackendTest.php` (EXTENDED — pre-existing file, 2 pre-existing tests untouched)

Added 4 tests for the two new `QueueBackendInterface` methods:

| Test | Criterion / behavior pinned | Tier | Why this tier |
|---|---|---|---|
| `testClaimDailyFiltersOnFrequencyAndThreshold` | `claimDaily(int $olderThan)` applies `frequency='daily' AND created<threshold` as an AND, not an OR (excludes daily-but-in-window AND non-daily-but-old rows); does not delete | Kernel | Needs a real DB table + real SQL filter — this is exactly the backend's SQL contract. |
| `testClaimDailyReturnsRowsAcrossMultipleUsers` | `claimDaily()` returns rows spanning multiple uids | Kernel | Same reason; also confirms no accidental `LIMIT 1`/single-user assumption in the query. |
| `testDeleteByIdsRemovesOnlySpecifiedRows` | `deleteByIds(array $ids)` deletes exactly the given ids, leaves others | Kernel | Real DB `DELETE ... WHERE id IN (...)` semantics. |
| `testDeleteByIdsWithEmptyArrayIsNoOp` | `deleteByIds([])` is a safe no-op (no accidental `DELETE FROM table` with no WHERE clause) | Kernel | Guards a classic empty-array-IN-clause footgun; only verifiable against the real query builder. |

A private `insertRawRow()` helper inserts rows with an explicit `created` timestamp, bypassing `enqueue()`'s hardcoded `time.getRequestTime()` — this codebase has no `TestTime` seam (confirmed via grep), so this is the equivalent "inject old `created` directly via raw DB insert" fallback named in the task prompt.

### `docs/groups/modules/do_notifications/tests/src/Kernel/DigestQueueBackendTest.php` (NEW)

4 tests for `DigestQueueBackendInterface` + `DatabaseDigestQueueBackend`:

| Test | Criterion / behavior pinned | Tier | Why this tier |
|---|---|---|---|
| `testEnqueuePersistsRowAndReturnsId` | `enqueue(...): int` persists a real row (uid, window, subject, body_text, body_html, send_at) and returns its id; `all()` is read-only | Kernel | Real DB round-trip is the entire contract being pinned. |
| `testMultipleEnqueuesPersistDistinctRows` | No dedup on the digest queue (unlike the per-message queue) — two enqueues for the same uid/window both persist | Kernel | Contrasts directly with `QueueBackendInterface`'s dedup contract; needs the real table's lack of a unique key. |
| `testDeleteByIdsRemovesOnlySpecifiedRows` | `deleteByIds()` on the digest queue, same claim-then-delete shape as A's finding #3 (locked: `all()` + `deleteByIds()`, not a combined `drain()`) | Kernel | Real DB delete-by-id semantics. |
| `testDeleteByIdsWithEmptyArrayIsNoOp` | Empty-array safety | Kernel | Same footgun-guard rationale as the per-message queue's equivalent test. |

## A's warns locked

1. **`do_notifications_update_11001()` (D11 numbering, not 9001).** Not directly assertable by a kernel test (update hooks aren't exercised by `installSchema()`), but the schema shape enforced by all three suites (`do_notifications_digest_queue` columns) is what F's update hook must produce — the hook number itself is a code-review/lint concern for A's diff pass, noted here so F does not regress it.
2. **`DigestCommands` registered in `drush.services.yml` only.** The test resolves the command via `\Drupal::service('do_notifications.digest_command')` — this works identically regardless of which services.yml file registers it, so the test doesn't distinguish F's registration file, but F's handoff should confirm the single-registration choice; T-green will re-check no duplicate-instantiation warning appears.
3. **Digest queue interface uses `all()` + `deleteByIds()`, not `drain()`.** Locked directly: `DigestQueueBackendTest` tests `all()` (read-only) and `deleteByIds()` (explicit id-based delete) — no `drain()` method is tested or required.

**Missing-Message behavior (locked, the specific ask in this prompt):** `testOrphanedMidIsDroppedAndDeletedWithoutBlockingOtherMessages` pins option (a) from A's finding: drop from digest AND delete the queue row (garbage-collect), rather than leaving it to retry.

## RED confirmation

Run command (from repo root, inside the ddev container after `bash scripts/ci/assemble-config.sh`):

```
ddev exec "cd /var/www/html && SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://web php vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/do_notifications/tests/src/Kernel/DailyDigestCommandTest.php web/modules/custom/do_notifications/tests/src/Kernel/DatabaseQueueBackendTest.php web/modules/custom/do_notifications/tests/src/Kernel/DigestQueueBackendTest.php --testdox"
```

Result: `Tests: 14, Assertions: 9, Errors: 12, Deprecations: 14.`

Exact failing output (the 12 new/affected tests, each failing for the right reason):

```
Daily Digest Command (Drupal\Tests\do_notifications\Kernel\DailyDigestCommand)
 ✘ Digest daily aggregates per user and consumes source rows
   │ LogicException: do_notifications module does not define a schema for table 'do_notifications_digest_queue'.
 ✘ Window seconds state override narrows window
   │ LogicException: do_notifications module does not define a schema for table 'do_notifications_digest_queue'.
 ✘ Orphaned mid is dropped and deleted without blocking other messages
   │ LogicException: do_notifications module does not define a schema for table 'do_notifications_digest_queue'.
 ✘ User with no in window items produces no digest row
   │ LogicException: do_notifications module does not define a schema for table 'do_notifications_digest_queue'.

Database Queue Backend (Drupal\Tests\do_notifications\Kernel\DatabaseQueueBackend)
 ⚠ Enqueue drain count               (pre-existing test, UNCHANGED, still passes)
 ⚠ Enqueue dedup on tuple             (pre-existing test, UNCHANGED, still passes)
 ✘ Claim daily filters on frequency and threshold
   │ Error: Call to undefined method Drupal\do_notifications\Queue\DatabaseQueueBackend::claimDaily()
 ✘ Claim daily returns rows across multiple users
   │ Error: Call to undefined method Drupal\do_notifications\Queue\DatabaseQueueBackend::claimDaily()
 ✘ Delete by ids removes only specified rows
   │ Error: Call to undefined method Drupal\do_notifications\Queue\DatabaseQueueBackend::deleteByIds()
 ✘ Delete by ids with empty array is no op
   │ Error: Call to undefined method Drupal\do_notifications\Queue\DatabaseQueueBackend::deleteByIds()

Digest Queue Backend (Drupal\Tests\do_notifications\Kernel\DigestQueueBackend)
 ✘ Enqueue persists row and returns id
   │ LogicException: do_notifications module does not define a schema for table 'do_notifications_digest_queue'.
 ✘ Multiple enqueues persist distinct rows
   │ LogicException: do_notifications module does not define a schema for table 'do_notifications_digest_queue'.
 ✘ Delete by ids removes only specified rows
   │ LogicException: do_notifications module does not define a schema for table 'do_notifications_digest_queue'.
 ✘ Delete by ids with empty array is no op
   │ LogicException: do_notifications module does not define a schema for table 'do_notifications_digest_queue'.
```

Every failure is for the RIGHT reason: either the new `do_notifications_digest_queue` schema does not exist yet (`installSchema()` throws `LogicException` because F hasn't added the `hook_schema()` entry), or the new `claimDaily()`/`deleteByIds()` methods don't exist on `DatabaseQueueBackend` yet (`Error: Call to undefined method`). None fail from a test-authorship bug (no typo, no missing import, no bad fixture setup) — the 2 pre-existing `DatabaseQueueBackendTest` tests I did not modify still pass unchanged (⚠ = risky/deprecation-flagged, same as before my edits, not a regression I introduced), proving my additions didn't break the existing suite's scaffolding.

The 14 PHPUnit "deprecations" reported are pre-existing core/contrib deprecation notices (flag module `@EntityType`/`@Action` annotations, `Config::save($has_trusted_data)`, `fetchAll($mode)` int-mode) unrelated to this story's code — identical noise the pre-existing `DigestRendererTest`/`DatabaseQueueBackendTest` already trigger.

## Ready for F

Confirmed RED is valid; F may implement against these tests.

**Note on environment setup for F/T-green:** this worktree's `.ddev/config.yaml` had gone stale (pointed at a different project's name via leftover `.ddev/config.gm253.yaml` / `config.gm139.yaml` override files, inherited from copying another worktree). I removed those two stale override files and ran `ddev config --project-name gm234-daily` + `ddev start` + `ddev composer install`. `scripts/ci/assemble-config.sh` must be run via `ddev exec` (no host PHP in this environment), and phpunit needs `SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://web` (per `scripts/dev/run-kernel.sh`'s own ddev-wrapping logic) — omitting these env vars produces a misleading "no database connection" error that is NOT a valid RED signal.
