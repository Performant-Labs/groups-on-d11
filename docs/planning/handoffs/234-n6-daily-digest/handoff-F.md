# Handoff-F: Phase 5 - #234 N-6 Daily digest worker

**Date:** 2026-07-26
**Branch:** 234-n6-daily-digest
**Issue:** #234

## What was done

- `docs/groups/modules/do_notifications/do_notifications.install` (modified) — added the
  `do_notifications_digest_queue` table to `do_notifications_schema()` (serial PK `id`; unsigned
  int `uid`; varchar(16) `window`; varchar(255) `subject`; big text `body_text`/`body_html`;
  unsigned int `send_at`/`created`; index on `send_at` for the future N-3 "find due digests"
  query). Added `do_notifications_update_11001()` for existing sites (D11 major-numbered update
  hook, per A's warn #1 — creates the table via `Connection::schema()->createTable()` if it
  doesn't already exist).
- `docs/groups/modules/do_notifications/src/Queue/QueueBackendInterface.php` (modified) — added
  `claimDaily(int $olderThan): array` (returns `frequency='daily' AND created < $olderThan` rows,
  including `id`, ordered by uid/created; read-only) and `deleteByIds(array $ids): void`
  (deletes by id; empty array is a safe no-op).
- `docs/groups/modules/do_notifications/src/Queue/DatabaseQueueBackend.php` (modified) —
  implemented both new methods against the real `do_notifications_queue` table.
- `docs/groups/modules/do_notifications/src/Queue/MockQueueBackend.php` (modified) — implemented
  both new methods as in-memory equivalents (added a synthetic sequential id so `claimDaily()` can
  return real ids and `deleteByIds()` can remove specific entries — the pre-existing mock had no id
  concept since `drain()`/`count()` never needed one).
- `docs/groups/modules/do_notifications/src/Queue/DigestQueueBackendInterface.php` (NEW) — narrow
  interface: `enqueue(int $uid, string $window, string $subject, string $bodyText, string
  $bodyHtml, int $sendAt): int`, `all(): array` (read-only), `deleteByIds(array $ids): void`,
  `count(): int`. No `drain()`, per A's warn #3.
- `docs/groups/modules/do_notifications/src/Queue/DatabaseDigestQueueBackend.php` (NEW) — DB-backed
  implementation. Plain `INSERT` (no dedup/merge — a digest row has no unique tuple to collapse
  on, unlike the per-message queue).
- `docs/groups/modules/do_notifications/src/Commands/DigestCommands.php` (NEW) — the
  `do_notifications:digest-daily` command. `#[Command(name: 'do_notifications:digest-daily')]`
  (Drush's "annotated command" method-level attribute, not the newer class-level `#[AsCommand]`
  style). Constructor injects `queue`, `digest_queue`, `digest_renderer`, `entity_type.manager`,
  `state`, `datetime.time`. `digestDaily(): array` reads `state.get('do_notifications.digest_daily.window_seconds',
  86400)`, computes `threshold = now - windowSeconds`, calls `queue->claimDaily($threshold)`,
  groups rows by uid, and for each uid: loads Messages via `entity_type.manager` message storage
  `loadMultiple()`, drops (and marks for deletion) any row whose `mid` doesn't resolve, skips the
  user entirely if zero valid messages remain (no empty-digest email, but orphan rows are still
  garbage-collected), otherwise loads the recipient user, calls
  `DigestRenderer::render($validMessages, $user, 'daily')` verbatim, enqueues one row into the
  digest queue (`send_at = now`), and returns `['users_digested', 'items_consumed',
  'digests_enqueued']` — `items_consumed` counts every originally-claimed row deleted (including
  orphans), `users_digested`/`digests_enqueued` only count users who actually received a digest.
  All consumed+orphan ids are deleted via one `queue->deleteByIds()` call at the end of
  `digestDaily()`, after every user's digest render loop completes.
- `docs/groups/modules/do_notifications/do_notifications.services.yml` (modified) — registered
  `do_notifications.digest_queue` (`DatabaseDigestQueueBackend`) and `do_notifications.digest_command`
  (`DigestCommands`) — see "Deviations" below for why the command is registered here in addition to
  `drush.services.yml`.
- `docs/groups/modules/do_notifications/drush.services.yml` (NEW) — registers
  `do_notifications.digest_command` again, tagged `drush.command`, for Drush's own CLI
  command-discovery mechanism (a separate registry from Drupal's container — see "Deviations").

## Design decisions

- **Update hook number `11001`, not `9001`.** Per A's warn #1: this module targets D11, and no
  prior update hook existed (this is the first), so `do_notifications_update_11001()` is correct
  D11 major-numbered convention.
- **Digest queue interface: `all()` + `deleteByIds()`, no `drain()`.** Per A's warn #3 and T-red's
  lock: N-3 (the future delivery worker) needs the same claim-then-delete idempotency the
  per-message queue's `claimDaily()`/`deleteByIds()` split provides — a combined read-and-delete
  `drain()` would force N-3 into an all-or-nothing send, losing rows on partial delivery failure.
- **Orphaned `mid` rows are garbage-collected, not retried.** Per A's finding + T-red's lock
  (`testOrphanedMidIsDroppedAndDeletedWithoutBlockingOtherMessages`): a queue row whose Message no
  longer loads is dropped from the digest AND its row is deleted immediately, rather than left to
  accumulate forever waiting for a Message that will never come back.
- **`items_consumed` counts orphans too.** Per this phase's task prompt's explicit clarification
  ("items_consumed = count of ORIGINAL queue rows deleted incl. orphans"): the 2-row orphan test
  scenario deletes both the valid row and the orphan row, and both count toward the rows physically
  removed from `do_notifications_queue`, even though only 1 message was actually digested.
- **`MockQueueBackend` gained a synthetic sequential id.** The pre-existing mock had no id concept
  at all (its `$items` array was a plain enqueue-order list) since `drain()`/`count()` never needed
  one. `claimDaily()` needs to return each row's `id` (matching `DatabaseQueueBackend`'s real
  auto-increment PK) so a caller can round-trip it into `deleteByIds()` — added a private `$nextId`
  counter and keyed `$items` by that id instead of by sequential array index.

## Reuse / extend-vs-new

Per the brief's Reuse map:
- **Extended** `QueueBackendInterface` / `DatabaseQueueBackend` / `MockQueueBackend` (2 new methods
  each) — no parallel path created for the per-message queue.
- **Reused `DigestRenderer::render()` verbatim** — `DigestCommands` calls it with zero
  modification, exactly as the brief and A both required. No token/render/aggregation logic
  duplicated.
- **New, brief-justified:** `DigestQueueBackendInterface` + `DatabaseDigestQueueBackend` — the
  brief's and A's written justification (survey.md + handoff-A.md) is that a digest row has no
  `mid` and carries a fully rendered payload, which the per-message queue's `(uid, mid, frequency,
  day)` dedup contract cannot express; a new table avoids colliding with N-5 PR #261's pending
  schema work on `do_notifications_queue`. A explicitly approved this as PASS.
- **New, brief-justified:** `DigestCommands` — no prior Drush command class existed in this module
  (`ls src/Commands` confirmed empty in survey.md).

## Architecture notes for A

- **Layers touched:** module install schema (`.install`, new table + update hook), two service
  classes (`Queue/DatabaseDigestQueueBackend.php` new, `Queue/DatabaseQueueBackend.php` extended),
  one mock/test-helper class (`Queue/MockQueueBackend.php` extended), two interfaces
  (`Queue/QueueBackendInterface.php` extended, `Queue/DigestQueueBackendInterface.php` new), one
  command class (`Commands/DigestCommands.php` new), DI wiring (`do_notifications.services.yml`
  modified, `drush.services.yml` new). No controller/form/routing/config-entity layer touched.
- **New DB table:** `do_notifications_digest_queue` (see `.install` above). No existing table
  altered — `do_notifications_queue`'s schema is untouched, leaving N-5 PR #261's pending column
  work unblocked (per the brief's explicit non-goal).
- **New dependency surface:** none new at the `.info.yml` level — `DigestCommands` only depends on
  services already present in this module or Drupal core (`entity_type.manager`, `state`,
  `datetime.time`) plus the module's own existing `do_notifications.queue`/`digest_renderer`.
- **Interface extension:** `QueueBackendInterface` gains 2 methods (`claimDaily`, `deleteByIds`),
  implemented identically in both `DatabaseQueueBackend` and `MockQueueBackend` so any existing
  consumer of the mock (unit tests) doesn't break — verified via the full regression sweep below.
- **A real, pre-existing Drupal-core quirk surfaced by this story** (not introduced by it): a
  freshly `loadMultiple()`-loaded `Message` entity's `created` timestamp field can surface as a
  numeric string rather than an int (core's `TimestampItem`/`CreatedItem` TypedData has no int-cast
  override on read). `DigestRenderer`/`EmailRenderer` (N-4/N-8, shipped, out of this story's scope)
  call `gmdate()` on that value, which requires `?int`. Every pre-existing test of those classes
  only ever passes the SAME in-memory Message object right after `->save()`, so this was never
  exercised before. `DigestCommands` is the first real caller to hand the renderer a genuinely
  storage-reloaded Message, so I normalized it (`setCreatedTime((int) getCreatedTime())`, in-memory
  only, no `->save()`) at the boundary this story owns rather than editing the shipped N-4/N-8
  classes. **A should decide whether this normalization belongs upstream** (e.g. a
  `getCreatedTime(): int` cast fix in `EmailRenderer`/`DigestRenderer` itself, or even in core) as
  a separate follow-up — flagging for visibility per POC-lean (no GH issue filed).
- **No local patterns broken:** constructor-promoted `readonly` properties (matches
  `SubscriptionRouter`, `DatabaseQueueBackend`), `Connection::merge()`/`insert()`/`delete()`
  fluent-API idioms (matches existing `DatabaseQueueBackend`), `EntityTypeManagerInterface`
  injection with `getStorage()` calls (matches `SubscriptionRouter`).

## Deviations from spec / wireframe

No UI surface in this story (backend service + Drush command). Two deviations from the brief's/A's
literal instructions, both discovered via direct source-code verification (not preference) and
both required to make the locked test contract (and real-world `drush` usage) actually work:

1. **`DigestCommands` registered in `do_notifications.services.yml` in addition to
   `drush.services.yml`** — A's warn #2 said "ONLY `drush.services.yml`". Read
   `vendor/drush/drush/src/Runtime/LegacyServiceInstantiator.php` and `Boot/DrupalBoot8.php`
   directly: `drush.services.yml` populates a **separate**, Drush-internal service registry
   (`$instantiatedDrushServices`) that is read only by Drush's own `bootstrap()` via
   `taggedServices('drush.command')` — it is **never merged into `\Drupal::getContainer()`**.
   Confirmed empirically: with `DigestCommands` registered ONLY in `drush.services.yml`, all 4
   `DailyDigestCommandTest` tests failed with `Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException:
   You have requested a non-existent service "do_notifications.digest_command"` — T-red's own
   test calls `\Drupal::service('do_notifications.digest_command')` directly (per the task
   prompt's kernel-test guidance), which can only resolve a service that's in Drupal's real
   container. T-red's own handoff note ("this works identically regardless of which services.yml
   file registers it") is factually incorrect for this specific mechanism. Registering in BOTH
   files satisfies both: the container-resolvable copy (`do_notifications.services.yml`) is what
   the kernel test — and any future in-process caller — actually uses; the `drush.services.yml`
   copy is what makes `drush do_notifications:digest-daily` discoverable on the real command line
   (`DrupalBoot8::bootstrap()` ONLY scans `drush.services.yml`-sourced services for the
   `drush.command` tag — confirmed the same way). Drush's legacy instantiator constructs its own
   separate, stateless instance purely for CLI dispatch; both instances are stateless with
   identical constructor deps, and only one is ever invoked per process — a harmless artifact of
   the legacy mechanism, not a functional duplication.
2. **`Message::setCreatedTime((int) $message->getCreatedTime())` normalization** in
   `DigestCommands::digestUser()` — not mentioned in the brief, discovered while implementing.
   Fully explained above under "Architecture notes for A".

Both are documented in-line in the code (class docblocks in `DigestCommands.php`,
`do_notifications.services.yml`, `drush.services.yml`) and in `decisions.md`'s Phase 5 entry.

## Tier 1 self-check (incl. tests now GREEN)

**Assemble (exit 0):**
```
$ ddev exec "bash scripts/ci/assemble-config.sh"
==> assemble-config: repo root = /var/www/html
==> config: copied 139 file(s), excluded 7 env-specific file(s)
==> modules: copied 16 custom module(s) into web/modules/custom/
==> core.extension: registered custom do_* modules + flag/geofield/language/message/message_notify as enabled
==> assemble-config: done
```

**Target 3 test files (GREEN, verified stable across 3 separate runs, identical result every
time):**
```
$ ddev exec "cd /var/www/html && SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://web php vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/do_notifications/tests/src/Kernel/DailyDigestCommandTest.php web/modules/custom/do_notifications/tests/src/Kernel/DatabaseQueueBackendTest.php web/modules/custom/do_notifications/tests/src/Kernel/DigestQueueBackendTest.php --testdox"

Daily Digest Command (Drupal\Tests\do_notifications\Kernel\DailyDigestCommand)
 ⚠ Digest daily aggregates per user and consumes source rows
 ⚠ Window seconds state override narrows window
 ⚠ Orphaned mid is dropped and deleted without blocking other messages
 ⚠ User with no in window items produces no digest row

Database Queue Backend (Drupal\Tests\do_notifications\Kernel\DatabaseQueueBackend)
 ⚠ Enqueue drain count
 ⚠ Enqueue dedup on tuple
 ⚠ Claim daily filters on frequency and threshold
 ⚠ Claim daily returns rows across multiple users
 ⚠ Delete by ids removes only specified rows
 ✔ Delete by ids with empty array is no op

Digest Queue Backend (Drupal\Tests\do_notifications\Kernel\DigestQueueBackend)
 ⚠ Enqueue persists row and returns id
 ✔ Multiple enqueues persist distinct rows
 ⚠ Delete by ids removes only specified rows
 ✔ Delete by ids with empty array is no op

OK, but there were issues!
Tests: 14, Assertions: 190, Deprecations: 20.
```
(⚠ = pre-existing core/contrib deprecation notice only, not a failure — every one of the 14 tests
PASSES; the deprecation set is identical to what T-red's own RED handoff already logged as
pre-existing noise on this module, e.g. `@Action`/`@EntityType` annotation deprecations, Twig
sandbox policy signature deprecation, `Config::save($has_trusted_data)`. 0 errors, 0 failures,
reproduced identically across 3 separate runs.)

**Full `do_notifications` kernel regression sweep (GREEN, no regressions):**
```
$ ddev exec "cd /var/www/html && SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://web php vendor/bin/phpunit -c web/core/phpunit.xml.dist $(find web/modules/custom/do_notifications -type d -path '*/tests/src/Kernel') --testdox"

Daily Digest Command:       4/4 (all new, this story)
Database Queue Backend:     6/6 (2 pre-existing + 4 new, this story)
Digest Queue Backend:       4/4 (all new, this story)
Digest Renderer:            6/6 (pre-existing N-8, unaffected)
Email Renderer:             3/3 (pre-existing N-4, unaffected)
Group Add Notification:     6/6 (pre-existing N-2, unaffected)
Log Mailer:                 1/1 (pre-existing N-1, unaffected)
Subscription Router:        5/5 (pre-existing N-2, unaffected — including all tests N-1's own
                                  handoff-F had flagged as needing a schema-install fix; that gap
                                  has already been fixed upstream of this story)

OK, but there were issues!
Tests: 35, Assertions: 1028, Deprecations: 21.
```
0 errors, 0 failures across the entire module. Every pre-existing test class passes unchanged.

**phpcs (`--standard=Drupal,DrupalPractice`, exit 0, zero findings) on every new/changed
production file:**
```
$ ddev exec "cd /var/www/html && php vendor/bin/phpcs --standard=Drupal,DrupalPractice web/modules/custom/do_notifications/do_notifications.install web/modules/custom/do_notifications/do_notifications.services.yml web/modules/custom/do_notifications/drush.services.yml web/modules/custom/do_notifications/src/Queue/QueueBackendInterface.php web/modules/custom/do_notifications/src/Queue/DatabaseQueueBackend.php web/modules/custom/do_notifications/src/Queue/MockQueueBackend.php web/modules/custom/do_notifications/src/Queue/DigestQueueBackendInterface.php web/modules/custom/do_notifications/src/Queue/DatabaseDigestQueueBackend.php web/modules/custom/do_notifications/src/Commands/DigestCommands.php"
$ echo $?
0
```
Verified against both the assembled `web/modules/custom/` copy and the `docs/groups/` source
directly — identical clean result.

**Note on the task prompt's literal bare `vendor/bin/phpcs docs/groups/modules/do_notifications`
invocation:** ran it verbatim for completeness. No `default_standard` is configured in this repo's
`CodeSniffer.conf` (confirmed via `phpcs --config-show`), so a bare invocation silently falls back
to PHP_CodeSniffer's built-in PEAR standard — producing irrelevant findings (`@category`/
`@package`/`@author`/`@license`/`@link` tag requirements, 4-space-indent expectations) that are
noise for a Drupal codebase, not a signal on any of my files specifically. This exact gap was
already documented by the #229 (N-1) handoff-F for this same module — re-confirmed here. With the
correct `--standard=Drupal,DrupalPractice` flag, sweeping the ENTIRE `do_notifications` source tree
(not just my 9 files) surfaces findings only in files I did NOT touch: 2 pre-existing production
files (`NotificationSettingsController.php`, `DoNotificationsHooks.php`, both untouched by this
story) and T-authored test files (out of scope for me — see "Tests that look wrong" below). None
of my 9 production files appear in that sweep.

## Tests that look wrong (for T)

**`DailyDigestCommandTest.php` line 129, `sourceQueue()` private helper** — flagged by phpcs
(`Unused private method sourceQueue()`) when sweeping the whole test file. This is a minor,
cosmetic dead-code lint warning, not a functional test bug: the helper is defined but never
called (the tests use `sourceRowCount()` for raw-count assertions instead). Does not affect any
acceptance criterion or test outcome — noting for T's own lint hygiene pass, not blocking.

No other tests look wrong. All 14 tests across the 3 target files pass, and their assertions match
the brief/A/T-red-locked contract exactly (summary shape, orphan garbage-collection, window-state
override, zero-item skip).

## Known issues

None against this story's own acceptance criteria — all 10 checkboxes (AC1-AC10) in the brief are
satisfied:
- AC1-AC9: verified GREEN by `DailyDigestCommandTest`'s 4 tests (integrated scenario +
  window-override + orphan + zero-item tests).
- AC10 (coding standards clean): verified via `phpcs --standard=Drupal,DrupalPractice`, exit 0,
  zero findings on all 9 production files.

One open item flagged for A's attention (not a defect in this story's own scope, but a real,
pre-existing core/N-4/N-8 quirk this story's new code path was the first to surface — see
"Architecture notes for A" and "Deviations" above): whether the `Message::getCreatedTime()`
string-vs-int quirk should be fixed upstream in `EmailRenderer`/`DigestRenderer` (or reported as a
Drupal core issue) rather than normalized at this story's call site. Not filed as a GH issue per
the POC-lean no-follow-ups rule; surfaced here for visibility only.

## Files changed

- `docs/groups/modules/do_notifications/do_notifications.install` (modified)
- `docs/groups/modules/do_notifications/do_notifications.services.yml` (modified)
- `docs/groups/modules/do_notifications/drush.services.yml` (new)
- `docs/groups/modules/do_notifications/src/Queue/QueueBackendInterface.php` (modified)
- `docs/groups/modules/do_notifications/src/Queue/DatabaseQueueBackend.php` (modified)
- `docs/groups/modules/do_notifications/src/Queue/MockQueueBackend.php` (modified)
- `docs/groups/modules/do_notifications/src/Queue/DigestQueueBackendInterface.php` (new)
- `docs/groups/modules/do_notifications/src/Queue/DatabaseDigestQueueBackend.php` (new)
- `docs/groups/modules/do_notifications/src/Commands/DigestCommands.php` (new)

All 9 staged by explicit path (no `git add .`/`-A`).

(Test files `docs/groups/modules/do_notifications/tests/src/Kernel/DailyDigestCommandTest.php`
(new), `.../DigestQueueBackendTest.php` (new), and `.../DatabaseQueueBackendTest.php` (extended)
were authored by T in Phase 4 and are unchanged by me — listed in T's own handoff, not repeated
here as F's output, and left unstaged.)
