# Handoff-F: Phase 5 - N-3 Notification delivery worker

**Date:** 2026-07-26
**Branch:** 231-n3-delivery-worker
**Issue:** #231

## What was done

- **New:** `docs/groups/modules/do_notifications/src/Drush/Commands/DoNotificationsCommands.php` — the `do_notifications:deliver` command (alias `do:notif:deliver`, option `--type=<all|immediately|daily|weekly>`). Drains the queue (frequency-filtered), delivers `immediately` entries individually via `EmailRenderer::render()` + `log_mailer`, groups `daily`/`weekly` entries by recipient uid and delivers ONE `DigestRenderer::render()` + ONE `log_mailer` call per recipient, skips-with-WARNING any entry whose Message/recipient no longer loads, no-ops cleanly on an empty drain.
- **New:** `docs/groups/modules/do_notifications/drush.services.yml` — registers the command class for Drush's own CLI bootstrap discovery (`tags: [{name: drush.command}]`).
- **Modified:** `docs/groups/modules/do_notifications/do_notifications.services.yml` — added the `do_notifications.deliver_commands` service (see "Design decisions" below for why this is ALSO needed here, not just in `drush.services.yml`).
- **Modified:** `docs/groups/modules/do_notifications/src/Queue/QueueBackendInterface.php` — extended `drain(): array` to `drain(?string $frequency = NULL): array`; updated the docblock to describe the optional filter (per A's finding #1).
- **Modified:** `docs/groups/modules/do_notifications/src/Queue/DatabaseQueueBackend.php` — `drain()` now WHERE-filters the SELECT on `frequency` when given, and DELETEs only the rows just read (by their own `id`, not by re-applying the frequency filter to a fresh DELETE).
- **Modified:** `docs/groups/modules/do_notifications/src/Queue/MockQueueBackend.php` — `drain()` now filters the in-memory `$items` array by frequency when given, pruning only the matching entries' dedup keys from `$seenKeys` (non-matching entries and their dedup state are left untouched for a later drain).
- **Modified:** `docs/groups/modules/do_notifications/src/Email/EmailRenderer.php` — one-line `(int)` cast added to `renderEventFragments()`'s `created` value (see "Deviations from spec" — this is a real production bug this story's own delivery path exposed, not a brief-scoped file).

## Design decisions

1. **`--type=all` drains the WHOLE queue in one call, then groups by frequency in-process** (not three separate `drain('immediately')` / `drain('daily')` / `drain('weekly')` calls). Rationale: a single atomic drain can never interleave with a concurrent enqueue landing between two of three separate drain calls; the interface already returns each entry's own `frequency` field, so grouping afterward is free. `testDeliverAllTypeDrainsEverythingIncludingDigests`'s "queue ends empty" assertion is satisfied identically either way, but the atomic version is strictly safer.

2. **`do_notifications.deliver_commands` is registered in BOTH `drush.services.yml` AND `do_notifications.services.yml`** — this is a deviation from the locked plan (which only specified `drush.services.yml`) and is the single most important thing for A/T/O to understand about this handoff. Traced through `vendor/drush/drush/src/Runtime/LegacyServiceInstantiator.php`: Drush 13's `drush.services.yml` mechanism is a **one-way reader** of Drupal's container — it instantiates the listed classes via reflection into its OWN internal array (`$instantiatedDrushServices`), for Drush's own Symfony Console command registration. It **never writes back** into Drupal's real service container. So `\Drupal::service('do_notifications.deliver_commands')` — which is exactly what T's kernel test calls (per the RED handoff's own documented pattern, matching how Drush 13 commands are unit-tested) — can **never** resolve a service that exists only in `drush.services.yml`. I registered the same class + arguments in `do_notifications.services.yml` too, so the real container can serve it. `drush.services.yml` remains in place (harmless, and still what Drush's own CLI bootstrap discovers `drush do_notifications:deliver` through at the command line).

3. **The `$notifierManager` constructor argument is NULLABLE, referenced as `'@?plugin.message_notify.notifier.manager'`** (optional Symfony DI reference), not a hard `'@...'` reference — this is downstream of decision #2. Registering the command in `do_notifications.services.yml` with a HARD reference to `plugin.message_notify.notifier.manager` broke container compilation for `DatabaseQueueBackendTest`, `DigestRendererTest`, and `EmailRendererTest` — all three deliberately enable `do_notifications` WITHOUT enabling `message_notify` to keep their own fixtures minimal, and Symfony's DI container validates every registered service's references at COMPILE time regardless of whether the service is ever instantiated. `message_notify` genuinely IS a hard `.info.yml` dependency of `do_notifications`, so this is always non-NULL on a real site; it's purely a test-harness-minimalism gap. `deliver()` fails fast with a `\RuntimeException` (via `notifierManagerOrFail()`) if it's ever actually NULL at call time — a state only reachable on a mis-provisioned site missing its own declared dependency, never in normal operation. Verified: full `do_notifications` kernel suite is 28/28 GREEN with this fix; it was 22/28-erroring before.

4. **`$notifierManager`'s injected property is named `$notifierManager`, but the logger is named `$notificationsLogger`, not `$logger`** — `DrushCommands` (the parent class) already declares its OWN non-readonly `$logger` property via `Psr\Log\LoggerAwareTrait` (Drush's console-output logger). A constructor-promoted `private readonly ... $logger` fatals ("Cannot redeclare non-readonly property ... as readonly") rather than override it. Renamed to `$notificationsLogger` to avoid the collision; this is the `do_notifications` watchdog channel, distinct from Drush's own console logger.

5. **Digest `mid` identifier uses the FIRST SURVIVING message id**, not unconditionally the first entry's mid — if a recipient's very first queued daily/weekly entry references a since-deleted Message, their whole digest's log identifier should not point at a message `log_mailer` never actually rendered anything for. In every test's happy path the first enqueued mid IS the first surviving mid (no missing-message tests overlap with the digest-grouping test), so this refinement doesn't change any test's asserted value — it's a robustness improvement consistent with brief AC #5's "skip stale entries" intent applied to the digest path too.

6. **`EmailRenderer::renderEventFragments()`'s `created` value is now explicitly cast `(int)`** — see "Deviations from spec" below; this is the one file outside the brief's listed "Files expected to change" that needed a change, and it's a real bug fix, not new behavior.

## Reuse / extend-vs-new

- Extended `QueueBackendInterface::drain()` (added optional `?string $frequency = NULL`, updated both implementations) — no new "drainByFrequency" method, per the brief's Reuse map.
- Reused `EmailRenderer::render()` and `DigestRenderer::render()` as-is for their actual rendering logic — no token/removed-placeholder/grouping logic was touched. The one line changed in `EmailRenderer.php` (`(int)` cast) hardens a pre-existing type-safety gap in the `renderEventFragments()` return contract; it does not change any rendering behavior for any existing caller (in-memory Messages already carried an int `created`, so `(int) $int === $int`).
- Reused `LogMailer::deliver()` via `plugin.message_notify.notifier.manager->createInstance('log_mailer', [], $message)`, matching `LogMailerTest`'s own established invocation pattern exactly.
- New object: `Drupal\do_notifications\Drush\Commands\DoNotificationsCommands` — justified in the brief (module has no prior Drush command surface; Drush 13 attributes-based command class is the standard current pattern; verified no `drush.services.yml` precedent exists anywhere in the `docs/groups/modules/do_*` tree before this story).

## Architecture notes for A

- **Layers touched:** Queue backend contract (`QueueBackendInterface` + both impls), a NEW CLI/Drush layer (`src/Drush/Commands/`), the module's service registration (`do_notifications.services.yml`, plus the new `drush.services.yml`), and one type-safety line in the email-rendering layer (`EmailRenderer::renderEventFragments()`).
- **New dependency surfaced at runtime, not compile-time:** the command's constructor now depends on `plugin.message_notify.notifier.manager` as an OPTIONAL DI reference (`'@?...'`) rather than a hard one — see Design decision #3. This is a genuine new pattern for this codebase (no prior optional-service-reference example existed in any `do_*` module's own `*.services.yml`); flagging for A's attention as a pattern choice, not just an implementation detail.
- **No schema/contract changes** beyond the `drain()` signature extension (BC-preserved; `?string $frequency = NULL` defaults identically to the old no-arg behavior — verified by both pre-existing `DatabaseQueueBackendTest` tests, which call `drain()` with no argument, still passing unmodified).
- **Shared component changed outside the brief's "Files expected to change" list:** `EmailRenderer.php` — see "Deviations from spec" below for the full trace of why.

## Deviations from spec / wireframe

No wireframe (CLI-only feature, correctly noted by T). Two deviations from the brief's literal file list / plan, both discovered mid-implementation and both necessary to reach GREEN without editing any test:

1. **`EmailRenderer.php` was NOT in the brief's "Files expected to change" list, but required a one-line fix.** Root cause, traced fully: `Message::getCreatedTime()` reads the field item's raw `->value` (not `Timestamp::getCastedValue()`, which does cast to int). `SqlContentEntityStorage::mapFromStorageRecords()` assigns a freshly-`->load()`-ed entity's field value directly from the PDO-fetched DB row with **no cast of its own** (confirmed by reading the core method body). MySQL/MariaDB's PDO driver commonly returns an `int`-columned value as a numeric PHP **string**. Every PRE-#231 caller of `renderEventFragments()` (`EmailRendererTest`, `DigestRendererTest`) only ever passes an in-memory `Message` object whose `created` was just set via `setCreatedTime()`/`applyDefaultValue()` (a genuine int, never round-tripped through the DB) — so this gap was never exercised until #231's worker, which is the first caller in the codebase to `getStorage('message')->load($mid)` a Message the queue only knows by numeric id. Without the cast, `DigestRenderer::groupByDay()`'s `gmdate('Y-m-d', $fragment['created'])` fatals under `strict_types=1` with `TypeError: gmdate(): Argument #2 ($timestamp) must be of type ?int, string given`. Fixed at the single source (`renderEventFragments()`'s `'created' => (int) $message->getCreatedTime()`), which both `EmailRenderer::render()` and `DigestRenderer::collectFragments()` funnel through — one line, no change to any rendering/token logic, and verified it does not alter any existing test's asserted output (`EmailRendererTest`/`DigestRendererTest` both still pass unmodified, since `(int)` of an already-int value is a no-op).

2. **`do_notifications.deliver_commands` is registered in `do_notifications.services.yml`, not only `drush.services.yml` as literally specified** — see Design decision #2 above for the full trace (Drush's `LegacyServiceInstantiator` never writes back into Drupal's container; T's test calls `\Drupal::service()` directly, which needs the real container registration to resolve at all).

Both deviations were necessary to make T's RED tests pass without editing any test, and both are documented in-line in the affected files' docblocks for the next reader.

## Tier 1 self-check (incl. tests now GREEN)

Assembled first:
```
ddev exec bash scripts/ci/assemble-config.sh
```
```
==> assemble-config: repo root = /var/www/html
==> config: copied 139 file(s), excluded 7 env-specific file(s)
==> modules: copied 16 custom module(s) into web/modules/custom/
==> core.extension: registered custom do_* modules + flag/geofield/language/message/message_notify as enabled
==> assemble-config: done
```

**DeliveryWorkerTest alone (T's 5 authored tests) — GREEN:**
```
ddev exec 'SIMPLETEST_DB="mysql://db:db@db:3306/db" SYMFONY_DEPRECATIONS_HELPER=disabled php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_notifications/tests/src/Kernel/DeliveryWorkerTest.php'
```
```
Delivery Worker (Drupal\Tests\do_notifications\Kernel\DeliveryWorker)
 ⚠ Deliver immediately type drains only immediately entries
 ⚠ Deliver all type drains everything including digests
 ⚠ Deliver is idempotent on empty queue
 ⚠ Deliver skips missing message with warning
 ⚠ Deliver groups daily digest by recipient

OK, but there were issues!
Tests: 5, Assertions: 175, Deprecations: 18.
```
Exit code: 0. The `⚠` icon is PHPUnit's deprecation-only marker (not a failure) — all 175 assertions pass; the 18 deprecations are pre-existing framework/contrib noise (Flag's `@EntityType`/`@Action` annotations, `ConfigEntityBase::trustData()`, core service renames), the same category T's own RED handoff already documented as tolerated by CI's `SYMFONY_DEPRECATIONS_HELPER=disabled`.

**Full `do_notifications` kernel suite (all 6 test classes, confirms no sibling regressions from the `drain()` signature change or the `EmailRenderer` cast) — GREEN:**
```
ddev exec 'SIMPLETEST_DB="mysql://db:db@db:3306/db" SYMFONY_DEPRECATIONS_HELPER=disabled php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_notifications/tests/src/Kernel/'
```
```
Database Queue Backend (Drupal\Tests\do_notifications\Kernel\DatabaseQueueBackend)
 ⚠ Enqueue drain count
 ⚠ Enqueue dedup on tuple

Delivery Worker (Drupal\Tests\do_notifications\Kernel\DeliveryWorker)
 ⚠ Deliver immediately type drains only immediately entries
 ⚠ Deliver all type drains everything including digests
 ⚠ Deliver is idempotent on empty queue
 ⚠ Deliver skips missing message with warning
 ⚠ Deliver groups daily digest by recipient

Digest Renderer (Drupal\Tests\do_notifications\Kernel\DigestRenderer)
 ⚠ Renders digest for 20 messages
 ⚠ Caps at 50 and reports overflow
 ⚠ Deleted entity fallback flows through
 ✔ Invalid window throws
 ⚠ Weekly label
 ⚠ Empty messages returns subject with zero

Email Renderer (Drupal\Tests\do_notifications\Kernel\EmailRenderer)
 ⚠ Renders all six event types
 ⚠ Missing referenced entity renders removed
 ⚠ Render event fragments returns locked shape

Group Add Notification (Drupal\Tests\do_notifications\Kernel\GroupAddNotification)
 ✔ Node insert records node created event
 ✔ Non eligible node insert records nothing
 ✔ Add to group after creation records event
 ✔ Add node fixture records content and group events
 ✔ Add member records no added to group event
 ✔ Get group ids resolves v 4 relationship type ids

Log Mailer (Drupal\Tests\do_notifications\Kernel\LogMailer)
 ⚠ Deliver writes watchdog and file

Subscription Router (Drupal\Tests\do_notifications\Kernel\SubscriptionRouter)
 ⚠ Router dedups across follow flags
 ✔ Author never enqueued
 ✔ Mute group suppresses
 ✔ Per post state suppression
 ✔ No subscribers produces zero

OK, but there were issues!
Tests: 28, Assertions: 1022, Deprecations: 18.
```
Exit code: 0. 28/28 tests pass, 1022 assertions, 0 failures/errors.

**PHPCS:**
```
ddev exec 'vendor/bin/phpcs --standard=Drupal,DrupalPractice --extensions=php,yml docs/groups/modules/do_notifications/src/Drush/ docs/groups/modules/do_notifications/src/Queue/ docs/groups/modules/do_notifications/src/Email/EmailRenderer.php docs/groups/modules/do_notifications/drush.services.yml docs/groups/modules/do_notifications/do_notifications.services.yml'
```
Output: empty (0 errors, 0 warnings). **Note:** no `phpcs.xml.dist` file exists anywhere in this repo (verified — only `web/core/phpcs.xml.dist` and `web/modules/contrib/group/phpcs.xml`, neither applicable to custom `do_*` modules); ran with `--standard=Drupal,DrupalPractice` directly instead, since `drupal/coder` is installed in vendor. First run surfaced 2 line-length warnings (>80 chars) in `DoNotificationsCommands.php`'s docblocks — rewrapped both; confirmed via a baseline check that pre-existing unmodified sibling files (`SubscriptionRouter.php`, `DigestRenderer.php`) produce zero PHPCS warnings, so the 80-char convention is real and followed throughout this module, not phpcs default noise to ignore.

## Tests that look wrong (for T)

None. All 5 authored tests in `DeliveryWorkerTest.php` pin exactly the behavior described in the brief's acceptance criteria, and all pass without any test edits.

## Known issues

None outstanding. Two things worth flagging for future stories (not blocking this one):

1. **A's handoff finding #2** (digest `mid`-collapse lossiness for a future digest-audit query) is unchanged by this implementation — still deferred to N-9/real-mailer planning, as A's own handoff already noted. My "first SURVIVING mid" refinement (Design decision #5) does not resolve the underlying lossiness, only makes the identifier slightly more meaningful when a stale entry is present.
2. **`EmailRenderer::renderEventFragments()`'s `(int)` cast is a real bug fix that had zero prior test coverage of the "load a Message fresh from DB" path** — worth a note for whoever eventually authors a T-red suite for a hypothetical future story that also loads Messages by id, so this class of PDO-string-vs-int gap doesn't resurface elsewhere in the codebase unnoticed (e.g. any other code reading a `created`/`changed` field's raw `->value` off a freshly-loaded entity, under `strict_types=1`, would have the identical exposure).

## Files changed

- `docs/groups/modules/do_notifications/src/Queue/QueueBackendInterface.php` (modified — `drain()` signature + docblock)
- `docs/groups/modules/do_notifications/src/Queue/DatabaseQueueBackend.php` (modified — `drain()` impl)
- `docs/groups/modules/do_notifications/src/Queue/MockQueueBackend.php` (modified — `drain()` impl)
- `docs/groups/modules/do_notifications/src/Email/EmailRenderer.php` (modified — one-line `(int)` cast, see Deviations)
- `docs/groups/modules/do_notifications/src/Drush/Commands/DoNotificationsCommands.php` (new)
- `docs/groups/modules/do_notifications/drush.services.yml` (new)
- `docs/groups/modules/do_notifications/do_notifications.services.yml` (modified — added `do_notifications.deliver_commands` service, see Deviations)

All staged explicitly by path (no `git add .`/`-A`). Not staged/touched: `docs/groups/modules/do_notifications/tests/src/Kernel/DeliveryWorkerTest.php` (already staged by T before this handoff began — I made no edits to it).
