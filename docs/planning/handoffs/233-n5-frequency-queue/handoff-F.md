# Handoff-F: Phase 5 - N-5 Frequency Queue Logic

**Date:** 2026-07-26
**Branch:** 233-n5-frequency-queue
**Issue:** #233

## What was done

- Created `docs/groups/modules/do_notifications/src/Frequency/FrequencyResolver.php` — new
  single-responsibility service resolving `(frequency, send_at)` per recipient uid.
- Modified `docs/groups/modules/do_notifications/src/Subscription/SubscriptionRouter.php` —
  removed the single-shot site-wide frequency read, injected `FrequencyResolver` as the 6th
  constructor arg (replacing `ConfigFactoryInterface`), and replaced the enqueue loop with a
  per-uid `$this->frequencyResolver->resolve($uid)` call that stamps `frequency` and `send_at`
  onto every payload.
- Modified `docs/groups/modules/do_notifications/src/Queue/QueueBackendInterface.php` — added
  `send_at` (optional key) to the `enqueue()` docblock, with an explicit note that it is NOT part
  of the `(uid, mid, frequency, day)` dedup tuple (handoff-A finding #2).
- Modified `docs/groups/modules/do_notifications/do_notifications.services.yml` — registered
  `do_notifications.frequency_resolver` (`@entity_type.manager`, `@datetime.time`,
  `@config.factory`); updated the router's args to drop `@config.factory` and add
  `@do_notifications.frequency_resolver`.

## Design decisions

- **`configFactory` removed from `SubscriptionRouter`.** Confirmed via grep it was used in
  exactly one place (the deleted line). `FrequencyResolver` now owns the site-default-frequency
  read; the router no longer needs a direct config dependency. Removed cleanly from both the
  constructor and `do_notifications.services.yml`.
- **`match($frequency)` with `default => $now` in `computeSendAt()`.** Rather than an explicit
  `'immediately' => $now` arm, any value that isn't `'daily'` or `'weekly'` falls through to the
  `$now` default — this satisfies the brief's "any unknown/malformed frequency value → treat as
  immediately (defensive)" requirement without a redundant explicit case.
- **Weekly boundary formula chosen for algebraic simplicity, verified equivalent.** Implemented
  as "this week's Sunday 19:00 UTC (computed by subtracting `day_of_week * 86400` from today's
  19:00 UTC), roll forward 7 days if not strictly after now" rather than the brief's literal
  "add `days_until_sunday` days forward" framing. Both formulas describe the same instant; I
  verified this empirically (not just by inspection) with an ad-hoc script run inside the DDEV
  container comparing my implementation against the test file's independently-written
  `expectedSendAt()` helper across a 3-week synthetic range (7 days x 9 times-of-day) plus three
  explicit exact-instant checks at 18:59:59 / 19:00:00 / 19:00:01 UTC on a Sunday: 0 mismatches
  across 192 weekly checks and 35 daily checks. See `decisions.md` Phase 5 for the full note.
- **No logger injected into `FrequencyResolver`** (handoff-A finding #1). Both "user unloadable"
  and "field missing/empty" collapse into the same `resolveFrequency()` → `siteDefaultFrequency()`
  fallback path — silent by design, matching the resolver's pure-function feel. The router still
  logs its own top-level `route()` catch (untouched).
- **`QueueBackendInterface` docblock**: added the `send_at` bullet co-located with the explicit
  "NOT part of the dedup tuple" sentence in the same bullet, rather than as a separate paragraph,
  since both facts describe the same key.

## Reuse / extend-vs-new

- **Extended** `SubscriptionRouter` per the brief's Reuse map: added one constructor dependency,
  replaced the 2-line enqueue-loop body with a per-uid resolver call. No new routing
  orchestration object created.
- **New service** `Drupal\do_notifications\Frequency\FrequencyResolver` — per the brief's written
  justification: (a) frequency policy (per-user preference + boundary time math) is a distinct
  concern from routing, expected to be reused by future digest workers' next-run scheduling
  (N-6/N-7); (b) cleanly unit-testable in isolation without a router/message/queue setup. This is
  the brief's own explicit "New service" call, not an unplanned parallel path.
- **Reused** the preexisting `field_notification_frequency` field on the user entity — no new
  field YAML created. Read via the exact pattern already established in
  `NotificationSettingsController::content()` (`hasField()` + `isEmpty()` guard, then `->value`).
- **Reused** the preexisting `do_notifications.settings:default_frequency` fallback config key —
  moved the read from `SubscriptionRouter` into `FrequencyResolver`, did not introduce a second
  config key or a new settings form.

## Architecture notes for A

- **Layer touched:** service layer only (`src/Frequency/`, `src/Subscription/`, `src/Queue/`
  interface docblock, DI wiring). No new controller, form, route, or schema.
- **New dependency:** `SubscriptionRouter` now depends on `FrequencyResolver` instead of
  `ConfigFactoryInterface` directly — one hop further removed from raw config access, consistent
  with the brief's stated intent that frequency policy is a distinct concern.
- **No schema/contract change to `QueueBackendInterface`'s method signatures** — only the
  `enqueue()` payload's documented shape gained an optional key (docblock-only change, no
  interface method added/removed). `MockQueueBackend` needed zero code changes (it stores the
  `$item` array verbatim, `send_at` included, and its dedup key already ignores unlisted keys).
- **Shared/other-agent-owned code:** none touched beyond the brief's four named files. N-1's
  `DatabaseQueueBackend` does not exist on origin yet (confirmed not present in this worktree's
  `src/Queue/` at start) — nothing to coordinate with at this time. The brief's DB-schema
  contract (`frequency VARCHAR`, `send_at INT UNSIGNED NULL`, index on `(frequency, send_at)`) is
  documentation-only in this story; N-1 inherits it when it lands.

## Deviations from spec / wireframe

None. No UI surface (task explicitly notes NO D, NO U). Implementation matches the brief's
algorithm, constructor signatures, and enqueue-loop shape verbatim, with the one algebraically
equivalent (verified) simplification to the weekly boundary formula noted above under Design
decisions.

## Tier 1 self-check (incl. tests now GREEN)

Assemble config (always run before any verification):
```
$ ddev exec bash scripts/ci/assemble-config.sh
==> assemble-config: repo root = /var/www/html
==> config: copied 139 file(s), excluded 7 env-specific file(s)
==> modules: copied 16 custom module(s) into web/modules/custom/
==> core.extension: registered custom do_* modules + flag/geofield/language/message/message_notify as enabled
==> assemble-config: done
```

**RED suite now GREEN** — `FrequencyRoutingTest` (5/5 passing):
```
$ ddev exec 'SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://web php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_notifications/tests/src/Kernel/FrequencyRoutingTest.php'

Frequency Routing (Drupal\Tests\do_notifications\Kernel\FrequencyRouting)
 ✔ Immediate frequency stamps request time send at
 ✔ Daily frequency stamps next 2am utc
 ✔ Weekly frequency stamps next sunday evening utc
 ✔ Missing frequency field falls back to site default
 ✔ Mixed recipients produce distinct payloads

OK, but there were issues!
Tests: 5, Assertions: 232, Deprecations: 14.
```
(0 Failures, 0 Errors. The 14 deprecations are the same pre-existing Drupal-core-vs-`flag`-contrib
noise T's RED handoff already cross-checked against `SubscriptionRouterTest`'s 13-deprecation
baseline — 1 extra from `createUserWithFrequency()`'s `$user->set()->save()` reload cycle, exactly
as predicted in `handoff-T-red.md`.)

**Regression — full `do_notifications` Kernel suite** (all 4 test classes, 18 tests total):
```
$ ddev exec 'SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://web php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_notifications/tests/src/Kernel/'

Email Renderer (Drupal\Tests\do_notifications\Kernel\EmailRenderer)
 ✔ Renders all six event types
 ✔ Missing referenced entity renders removed

Frequency Routing (Drupal\Tests\do_notifications\Kernel\FrequencyRouting)
 ✔ Immediate frequency stamps request time send at
 ✔ Daily frequency stamps next 2am utc
 ✔ Weekly frequency stamps next sunday evening utc
 ✔ Missing frequency field falls back to site default
 ✔ Mixed recipients produce distinct payloads

Group Add Notification (Drupal\Tests\do_notifications\Kernel\GroupAddNotification)
 ✔ Node insert records node created event
 ✔ Non eligible node insert records nothing
 ✔ Add to group after creation records event
 ✔ Add node fixture records content and group events
 ✔ Add member records no added to group event
 ✔ Get group ids resolves v 4 relationship type ids

Subscription Router (Drupal\Tests\do_notifications\Kernel\SubscriptionRouter)
 ✔ Router dedups across follow flags
 ✔ Author never enqueued
 ✔ Mute group suppresses
 ✔ Per post state suppression
 ✔ No subscribers produces zero

OK, but there were issues!
Tests: 18, Assertions: 788, Deprecations: 19.
```
(0 Failures, 0 Errors across all 18 tests — `SubscriptionRouterTest`, `EmailRendererTest`, and
`GroupAddNotificationTest` all still pass, confirming no regression from the router's constructor
signature change.)

**PHPCS** on all new/edited production files:
```
$ ddev exec php vendor/bin/phpcs --standard=Drupal,DrupalPractice web/modules/custom/do_notifications/src/Frequency/FrequencyResolver.php web/modules/custom/do_notifications/src/Subscription/SubscriptionRouter.php web/modules/custom/do_notifications/src/Queue/QueueBackendInterface.php web/modules/custom/do_notifications/do_notifications.services.yml

FILE: .../SubscriptionRouter.php
FOUND 0 ERRORS AND 1 WARNING AFFECTING 1 LINE
 69 | WARNING | \Drupal calls should be avoided in classes, use dependency injection instead
```
`FrequencyResolver.php`, `QueueBackendInterface.php`, and `do_notifications.services.yml`: **0
errors, 0 warnings** each (no output block for them — phpcs only prints files with findings).
The single `SubscriptionRouter.php` warning is **pre-existing**, on the `\Drupal::logger(...)`
call inside `route()`'s top-level catch (line 63 pre-#233, shifted to line 69 only by added
docblock lines) — confirmed by placing an `origin/main` baseline copy of the unmodified file at
the real registered path and re-running phpcs: identical warning fires. This is not code I wrote
or touched; it predates #233 (originally from N-2, #230).

## Tests that look wrong (for T)

None.

## Known issues

None. All acceptance criteria 1–7 satisfied:
1. `FrequencyResolver` exists at the specified path; `do_notifications.frequency_resolver`
   registered.
2. `SubscriptionRouter` injects and uses `FrequencyResolver` per recipient (verified: a
   site where every user has the default `immediately` produces the same behavior as before —
   `testMissingFrequencyFieldFallsBackToSiteDefault` and the `immediately` arm of
   `testMixedRecipientsProduceDistinctPayloads` both confirm this).
3. `send_at` is populated on every enqueue (all 5 new tests assert on it; `MockQueueBackend`
   requires no change since it stores payloads verbatim).
4. `FrequencyRoutingTest`'s 5 tests (4a–4e) all pass.
5. `QueueBackendInterface` docblock documents `send_at`, including the dedup-tuple exclusion note.
6. `SubscriptionRouterTest` and `GroupAddNotificationTest` (plus `EmailRendererTest`) all still
   pass — full regression run above.
7. PHPCS clean (0 errors) on all new/edited files; the one warning present is pre-existing,
   unrelated code.

## Files changed

- `docs/groups/modules/do_notifications/src/Frequency/FrequencyResolver.php` (new)
- `docs/groups/modules/do_notifications/src/Subscription/SubscriptionRouter.php` (modified)
- `docs/groups/modules/do_notifications/src/Queue/QueueBackendInterface.php` (modified)
- `docs/groups/modules/do_notifications/do_notifications.services.yml` (modified)
