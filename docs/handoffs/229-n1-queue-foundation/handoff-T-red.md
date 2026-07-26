# Handoff-T-red: Phase 4 - N-1 Queue foundation + LogMailer (#229)

**Date:** 2026-07-26
**Branch:** 229-n1-queue-foundation
**Brief / wireframe reviewed:** `docs/handoffs/229-n1-queue-foundation/brief.md`, `docs/handoffs/229-n1-queue-foundation/decisions.md` (no wireframe — no UI surface, POC lean pipeline, review-rigor `none`)

## A precondition

No formal A handoff exists for this story (review-rigor `none` per brief — POC lean, diff-gate
only). Proceeding per O's Phase-1 pipeline decision (`O → A → T(RED) → F → T(GREEN) → diff-gate →
S → PR → self-merge`, no D, no U). Treating the approved brief itself as the Phase-3 plan artifact.

## Tests authored

Both files added under `docs/groups/modules/do_notifications/tests/src/Kernel/` (source of truth;
copied into `web/modules/custom/` by `scripts/ci/assemble-config.sh`):

1. **`DatabaseQueueBackendTest.php`** (KernelTestBase, `$modules = ['flag', 'do_notifications']`)
   - `testEnqueueDrainCount` — pins: enqueue() persists a real row per distinct
     (uid, mid, frequency, day) tuple, independently verified via a raw
     `\Drupal::database()->select('do_notifications_queue', ...)->countQuery()` (not just the
     backend's own `count()`); `drain()` returns every item with its payload intact and empties
     both the backend's `count()` and the raw DB count. Kernel tier — needs a real DB schema +
     service container, cheapest tier that can prove persistence (a unit test with a mocked
     `Connection` would not catch a backend that never actually writes SQL).
   - `testEnqueueDedupOnTuple` — pins: a second `enqueue()` with an identical
     (uid, mid, frequency, day) tuple is silently dropped (MockQueueBackend's contract, brief
     acceptance criterion, `QueueBackendInterface::enqueue()` docblock). Same kernel tier — the
     dedup key collision is the DB unique-key behavior itself (`merge()`), not mockable at unit
     tier without re-implementing the constraint.
   - Both added `flag` to `$modules`: `do_notifications.subscription_router` unconditionally
     depends on `@flag` in `do_notifications.services.yml`, and the DI container fails to compile
     for ANY kernel test enabling `do_notifications` without it (pre-existing constraint, not new
     to this story — `GroupAddNotificationTest` and `SubscriptionRouterTest` already do this).

2. **`LogMailerTest.php`** (KernelTestBase, `$modules = ['system', 'user', 'flag', 'message', 'message_notify', 'dblog', 'do_notifications']`)
   - `testDeliverWritesWatchdogAndFile` — pins: 3 `deliver()` calls on the `log_mailer` Notifier
     plugin each write one watchdog row (type `do_notifications`, verified via direct DB count on
     `watchdog`) AND append one line to the file sink
     (`file_system.getTempDirectory() . '/notifications.log'`), in the exact format
     `[YYYY-MM-DDTHH:MM:SSZ] uid=<uid> mid=<mid> template=<template>` (regex-matched line-by-line),
     with the actual uid/mid/template values cross-checked (not just the format shape), and
     `deliver()` returns `TRUE`. Kernel tier — needs a real plugin manager (`plugin.message_notify.
     notifier.manager`, discovered from `web/modules/contrib/message_notify/src/Plugin/Notifier/
     Manager.php`), a real `dblog` watchdog table, and a real filesystem service; not mockable at
     unit tier without losing the plugin-discovery assertion (the `@Notifier` annotation
     registration is itself part of the acceptance criterion).

## RED confirmation

Environment setup notes (both needed before a valid run was possible — not test-authorship
issues, environment plumbing specific to this worktree):
- `vendor/` and `web/core` were absent in the worktree (fresh worktree, no `composer install` yet)
  → ran `ddev composer install --no-interaction --no-progress` (installs `drupal/message_notify`
  1.5.0 among others, confirmed present under `web/modules/contrib/message_notify`).
- `scripts/ci/assemble-config.sh` requires `php` (not on host) → ran via `ddev exec`.
- Kernel tests need `SIMPLETEST_DB` / `SIMPLETEST_BASE_URL` → used the project's own
  `scripts/dev/run-kernel.sh` recipe (`SIMPLETEST_DB=mysql://db:db@db/db
  SIMPLETEST_BASE_URL=https://web`) rather than a guessed value.

Command:
```
cd C:/Users/aange/Projects/_worktrees/groups-n1-queue-foundation-229
ddev exec "bash scripts/ci/assemble-config.sh"
ddev exec "SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://web php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_notifications/tests/src/Kernel/DatabaseQueueBackendTest.php web/modules/custom/do_notifications/tests/src/Kernel/LogMailerTest.php"
```

Output (first RED, valid — both failures are the target-behavior assertions failing, not
setup/import errors):

```
Database Queue Backend (Drupal\Tests\do_notifications\Kernel\DatabaseQueueBackend)
 ✘ Enqueue drain count
   │
   │ LogicException: do_notifications module does not define a schema for table 'do_notifications_queue'.
   │
   │ /var/www/html/web/core/tests/Drupal/KernelTests/KernelTestBase.php:779
   │ /var/www/html/web/modules/custom/do_notifications/tests/src/Kernel/DatabaseQueueBackendTest.php:42
   │
 ✘ Enqueue dedup on tuple
   │
   │ LogicException: do_notifications module does not define a schema for table 'do_notifications_queue'.
   │
   │ /var/www/html/web/core/tests/Drupal/KernelTests/KernelTestBase.php:779
   │ /var/www/html/web/modules/custom/do_notifications/tests/src/Kernel/DatabaseQueueBackendTest.php:42

Log Mailer (Drupal\Tests\do_notifications\Kernel\LogMailer)
 ✘ Deliver writes watchdog and file
   │
   │ Drupal\Component\Plugin\Exception\PluginNotFoundException: The "log_mailer" plugin does not exist. Valid plugin IDs for Drupal\message_notify\Plugin\Notifier\Manager are: email
   │
   │ /var/www/html/web/core/lib/Drupal/Component/Plugin/Discovery/DiscoveryTrait.php:53
   │ /var/www/html/web/core/lib/Drupal/Component/Plugin/Discovery/DiscoveryCachedTrait.php:28
   │ /var/www/html/web/modules/contrib/message_notify/src/Plugin/Notifier/Manager.php:36
   │ /var/www/html/web/modules/custom/do_notifications/tests/src/Kernel/LogMailerTest.php:88
   │ /var/www/html/web/modules/custom/do_notifications/tests/src/Kernel/LogMailerTest.php:117

Tests: 3, Assertions: 5, Errors: 3, Deprecations: 6 (pre-existing core/contrib deprecation noise,
unrelated to this story — same deprecations fire on the pre-existing EmailRendererTest /
SubscriptionRouterTest / GroupAddNotificationTest when the full module suite runs).
```

Both errors are exactly the RED the brief predicts:
- `DatabaseQueueBackendTest` fails because `do_notifications.install` (`hook_schema()` for
  `do_notifications_queue`) does not exist yet — F must add it.
- `LogMailerTest` fails because `Drupal\do_notifications\Plugin\Notifier\LogMailer` (the
  `@Notifier`-annotated plugin, id `log_mailer`) does not exist yet — F must add it.

Neither failure is an import error, a typo, or a test-setup mistake — both are the exact "code
under test doesn't exist" RED the brief specifies.

## Decisions made during authoring (not blocking, recorded for F)

- **LogMailer invocation path:** the brief was ambiguous between `send()` and `deliver()`.
  Confirmed via `MessageNotifierInterface`/`MessageNotifierBase`/`Email` (message_notify contrib)
  that `deliver(array $output)` is the plugin's own abstract-required entry point — `send()` is a
  higher-level wrapper that renders the Message via the entity view builder into `$output` keyed
  by view mode, then calls `deliver()`. Since LogMailer's payload is `uid`/`mid`/`template`
  identifiers rather than rendered view-mode markup, the test instantiates the plugin via
  `plugin.message_notify.notifier.manager` (the correct service id — NOT
  `plugin.manager.message_notify.notifier` as the task brief guessed) and calls `deliver($payload)`
  directly, bypassing `send()`'s view-builder rendering. This matches the brief's own phrasing:
  "`deliver(array $output)`: writes one log line per delivery."
- **Message/MessageTemplate fixture:** `Message::create()` requires an existing
  `message_template` bundle to save (message module's `bundle_entity_type`). Created a minimal
  `MessageTemplate` config entity (`do_notifications_test_template`, no `text` setting) in
  `setUp()` — LogMailer's contract does not depend on rendered template text, only on the
  uid/mid/template identifiers passed directly in `$output`.
- **`flag` module added to both suites' `$modules`:** `do_notifications.services.yml`'s
  `do_notifications.subscription_router` service unconditionally references `@flag`, so the DI
  container fails to compile for ANY kernel test enabling `do_notifications` without the `flag`
  module present — this is a pre-existing constraint (already worked around identically in
  `GroupAddNotificationTest` / `SubscriptionRouterTest`), not something introduced by this story.

## Ready for F

RED confirmed valid. F may implement against `DatabaseQueueBackendTest.php` and
`LogMailerTest.php`. Scope for F per the brief:
1. `Drupal\do_notifications\Queue\DatabaseQueueBackend implements QueueBackendInterface`
   (merge()-based upsert, dedup on uid/mid/frequency/day).
2. `do_notifications.install` — `hook_schema()` for `do_notifications_queue`.
3. `Drupal\do_notifications\Plugin\Notifier\LogMailer` (`@Notifier`, id `log_mailer`) —
   `deliver()` writes to `\Drupal::logger('do_notifications')` + appends to
   `file_system.getTempDirectory() . '/notifications.log'`, format
   `[YYYY-MM-DDTHH:MM:SSZ] uid=<uid> mid=<mid> template=<template>`, returns `TRUE`.
4. `do_notifications.services.yml` — swap `do_notifications.queue` class to
   `DatabaseQueueBackend`, inject `@database`.
