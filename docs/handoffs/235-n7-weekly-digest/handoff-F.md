# Handoff-F: Phase 5 - N-7 Weekly digest worker

**Date:** 2026-07-26
**Branch:** 235-n7-weekly-digest
**Issue:** #235

## What was done

- `docs/groups/modules/do_notifications/src/Queue/QueueBackendInterface.php` — added
  `claimWeekly(int $olderThan): array` (literal sibling of `claimDaily()`); added a parallel
  "#235 (N-7)" paragraph to the class docblock alongside the existing "#234 (N-6)" one.
- `docs/groups/modules/do_notifications/src/Queue/DatabaseQueueBackend.php` — added
  `claimWeekly()`, a literal copy of `claimDaily()` with `->condition('frequency', 'weekly')` in
  place of `'daily'`. Updated class docblock to mention the addition.
- `docs/groups/modules/do_notifications/src/Queue/MockQueueBackend.php` — added `claimWeekly()`,
  a literal in-memory copy of `claimDaily()` filtering on `$item['frequency'] === 'weekly'`.
  Updated class docblock to mention the addition.
- `docs/groups/modules/do_notifications/src/Commands/DigestCommands.php` — added public
  `digestWeekly(): array` (new `#[Command(name: 'do_notifications:digest-weekly')]`-attributed
  method on the EXISTING class) and private `digestUserWeekly()` helper (structural mirror of
  `digestUser()`, including the `setCreatedTime((int) ...)` normalization A flagged). Added
  `DEFAULT_WEEKLY_WINDOW_SECONDS = 604800` class constant alongside the existing
  `DEFAULT_WINDOW_SECONDS = 86400`. Updated the class docblock (title, description, non-goals)
  to describe both daily and weekly as implemented siblings.

## Design decisions

- **`digestUserWeekly()` is a literal structural copy of `digestUser()`**, not a call to a
  shared helper with a `$window` parameter. Considered factoring the two into one
  `digestUserForWindow(string $window, ...)` method, but the brief's Reuse map explicitly names
  "private `digestUserWeekly()` helper" as the authorized shape (mirroring the daily
  `digestDaily()`/`digestUser()` pair), and A's Phase 3 review signed off on that exact shape —
  a parameterized merge would be an unplanned refactor of code the brief didn't ask F to touch.
  Kept the duplication (2 near-identical ~70-line private methods) rather than introduce an
  un-spec'd abstraction.
- **`setCreatedTime((int) $message->getCreatedTime())` normalization copied verbatim** into
  `digestUserWeekly()`, per A's Phase 3 finding #3. Without it, a genuinely storage-reloaded
  Message's `created` value can surface as a numeric string, and
  `DigestRenderer` → `EmailRenderer::renderEventFragments()` → `gmdate()` throws a TypeError on a
  string argument. Confirmed this matters in practice: all 4 `WeeklyDigestCommandTest` scenarios
  load Messages via `$message_storage->loadMultiple($mids)` (a real storage round-trip), so
  omitting the normalization would have failed the GREEN run with a TypeError, not merely been
  theoretically risky.
- **`DEFAULT_WEEKLY_WINDOW_SECONDS` as a distinct constant**, not a parameter to a shared
  "default window" constant/method. Symmetric with how the daily path already names its own
  `DEFAULT_WINDOW_SECONDS`; a shared "get default window for X" abstraction was not asked for
  and would be unplanned scope creep for a 1-line constant.
- **No changes to `do_notifications.services.yml` / `drush.services.yml`.** Confirmed (per A's
  finding #6) that Drush 12+ attribute commands scan a class's methods for `#[Command]`
  attributes, so a second attributed method on the already-registered `DigestCommands` class
  surfaces automatically — no new/changed service registration needed.

## Reuse / extend-vs-new

Extended exactly the 4 objects the brief's Reuse map named, with no new classes/interfaces/tables:

| Object | Action taken |
|---|---|
| `QueueBackendInterface` | EXTENDED — added `claimWeekly()` |
| `DatabaseQueueBackend` | EXTENDED — added `claimWeekly()` impl |
| `MockQueueBackend` | EXTENDED — added `claimWeekly()` impl |
| `DigestCommands` | EXTENDED — added `digestWeekly()` public method + `digestUserWeekly()` private helper |

`DigestQueueBackendInterface`, `DatabaseDigestQueueBackend`, the digest queue schema, and
`DigestRenderer` were reused verbatim with zero changes, exactly as the brief specified ("NO
changes to" list). No `WeeklyDigestCommands` class, no `WeeklyDigestQueueBackend`, no
`digest_queue_weekly` table were created — the brief's named anti-pattern was not committed.

## Architecture notes for A

- No new dependencies, no schema/contract changes, no shared-component edits.
- `DigestCommands`' constructor signature is unchanged (same 6 injected services `digestDaily()`
  already used) — `digestWeekly()` needed no new dependency.
- The only cross-cutting surface touched is `QueueBackendInterface` (a contract both
  `DatabaseQueueBackend` and `MockQueueBackend` implement) — both implementations were updated in
  the same pass so the interface never has a stray unimplemented method.
- Docblocks on `QueueBackendInterface` and `DigestCommands` were updated to describe the current
  (post-N-7) state rather than leaving stale "future story" language pointing at N-7 as
  not-yet-built.

## Deviations from spec / wireframe

None. No UI surface (drush command + kernel-testable service only, per the brief).

## Tier 1 self-check (incl. tests now GREEN)

**Scoped run (the 3 classes named in the task):**

```
ddev exec bash scripts/ci/assemble-config.sh
ddev exec bash -c "SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://web \
  php vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  web/modules/custom/do_notifications/tests/src/Kernel/WeeklyDigestCommandTest.php \
  web/modules/custom/do_notifications/tests/src/Kernel/DatabaseQueueBackendTest.php \
  web/modules/custom/do_notifications/tests/src/Kernel/DailyDigestCommandTest.php \
  --testdox"
```

```
DDDDDDDDDDDDDDD                                                   15 / 15 (100%)

Daily Digest Command (Drupal\Tests\do_notifications\Kernel\DailyDigestCommand)
 ⚠ Digest daily aggregates per user and consumes source rows
 ⚠ Window seconds state override narrows window
 ⚠ Orphaned mid is dropped and deleted without blocking other messages
 ⚠ User with no in window items produces no digest row

Database Queue Backend (Drupal\Tests\do_notifications\Kernel\DatabaseQueueBackend)
 ⚠ Enqueue drain count
 ⚠ Enqueue dedup on tuple
 ⚠ Claim daily filters on frequency and threshold
 ⚠ Claim weekly filters on frequency and threshold
 ⚠ Claim daily returns rows across multiple users
 ⚠ Delete by ids removes only specified rows
 ✔ Delete by ids with empty array is no op

Weekly Digest Command (Drupal\Tests\do_notifications\Kernel\WeeklyDigestCommand)
 ⚠ Digest weekly aggregates per user and consumes source rows
 ⚠ Window seconds state override narrows window
 ⚠ Orphaned mid is dropped and deleted without blocking other messages
 ⚠ User with no in window items produces no digest row

OK, but there were issues!
Tests: 15, Assertions: 323, Deprecations: 20.
```

All 5 previously-RED tests (4 `WeeklyDigestCommandTest` + `testClaimWeeklyFiltersOnFrequencyAndThreshold`
in `DatabaseQueueBackendTest`) now pass. All 10 previously-passing tests (6
`DatabaseQueueBackendTest` + 4 `DailyDigestCommandTest`, the regression check) still pass. Zero
failures, zero errors — `⚠` marks are the same pre-existing core/contrib deprecation noise (Twig
extension signature warnings, `flag`/`message` module `.tokens.inc` hook-autoloading deprecations,
`template_preprocess_message()` deprecation) T's RED evidence already documented as identical for
`DailyDigestCommandTest`, not new failures introduced by this change.

**Broader sanity check — full module suite (all 10 `do_notifications` kernel test classes, not
just the 3 named):**

```
ddev exec bash -c "SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://web \
  php vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  web/modules/custom/do_notifications/tests/src/Kernel --testdox"
```

```
DDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDD                50 / 50 (100%)
OK, but there were issues!
Tests: 50, Assertions: 1589, Deprecations: 22.
```

Zero failures/errors across the entire module — no regression anywhere in `do_notifications`.

**phpcs:**

The task's literal command (`ddev exec php vendor/bin/phpcs docs/groups/modules/do_notifications/src
docs/groups/modules/do_notifications/tests`, no `--standard` flag) does **not** exit 0 — it exits
2, reporting ~5700 errors across every file in the module, including files this story never
touched. Root cause: the project has **no root-level `phpcs.xml`/`phpcs.xml.dist` ruleset**
(confirmed absent both in the worktree and inside the DDEV container), so bare `phpcs` falls back
to its own built-in `PEAR` default rather than the `drupal/coder`-installed `Drupal` standard this
codebase's style clearly follows throughout (verified: `drupal/coder` 8.3.31 is installed and
`phpcs -i` lists `Drupal` as an available standard).

Re-running with `--standard=Drupal` explicit:

```
ddev exec php vendor/bin/phpcs --standard=Drupal docs/groups/modules/do_notifications/src docs/groups/modules/do_notifications/tests --report=summary
```

narrows this to **33 errors / 19 warnings across 10 files** — all either T's authored test files
(`DatabaseQueueBackendTest.php`: 7 errors/1 warning, `WeeklyDigestCommandTest.php`: 5 errors/1
warning, plus 5 other pre-existing test files this story never touched) or 2 pre-existing
production files F never modified (`NotificationSettingsController.php`: 3 errors,
`DoNotificationsHooks.php`: 1 error/7 warnings).

**Scoped to only the 4 files this F pass created/modified:**

```
ddev exec php vendor/bin/phpcs --standard=Drupal \
  docs/groups/modules/do_notifications/src/Commands/DigestCommands.php \
  docs/groups/modules/do_notifications/src/Queue/QueueBackendInterface.php \
  docs/groups/modules/do_notifications/src/Queue/DatabaseQueueBackend.php \
  docs/groups/modules/do_notifications/src/Queue/MockQueueBackend.php
```

Exit code: **0**. Zero errors, zero warnings.

This is an environment/config gap (missing project-root phpcs ruleset — no CI script or
`composer.json` script pins `--standard` either), not a defect this story introduced. Not fixed
here since adding a project-wide ruleset file is unrelated to and out of scope for this story's
Reuse map. Flagging for O/A visibility rather than silently declaring "phpcs passes" when the
literal command as specified does not exit 0.

## Tests that look wrong (for T)

None. All 5 authored tests are correct against the brief and A's approved plan; all now pass.

## Known issues

None against this story's acceptance criteria (AC1–AC10 all covered by the 4
`WeeklyDigestCommandTest` scenarios + the `claimWeekly()` filter test, all GREEN). See the phpcs
note above for a pre-existing, out-of-scope environment gap (no project-root ruleset file) that
is not a regression from this change.

## Files changed

- `docs/groups/modules/do_notifications/src/Queue/QueueBackendInterface.php`
- `docs/groups/modules/do_notifications/src/Queue/DatabaseQueueBackend.php`
- `docs/groups/modules/do_notifications/src/Queue/MockQueueBackend.php`
- `docs/groups/modules/do_notifications/src/Commands/DigestCommands.php`

(No test files changed by F — `WeeklyDigestCommandTest.php` and the extended
`DatabaseQueueBackendTest.php` are T's, authored in Phase 4.)
