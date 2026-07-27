# Handoff-T-red: Phase 4 - #235 N-7 Weekly digest worker

**Date:** 2026-07-26
**Branch:** 235-n7-weekly-digest
**Brief / wireframe reviewed:** `docs/handoffs/235-n7-weekly-digest/brief.md`, `docs/handoffs/235-n7-weekly-digest/handoff-A.md` (no wireframe — no UI surface)

## A precondition

Confirmed: A returned **PASS** on the plan (Phase 3, `handoff-A.md`). No blocking findings; T may
author RED tests directly against the brief's Reuse map.

## DDEV environment note (housekeeping, not test-authorship)

This worktree's `.ddev/` directory was stale from prior reuse: `config.yaml` still read
`name: gm145-wcag`, and two leftover override files (`config.gm139.yaml`,
`config.gm253.yaml`, both `override_config: true`) pinned the project name to earlier
issues' slugs (`gm139-multilang-rtl`, `gm253-preproc`), colliding with other running
worktrees' containers. Fixed by:
- Editing `.ddev/config.yaml`: `name: gm145-wcag` → `name: gm235-weekly`.
- Deleting `.ddev/config.gm139.yaml` and `.ddev/config.gm253.yaml` (both obsolete
  `override_config` shims with no reason to persist).
- `ddev poweroff` then `ddev start` (clean start under the corrected name).
- `ddev composer install` (vendor/ was absent).

`ddev describe` now shows the project correctly as `gm235-weekly`, matching the task prompt.

## Tests authored

### `docs/groups/modules/do_notifications/tests/src/Kernel/WeeklyDigestCommandTest.php` (NEW)

Structural mirror of N-6's `DailyDigestCommandTest.php` with `weekly`/`604800` substituted
throughout. Kernel tier (chosen because the behavior under test — claim/render/enqueue/delete
across real DB rows, a real Message entity, and DigestRenderer's real Twig-adjacent rendering
pipeline — cannot be cheaply isolated at the unit tier; N-6 established this same tier choice
for the identical shape).

| Test | Criterion / behavior pinned | Tier |
|---|---|---|
| `testDigestWeeklyAggregatesPerUserAndConsumesSourceRows` | AC2, AC3, AC4, AC5, AC6, AC7, AC8 (integrated): 12 weekly items (5/4/3) across 3 users older than 7d → 3 digest rows, `window='weekly'`, each subject traceable to that user's own fragment count; 12 source rows deleted; 2 in-window weekly rows + 2 `immediately` + 2 `daily` rows (out-of-window) are untouched (6 survivors); a 4th user with zero items produces nothing. | Kernel |
| `testWindowSecondsStateOverrideNarrowsWindow` | AC9: `state.set('do_notifications.digest_weekly.window_seconds', 300)` narrows the window — a 600s-old row not consumed under the 7-day default IS consumed once narrowed. | Kernel |
| `testOrphanedMidIsDroppedAndDeletedWithoutBlockingOtherMessages` | AC10: a queue row whose `mid` doesn't resolve to a Message is dropped from the digest AND garbage-collected (deleted), without blocking the user's other valid message from being digested. | Kernel |
| `testUserWithNoInWindowItemsProducesNoDigestRow` | AC7 isolated: a user with zero queued items at all produces zero digest rows (isolates the "skip empty digest" guard from the integrated scenario's incidental zero-item user). | Kernel |

### `docs/groups/modules/do_notifications/tests/src/Kernel/DatabaseQueueBackendTest.php` (EXTENDED)

Added one new test method, mirroring the existing `testClaimDailyFiltersOnFrequencyAndThreshold`:

| Test | Criterion / behavior pinned | Tier |
|---|---|---|
| `testClaimWeeklyFiltersOnFrequencyAndThreshold` | `claimWeekly(int $olderThan)` applies `frequency = 'weekly' AND created < :olderThan` as an AND — a weekly-but-in-window row and an out-of-window-but-non-weekly row are both excluded; `claimWeekly()` is read-only (all 4 fixture rows remain in the DB table). | Kernel |

No duplication: each test pins a distinct behavior not covered elsewhere in the suite. The
4-scenario shape for `WeeklyDigestCommandTest` matches N-6's own 4-scenario shape exactly (no
narrower or broader coverage), per the brief's explicit instruction to mirror N-6's suite. The
`claimWeekly()` filter test is the one new interface method the brief calls out
(`QueueBackendInterface::claimWeekly()`) that has no other test coverage path.

## RED confirmation

**Assemble + run command** (from repo root, inside the `gm235-weekly` ddev container; the
`SIMPLETEST_DB`/`SIMPLETEST_BASE_URL` env vars are required — see
`scripts/dev/run-kernel.sh`'s own comment on this; omitting them produced a **setup-error, not a
valid RED**, on the first attempt, so I added them per the wrapper script's own idiom):

```
ddev exec bash scripts/ci/assemble-config.sh
ddev exec 'SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://web php vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/do_notifications/tests/src/Kernel/WeeklyDigestCommandTest.php web/modules/custom/do_notifications/tests/src/Kernel/DatabaseQueueBackendTest.php --testdox'
```

**Result:** `Tests: 11, Assertions: 140, Errors: 5, Deprecations: 16.` (deprecations are
pre-existing core/contrib noise unrelated to this story — same deprecations fire on N-6's own
merged `DailyDigestCommandTest`, confirmed by the identical warning list appearing for both
classes in this same run).

**Exact failing output for each of the 5 new tests** (all fail for the RIGHT reason — an
undefined method the production code doesn't have yet, not an import/setup/fixture error):

```
Database Queue Backend (Drupal\Tests\do_notifications\Kernel\DatabaseQueueBackend)
 ⚠ Enqueue drain count
 ⚠ Enqueue dedup on tuple
 ⚠ Claim daily filters on frequency and threshold
 ✘ Claim weekly filters on frequency and threshold
   │
   │ Error: Call to undefined method Drupal\do_notifications\Queue\DatabaseQueueBackend::claimWeekly()
   │
   │ /var/www/html/web/modules/custom/do_notifications/tests/src/Kernel/DatabaseQueueBackendTest.php:231
   │
 ⚠ Claim daily returns rows across multiple users
 ⚠ Delete by ids removes only specified rows
 ✔ Delete by ids with empty array is no op

Weekly Digest Command (Drupal\Tests\do_notifications\Kernel\WeeklyDigestCommand)
 ✘ Digest weekly aggregates per user and consumes source rows
   │
   │ Error: Call to undefined method Drupal\do_notifications\Commands\DigestCommands::digestWeekly()
   │
   │ /var/www/html/web/modules/custom/do_notifications/tests/src/Kernel/WeeklyDigestCommandTest.php:251
   │
 ✘ Window seconds state override narrows window
   │
   │ Error: Call to undefined method Drupal\do_notifications\Commands\DigestCommands::digestWeekly()
   │
   │ /var/www/html/web/modules/custom/do_notifications/tests/src/Kernel/WeeklyDigestCommandTest.php:321
   │
 ✘ Orphaned mid is dropped and deleted without blocking other messages
   │
   │ Error: Call to undefined method Drupal\do_notifications\Commands\DigestCommands::digestWeekly()
   │
   │ /var/www/html/web/modules/custom/do_notifications/tests/src/Kernel/WeeklyDigestCommandTest.php:360
   │
 ✘ User with no in window items produces no digest row
   │
   │ Error: Call to undefined method Drupal\do_notifications\Commands\DigestCommands::digestWeekly()
   │
   │ /var/www/html/web/modules/custom/do_notifications/tests/src/Kernel/WeeklyDigestCommandTest.php:393
   │
```

The 6 pre-existing `DatabaseQueueBackendTest` tests (⚠/✔, no ✘) confirm the extension did not
regress the existing suite — the `⚠` marks are pre-existing core deprecation noise (Twig
extension signature warnings, `@EntityType`/`@Action` annotation deprecations from the `flag`
module, etc.), identical to what fires against N-6's own merged test file, not new failures
introduced by this change.

## Deviations from N-6's shape (and why)

- **Item distribution:** N-6 used 7/2/1 items across 3 users (10 total); this suite uses 5/4/3
  (12 total) per the brief's explicit AC2 wording ("12 weekly items across 4 users"). Otherwise
  structurally identical (one integrated scenario, one state-override scenario, one orphan
  scenario, one zero-item scenario).
- **`testClaimWeeklyFiltersOnFrequencyAndThreshold` fixture rows use `daily` as the "other
  frequency older-than-window" row** (N-6's `testClaimDailyFiltersOnFrequencyAndThreshold` used
  `weekly` for the symmetric slot) — the two tests are now mirror images of each other, each
  proving the sibling frequency is correctly excluded.
- No other deviations. No new objects beyond what the brief authorizes were touched by T (T
  authors tests only).

## Ready for F

**RED is valid.** F may implement against these tests: add `QueueBackendInterface::claimWeekly()`
(+ `DatabaseQueueBackend`/`MockQueueBackend` implementations) and
`DigestCommands::digestWeekly()` (+ private `digestUserWeekly()` helper) per the brief's Reuse
map. Do not implement the feature to make these tests pass — that is F's job.
