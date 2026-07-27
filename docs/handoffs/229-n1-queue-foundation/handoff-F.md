# Handoff-F: Phase 5 - N-1 Queue foundation + LogMailer

**Date:** 2026-07-26
**Branch:** 229-n1-queue-foundation
**Issue:** #229

## What was done

- `docs/groups/modules/do_notifications/do_notifications.install` (new) — `do_notifications_schema()`
  defining the `do_notifications_queue` table: serial PK `id`; unsigned int `uid`/`mid`; varchar(128)
  `template`; varchar(32) `frequency`; varchar(10) `day`; unsigned int `created`. Unique key
  `uid_mid_frequency_day` on `(uid, mid, frequency, day)` (the dedup enforcement). Added a
  non-required `frequency_day` index anticipating the N-3+ digest worker's query pattern.
- `docs/groups/modules/do_notifications/src/Queue/DatabaseQueueBackend.php` (new) —
  `QueueBackendInterface` implementation backed by the real DB table. `enqueue()` uses
  `Connection::merge()->keys([...])->fields([...])` for silent-drop dedup on the unique tuple.
  `drain()` wraps a SELECT (ordered `id` ASC) + DELETE in a transaction with explicit rollback on
  exception. `count()` is a `SELECT COUNT(*)`. Constructor injects `@database` and `@datetime.time`.
- `docs/groups/modules/do_notifications/src/Plugin/Notifier/LogMailer.php` (new) — `@Notifier`
  plugin (id `log_mailer`, viewModes `{email}`) extending `message_notify`'s
  `MessageNotifierBase`. `deliver(array $output)` reads `uid`/`mid`/`template` from `$output`,
  logs to the injected `logger.channel.do_notifications` channel, and appends one line
  (`[YYYY-MM-DDTHH:MM:SSZ] uid=<uid> mid=<mid> template=<template>`, UTC) to
  `file_system.getTempDirectory() . '/notifications.log'`. Always returns `TRUE`.
- `docs/groups/modules/do_notifications/do_notifications.services.yml` (modified) — swapped
  `do_notifications.queue`'s class to `DatabaseQueueBackend` with `arguments: ['@database',
  '@datetime.time']`; updated the inline comment from future-tense ("N-1 will swap...") to
  past-tense ("N-1 completed..."). Added a new `logger.channel.do_notifications` entry
  (`parent: logger.channel_base`) that `LogMailer` injects, mirroring `message_notify`'s own
  `logger.channel.message_notify` entry. Every other service entry preserved untouched.
- `docs/groups/modules/do_notifications/src/Queue/MockQueueBackend.php` (modified, docblock only)
  — updated the class docblock from future-tense ("shippable default... until DatabaseQueueBackend
  lands") to past-tense + restated purpose ("Was the shippable default... Retained... for unit
  tests"). Class body (dedup/enqueue/drain/count logic) untouched — kept intact per the brief.
- `docs/groups/modules/do_notifications/do_notifications.info.yml` (modified) — added
  `drupal:message_notify` to `dependencies` (the project's established notation, confirmed against
  `do_activity.info.yml`'s identical existing entry for the same contrib module).

## Design decisions

- **`MessageNotifierBase`, not a class literally named `Base`.** The task instructions asked me to
  "check what message_notify's base class actually is." Inspected
  `web/modules/contrib/message_notify/src/Plugin/Notifier/`: the abstract parent both `Email` and
  `Sms` extend is `MessageNotifierBase`; there is no class named `Base` in this contrib module.
  Followed `Email.php`'s established pattern exactly — override `__construct()` to accept one
  extra injected service beyond the parent's four (config/plugin_id/plugin_definition/logger/
  entity_type_manager/renderer/message), override the static `create()` factory to pull that extra
  service from the container.
- **The one extra injected service is `FileSystemInterface`** (`@file_system`) — needed only for
  `getTempDirectory()`. Used the interface type, matching the brief's own portability rationale
  ("portable + kernel-testable, not hardcoded `/tmp`").
- **`logger.channel.do_notifications` registered as a proper injectable service**, not a
  `\Drupal::logger('do_notifications')` static call inside `deliver()`. `MessageNotifierBase`'s
  constructor already declares a `LoggerChannelInterface $logger` parameter that both `Email` and
  `LogMailer` receive via DI — reusing that exact injection point (with a new
  `logger.channel.do_notifications` service, `parent: logger.channel_base`, mirroring
  `message_notify.services.yml`'s own `logger.channel.message_notify` entry) is the idiomatic path
  already established by the base class this plugin extends. Functionally identical to the brief's
  literal phrasing — both produce a `watchdog.type = 'do_notifications'` row, which is exactly what
  T's test asserts via a raw DB count.
- **`TimeInterface` injected into `DatabaseQueueBackend`, not a static `\Drupal::time()` call.**
  The task instructions' literal `enqueue()` snippet used `\Drupal::time()->getRequestTime()`
  inline, but that trips DrupalPractice's "`\Drupal` calls should be avoided in classes" warning.
  Free to fix (one constructor arg, one services.yml arg, matches `SubscriptionRouter`'s existing
  `@datetime.time` injection in this same module) — did so, verified no test-behavior change
  (identical 3/3 GREEN before/after), and it gets this new file to a clean phpcs bar rather than
  accepting the same tolerated-but-not-ideal pattern the codebase's pre-existing
  `DoDiscoveryHooks.php` carries.
- **`\Drupal::time()` left as a static call inside `LogMailer::deliver()`** (only for the log-line
  timestamp) — verified via a verbose single-file phpcs run (164 sniffs registered) that this
  specific call is NOT flagged, and `MessageNotifierBase`'s fixed constructor signature (which
  `Manager::createInstance()` calls positionally) makes adding a fifth constructor argument pure
  ceremony for zero reported violations.
- **`.install` file has no `declare(strict_types=1)`**, matching the project's own established
  convention: `do_discovery.install` (a bare array-returning `hook_schema()`, closest structural
  precedent) has none; the `declare` only appears in this codebase's `.install` files that also
  define typed function signatures (`do_activity.install`, `do_group_membership.install`).
- **`drupal:message_notify` in `do_notifications.info.yml`**, not the task instructions' literal
  `message_notify:message_notify` guess — confirmed `do_activity.info.yml` already lists
  `drupal:message_notify` for the identical contrib module; using the project's real, established
  notation rather than the task-writer's approximation.
- **`--standard=Drupal,DrupalPractice` explicit phpcs flag**, not the bare invocation the task
  instructions specified. Verified `phpcs --config-show` reports no `default_standard` and no
  `phpcs.xml`/`.phpcs.xml.dist` exists anywhere in this repo — a bare invocation silently falls
  back to PHP_CodeSniffer's built-in PEAR standard (thousands of irrelevant findings across the
  whole module, confirmed with a scratch run). This exact gap is already documented by a prior
  story in this repo (`docs/planning/handoffs/193-sd4-tooltip-consumers/handoff-F.md`) and is the
  flag T's own precedent handoffs for this module (#230, #112) already use.

## Reuse / extend-vs-new

Per the brief's Reuse map:
- **Extended** N-2's `QueueBackendInterface` (`docs/groups/modules/do_notifications/src/Queue/
  QueueBackendInterface.php`) — `DatabaseQueueBackend` implements it with identical
  `enqueue`/`drain`/`count` signatures, no `retry` field or method added (Option A trim, per the
  brief).
- **New, brief-justified:** `DatabaseQueueBackend` class — the brief explicitly calls this "a
  parallel implementation, not duplication — that's the swap point," and `do_notifications.services.
  yml` now points at it exclusively; `MockQueueBackend` remains only as a secondary,
  intentionally-retained implementation for unit tests, not a competing production path.
- **New, brief-justified:** `do_notifications.install` — the module had no `.install` file before
  this story; the brief explicitly scoped adding one.
- **New, brief-justified:** `LogMailer` plugin — no pre-existing do_notifications Notifier plugin
  to extend; `message_notify`'s own `Email` plugin is the closest analog and was read for its
  extension pattern (constructor/`create()` override shape), not extended directly, since
  `LogMailer`'s payload contract (uid/mid/template identifiers) is fundamentally different from
  `Email`'s (rendered view-mode markup via mail).

## Architecture notes for A

- **Layers touched:** module install schema (`.install`), a service class (`Queue/
  DatabaseQueueBackend.php`), a plugin class (`Plugin/Notifier/LogMailer.php`), DI wiring
  (`services.yml`), module metadata (`info.yml`). No controller/form/routing/config-entity layer
  touched.
- **New dependency:** `drupal:message_notify` added to `do_notifications.info.yml`. This module was
  already a transitive/enabled dependency project-wide (per `scripts/ci/assemble-config.sh`'s own
  `core.extension.yml` patch step, which has enabled `message_notify` since #116/do_activity) —
  this change makes `do_notifications`'s own `.info.yml` honestly declare a dependency its new
  `LogMailer` plugin now has directly, closing a previously-implicit gap.
- **New service:** `logger.channel.do_notifications` (`parent: logger.channel_base`) — a small,
  standard Drupal logger-channel registration, zero behavioral surface beyond naming the watchdog
  `type` column value.
- **Schema/contract change:** new DB table `do_notifications_queue` (see `.install` above). No
  existing table or config schema altered.
- **Service swap (the story's core purpose):** `do_notifications.queue` now resolves to
  `DatabaseQueueBackend` instead of `MockQueueBackend` — every existing consumer of
  `@do_notifications.queue` (currently only `do_notifications.subscription_router`, per a
  codebase-wide grep) now gets the real DB-backed implementation. This is the single change most
  worth A's attention: it surfaced a genuine gap in a pre-existing N-2 test (see "Tests that look
  wrong" below) that only exists because that test's `setUp()` implicitly relied on the
  in-memory-mock's zero-schema-dependency behavior.
- **No local patterns broken:** constructor-promoted `readonly` properties (matches
  `SubscriptionRouter`), `Connection::merge()` fluent API (matches `DoDiscoveryHooks.php`'s
  existing single-key usage, extended here to a 4-column composite key via `->keys([...])`),
  `\Drupal::time()->getRequestTime()` usage pattern (matches `DoNotificationsHooks.php`,
  `DoDiscoveryHooks.php`).

## Deviations from spec / wireframe

None from the brief's functional scope. Two deviations from the task instructions' literal code
snippets (both documented above and in `decisions.md`, both net-neutral-or-better against the
brief's own stated goals):
1. `TimeInterface` injected rather than a static `\Drupal::time()` call in `enqueue()` (DI-idiomatic,
   phpcs-clean, matches this module's own established pattern).
2. `logger.channel.do_notifications` registered as an injectable service rather than a
   `\Drupal::logger()` static call (DI-idiomatic, matches the exact base class `LogMailer` extends,
   functionally identical watchdog output).

No UI surface in this story (backend service + plugin foundation only) — no wireframe to conform to.

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

**Target kernel tests (GREEN, verified stable across 3 separate runs):**
```
$ ddev exec "SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://web php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_notifications/tests/src/Kernel/DatabaseQueueBackendTest.php web/modules/custom/do_notifications/tests/src/Kernel/LogMailerTest.php"

Database Queue Backend (Drupal\Tests\do_notifications\Kernel\DatabaseQueueBackend)
 ⚠ Enqueue drain count
 ⚠ Enqueue dedup on tuple

Log Mailer (Drupal\Tests\do_notifications\Kernel\LogMailer)
 ⚠ Deliver writes watchdog and file

OK, but there were issues!
Tests: 3, Assertions: 26, Deprecations: 7.
```
(⚠ = deprecation notice only, not a failure — all 3 tests PASS. The 7 deprecations are pre-existing
core/contrib noise, confirmed against T's RED handoff which already logged the identical
deprecation set on this same module's pre-existing suites — none originate from new code in this
story.)

**phpcs (exit 0, zero findings) on every new/changed PHP production file:**
```
$ ddev exec "php vendor/bin/phpcs --standard=Drupal,DrupalPractice web/modules/custom/do_notifications/src/Queue/DatabaseQueueBackend.php web/modules/custom/do_notifications/src/Plugin/Notifier/LogMailer.php web/modules/custom/do_notifications/do_notifications.install"
$ echo $?
0
```
Also re-verified on the docblock-only-changed `MockQueueBackend.php`: exit 0, zero findings.

**Full `do_notifications` regression sweep (found + isolated a pre-existing gap, see below):**
```
$ ddev exec "SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://web php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_notifications/tests/src/Kernel/"

Database Queue Backend: ⚠ Enqueue drain count / ⚠ Enqueue dedup on tuple      (PASS, deprecation only)
Email Renderer:         ⚠ Renders all six event types / ⚠ Missing ref. renders removed (PASS, deprecation only — unrelated to this story)
Group Add Notification: ✔ ×6                                                  (PASS — different queue service, unaffected)
Log Mailer:             ⚠ Deliver writes watchdog and file                    (PASS, deprecation only)
Subscription Router:    ✘ ×5 — ALL "Table '...do_notifications_queue' doesn't exist"

Tests: 16, Assertions: 546, Errors: 5, Deprecations: 20.
```

## Tests that look wrong (for T)

**`SubscriptionRouterTest.php`** (pre-existing N-2/#230 file, NOT edited by me) — `setUp()` never
calls `installSchema('do_notifications', ['do_notifications_queue'])`. This was correct when
`do_notifications.queue` resolved to N-2's in-memory `MockQueueBackend` (no DB table involved at
all), but this story's entire purpose is swapping that service to the real DB-backed
`DatabaseQueueBackend`, which genuinely needs the table to exist. All 5 of `SubscriptionRouterTest`'s
methods now fail with `Drupal\Core\Database\DatabaseExceptionWrapper: ... Table
'db.testXXXXXXXXdo_notifications_queue' doesn't exist` at the exact line each calls
`$this->container->get('do_notifications.queue')->drain()` or `->count()`.

Confirmed this is NOT something I can fix from production code: `KernelTestBase::enableModules()`
(the code path every kernel test's static `$modules` property drives) deliberately never invokes
`hook_schema()`-driven table creation — only the real `ModuleInstaller::install()` (core's
`web/core/lib/Drupal/Core/Extension/ModuleInstaller.php:347-348`, a separate and heavier code path
used by BrowserTestBase / production module installs, not KernelTestBase) auto-creates
`hook_schema()` tables. Every existing kernel test in this codebase that needs a module's DB table
opts in via its own explicit `installSchema()` call — there is no `do_notifications`-level hook or
shared base-class override I could add that would make this automatic without editing the test
file's `setUp()` directly.

**Suggested fix for T (Phase 6):** add one line to `SubscriptionRouterTest::setUp()`, immediately
after the existing `installSchema()` calls:
```php
$this->installSchema('do_notifications', ['do_notifications_queue']);
```
This exactly mirrors what T's own new `DatabaseQueueBackendTest::setUp()` already does. After this
one-line addition, the full `do_notifications` kernel suite should be 16/16 tests, 0 errors (only
the same pre-existing deprecation noise on the other 3 suites).

## Known issues

None against this story's own acceptance criteria — all 8 checkboxes in the brief are satisfied by
the files listed above (verified against `DatabaseQueueBackendTest` + `LogMailerTest`, both GREEN).
The one open item is the `SubscriptionRouterTest` gap above, which is pre-existing test debt this
story's service swap surfaced, not a defect in the code I wrote — flagged for T rather than treated
as "my known issue."

## Files changed

- `docs/groups/modules/do_notifications/do_notifications.install` (new)
- `docs/groups/modules/do_notifications/src/Queue/DatabaseQueueBackend.php` (new)
- `docs/groups/modules/do_notifications/src/Plugin/Notifier/LogMailer.php` (new)
- `docs/groups/modules/do_notifications/do_notifications.services.yml` (modified)
- `docs/groups/modules/do_notifications/src/Queue/MockQueueBackend.php` (modified, docblock only)
- `docs/groups/modules/do_notifications/do_notifications.info.yml` (modified)

(Test files `docs/groups/modules/do_notifications/tests/src/Kernel/DatabaseQueueBackendTest.php`
and `.../LogMailerTest.php` were authored by T in Phase 4 and are unchanged by me — listed in T's
own handoff, not repeated here as F's output.)
