# Handoff-T-green: Phase 6 - N-1 Queue foundation + LogMailer (#229)

**Date:** 2026-07-26
**Branch:** 229-n1-queue-foundation
**Issue:** #229
**Handoff-F reviewed:** `docs/handoffs/229-n1-queue-foundation/handoff-F.md`
**Handoff-T-red:** `docs/handoffs/229-n1-queue-foundation/handoff-T-red.md`

## GREEN confirmation

Both Phase-4 target suites re-verified GREEN against F's implementation:

```
cd C:/Users/aange/Projects/_worktrees/groups-n1-queue-foundation-229
ddev exec "bash scripts/ci/assemble-config.sh"
ddev exec "SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://web php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_notifications/tests/src/Kernel/DatabaseQueueBackendTest.php web/modules/custom/do_notifications/tests/src/Kernel/LogMailerTest.php"
```
```
Database Queue Backend (Drupal\Tests\do_notifications\Kernel\DatabaseQueueBackend)
 ⚠ Enqueue drain count
 ⚠ Enqueue dedup on tuple

Log Mailer (Drupal\Tests\do_notifications\Kernel\LogMailer)
 ⚠ Deliver writes watchdog and file

OK, but there were issues!
Tests: 3, Assertions: 26, Deprecations: 7.
```
(⚠ = deprecation notice only, all 3 tests PASS — matches F's self-check exactly.)

**Spot-check that tests still fail if behavior is removed:** already proven in the RED handoff —
`DatabaseQueueBackendTest` failed with `LogicException: do_notifications module does not define a
schema for table 'do_notifications_queue'` before `do_notifications.install` existed, and
`LogMailerTest` failed with `PluginNotFoundException: The "log_mailer" plugin does not exist`
before the plugin existed. Neither test's assertions changed between RED and GREEN — only F's
production code changed — so the RED→GREEN transition is attributable to F's implementation, not
a loosened test.

## Regression fixed (T-owned)

F's handoff flagged that `SubscriptionRouterTest.php` (a pre-existing N-2/#230 file) broke when
the queue swap landed: its `setUp()` never called `installSchema('do_notifications',
['do_notifications_queue'])`, which was fine when `do_notifications.queue` resolved to the
in-memory `MockQueueBackend` but fails now that it resolves to the real DB-backed
`DatabaseQueueBackend`. `KernelTestBase::enableModules()` does not run `hook_schema()` — F's
diagnosis was correct and is exactly why this is a T-owned test fix, not an F production-code fix.

**Fix applied** to `docs/groups/modules/do_notifications/tests/src/Kernel/SubscriptionRouterTest.php`:

```diff
     $this->installSchema('flag', ['flag_counts']);
     $this->installSchema('node', ['node_access']);
+    // N-1 (#229) swapped do_notifications.queue to a real DB-backed
+    // implementation (DatabaseQueueBackend); this suite calls the queue's
+    // drain()/count() directly, so the table must exist.
+    $this->installSchema('do_notifications', ['do_notifications_queue']);
     $this->installConfig(['field']);
```

This is the only change; no other legacy test needed the same fix (confirmed — see Tier 2 §
"Files/suites checked for the same root cause" below). All 5 of `SubscriptionRouterTest`'s
methods were affected and all 5 now pass.

**Self-inflicted hiccup, caught and fixed:** the initial edit script wrote CRLF line endings into
the file (rest of the file is LF), which phpcs's Drupal standard flags. Normalized to LF before
re-assembling; confirmed clean afterward.

## Tier 1 results

| Check | Command | Expected | Actual | Result |
|---|---|---|---|---|
| Assemble | `ddev exec "bash scripts/ci/assemble-config.sh"` | exit 0 | exit 0, 139 config files + 16 modules copied | PASS |
| Target suite GREEN | phpunit `DatabaseQueueBackendTest.php LogMailerTest.php` | 3/3 pass | 3/3 pass, 26 assertions, 7 deprecations (pre-existing noise) | PASS |
| phpcs on fixed test file | `phpcs --standard=Drupal,DrupalPractice SubscriptionRouterTest.php` | 0 errors | 0 errors after LF normalization (1 CRLF error caught+fixed first) | PASS |

## Tier 2 results

**Full `do_notifications` kernel suite (explicit path):**
```
ddev exec "SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://web php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_notifications/tests/src/Kernel/"
```
```
 ⚠ Enqueue drain count
 ⚠ Enqueue dedup on tuple
 ⚠ Renders all six event types
 ⚠ Missing referenced entity renders removed
 ✔ Node insert records node created event
 ✔ Non eligible node insert records nothing
 ✔ Add to group after creation records event
 ✔ Add node fixture records content and group events
 ✔ Add member records no added to group event
 ✔ Get group ids resolves v 4 relationship type ids
 ⚠ Deliver writes watchdog and file
 ⚠ Router dedups across follow flags
 ✔ Author never enqueued
 ✔ Mute group suppresses
 ✔ Per post state suppression
 ✔ No subscribers produces zero
OK, but there were issues!
Tests: 16, Assertions: 582, Deprecations: 20.
```
16/16 tests pass (⚠ = deprecation-only, not failure). All 5 `SubscriptionRouterTest` methods
("Router dedups across follow flags", "Author never enqueued", "Mute group suppresses", "Per post
state suppression", "No subscribers produces zero") now pass — the pre-existing regression is
resolved. **Result: PASS.**

**Discovery-based invocation (sanity check, matches CI's actual wrapper pattern):**
```
ddev exec "... --testdox $(find web/modules/custom/do_notifications -type d -path '*/tests/src/Kernel')"
```
Identical output: 16/16, same 20 deprecations, 0 errors. **Result: PASS** (confirms no path-
resolution discrepancy between the explicit-file and discovery invocations).

**Cross-module sanity — `do_activity` + `do_activity_feed` kernel suites:**
```
ddev exec "... --testdox web/modules/custom/do_activity/tests/src/Kernel/ web/modules/custom/do_activity_feed/tests/src/Kernel/"
```
42/42 tests pass, 0 errors, exit 0 (deprecations only — pre-existing Twig/message/flag.tokens.inc
core-deprecation noise, unrelated to this story). Confirmed these modules use Drupal core's own
`@queue` service, not `do_notifications.queue` — the N-1 swap does not touch their code paths, and
the GREEN result confirms no incidental breakage. **Result: PASS.**

**Files/suites checked for the same root cause (only one hit):** grepped every kernel test file
under `docs/groups/modules/do_notifications/tests/src/Kernel/` for references to
`do_notifications.queue` or existing `installSchema('do_notifications', ...)` calls.
`DatabaseQueueBackendTest.php` (T's own new file) already had the call. `SubscriptionRouterTest.php`
was the only other consumer and the only one missing it — no other file needed the fix.

**Test coverage vs. acceptance criteria:** all 8 checkboxes in the brief map to a passing test —
see below.

**Test quality spot-check (test-quality.md §7):**
- `DatabaseQueueBackendTest::testEnqueueDrainCount` / `testEnqueueDedupOnTuple` — each names one
  behavior, asserts persistence/dedup via a raw DB count (not just the backend's own `count()`,
  which would be implementation-circular), kernel tier is the cheapest tier that can prove a real
  unique-key constraint. No duplication between the two tests (drain/count vs. dedup are distinct
  behaviors).
- `LogMailerTest::testDeliverWritesWatchdogAndFile` — single test, asserts three independent
  observable effects of one `deliver()` contract (watchdog row, file line, return value) plus the
  plugin-discovery precondition (`@Notifier` annotation registration is itself part of the
  acceptance criterion) — proportionate; splitting into three tests would triplicate the expensive
  kernel bootstrap for no isolation gain since all three assertions share one `deliver()` call.
- `SubscriptionRouterTest`'s one-line fix does not touch any assertion — it only makes an
  already-authored, already-correct test able to run against the real (not mock) queue backend.
  No new test debt introduced.
- No redundant or invalid tests found; nothing flagged for deletion.

**Type safety / error handling / data integrity / API contract / security:** no findings beyond
what F's own handoff documents (constructor-injected typed services throughout, `merge()`-based
upsert avoids exception-as-control-flow, transaction-wrapped drain with explicit rollback). No new
input-validation surface in this story (internal service-to-service calls only, no
controller/form/routing layer touched, confirmed against F's "Layers touched" note).

**Migration safety:** new table only (`do_notifications_queue`), no existing schema altered — no
reversibility concern for a purely additive `hook_schema()`.

**Playwright / UI:** N/A — no UI surface in this story (backend service + plugin foundation only,
confirmed by F's handoff and the brief's own "no wireframe" note).

## Acceptance criteria status

| # | Criterion | Status | Backing test |
|---|---|---|---|
| 1 | `DatabaseQueueBackend` implements `QueueBackendInterface`, signatures identical | PASS | `DatabaseQueueBackendTest` (both methods exercise `enqueue`/`drain`/`count` through the interface) |
| 2 | `do_notifications.services.yml` swaps class + injects `@database` | PASS | Verified by inspection + `DatabaseQueueBackendTest` resolving the real backend via `$this->container->get('do_notifications.queue')` |
| 3 | `do_notifications.install` creates `do_notifications_queue` with unique key | PASS | `testEnqueueDedupOnTuple` (second identical-tuple `enqueue()` is silently dropped — proves the unique key is live) |
| 4 | `LogMailer` is a `@Notifier` plugin registered under `Plugin/Notifier/` | PASS | `LogMailerTest` resolves it via `plugin.message_notify.notifier.manager` by id `log_mailer` |
| 5 | Kernel test: enqueue 3 → count=3, drain returns 3, DB empty; LogMailer deliver×3 → 3 watchdog + 3 file lines | PASS | `testEnqueueDrainCount` + `testDeliverWritesWatchdogAndFile` |
| 6 | Dedup test: enqueue same tuple twice → count=1, drain returns 1 | PASS | `testEnqueueDedupOnTuple` |
| 7 | `phpcs` clean on all new/changed files | PASS | F's self-check (0 errors on `DatabaseQueueBackend.php`, `LogMailer.php`, `do_notifications.install`, `MockQueueBackend.php`) + T's re-check on the fixed `SubscriptionRouterTest.php` (0 errors after LF normalization) |
| 8 | (Non-goal guard) No retry column/claim/release/cron worker added | PASS | Confirmed by inspection — `DatabaseQueueBackend` matches `QueueBackendInterface`'s trimmed signature exactly, no extra methods |

## Blocking issues

None.

## Advisory notes

- The CRLF-introduction-then-fix on `SubscriptionRouterTest.php` was a tooling artifact of the
  editing method used (a Python script defaulting to platform line endings on Windows), not a
  project or F issue — flagging only so a future T knows to verify line endings after any
  non-Edit-tool text substitution on this repo's PHP files.
- F's root-cause analysis in `handoff-F.md` ("Tests that look wrong") was accurate and saved
  investigation time — the one-line fix it suggested was applied verbatim.
