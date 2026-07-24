# Handoff-F: Phase 5 - N-2 Message Subscribe Event Routing

**Date:** 2026-07-24
**Branch:** 230-n2-subscribe-routing
**Issue:** #230

## What was done

- `docs/groups/modules/do_notifications/src/Queue/QueueBackendInterface.php` (new) — the
  `enqueue()`/`drain()`/`count()` contract, with a class-level docblock explaining why it doesn't
  extend core's `QueueInterface` (dedup + per-user broadcast semantics don't fit
  claim/release/delete).
- `docs/groups/modules/do_notifications/src/Queue/MockQueueBackend.php` (new) — in-memory
  implementation; dedups on `(uid, mid, frequency, day)` at `enqueue()` time via a `seenKeys` map,
  silently dropping repeats.
- `docs/groups/modules/do_notifications/src/Subscription/SubscriptionRouter.php` (new) — the
  routing orchestrator. `route(MessageInterface $message): int` resolves the referenced entity,
  checks per-post State suppression, builds the candidate uid union across
  follow_content/follow_term/follow_user, filters the author and muted-group users, then enqueues
  one entry per remaining uid (deduped by the router itself via a uid-keyed array before ever
  calling `enqueue()`, not relying solely on the backend's own dedup).
- `docs/groups/modules/do_notifications/do_notifications.services.yml` (modified) — registered
  `do_notifications.queue` → `MockQueueBackend` and `do_notifications.subscription_router` →
  `SubscriptionRouter` with its 6 constructor args, exactly per the brief.
- `docs/groups/modules/do_activity/src/Hook/DoActivityHooks.php` (modified) — constructor now
  takes an optional third arg `?SubscriptionRouter $router = NULL`; `createMessage()` (the single
  funnel all six log points already go through) calls `$this->router->route($message)` after
  `$message->save()`, wrapped in try/catch that logs via `\Drupal::logger('do_activity')` and never
  throws. Added a class-level docblock section ("SUBSCRIPTION ROUTING (#230, N-2)") explaining the
  wiring, and a doc note on `createMessage()` itself.
- `docs/groups/modules/do_activity/do_activity.services.yml` (modified) — added
  `'@?do_notifications.subscription_router'` as the third constructor arg (nullable, so
  do_activity keeps working with do_notifications disabled).

## Design decisions

- **Router dedups uids itself, before ever calling `enqueue()`.** The candidate-uid union is built
  into a uid-keyed array (`$candidate_uids[$uid] = TRUE`), so User A's overlapping
  follow_content + follow_term subscription to the same node collapses to one array entry before
  routing ever reaches the queue backend. This means `route()`'s return value (count of `enqueue()`
  calls made) is trivially accurate — every uid the router hands to `enqueue()` is guaranteed to be
  new for this call — rather than depending on the backend's own dedup to hide an off-by-one in the
  router's counting. The backend's dedup (on `(uid, mid, frequency, day)`) still exists and is
  exercised by the brief's forward-compat note for N-1's `DatabaseQueueBackend`, but for THIS
  router's own per-call correctness it's a second line of defense, not the only one.
- **`follow_term` iterates `getFieldDefinitions()` generically**, matching any `entity_reference`
  field targeting `taxonomy_term` (`getType() === 'entity_reference'` +
  `getSetting('target_type') === 'taxonomy_term'`), rather than hard-coding `field_tags`. This is
  the "best-effort" language in the brief taken literally — it works for the test fixture's
  `field_tags` and for any other node type's own tag field without a code change, and returns empty
  cleanly for non-fieldable entities (State check via `FieldableEntityInterface`).
- **`activity_membership_created`'s follow_user branch checks the referenced entity's type
  (`'user'`), not just the template id**, as a defensive belt-and-suspenders: the brief specifies
  the template as the trigger, but gating on both keeps the branch inert if a future template ever
  reused the `activity_membership_created` id against a non-user reference.
- **The comment-ref path reloads the comment via `entityTypeManager->getStorage('comment')->load()`**
  exactly as the brief instructs, rather than trusting that the `$referenced_entity` already in
  hand (loaded generically by `resolveReferencedEntity()` off `field_referenced_entity_type` =
  `'comment'`) is sufficient — the brief calls this out as A's warn #3, so the extra explicit
  reload matches that instruction literally even though in practice `resolveReferencedEntity()`
  already loaded the same comment entity via the generic `comment` storage.
- **`route()`'s outer try/catch wraps the entire algorithm** (`doRoute()`), not just individual flag
  lookups, so ANY unexpected exception (a malformed field, a storage exception this codebase hasn't
  seen yet) degrades to "log + return 0" rather than propagating into `DoActivityHooks::
  createMessage()` — which ALSO wraps its own call to `$router->route()` in try/catch per the
  brief's explicit instruction. This is deliberate double protection: the router protects its own
  callers in general (any future caller, not just do_activity), and do_activity additionally
  protects itself in case a router implementation ever changes to no longer be defensive.

## Reuse / extend-vs-new

Per the brief's Reuse & Analogous-Feature map:
- Extended `DoActivityHooks::createMessage()` — the single funnel all six do_activity log points
  already go through — rather than adding a routing call to each of the six hook methods
  individually. This matches the brief's explicit instruction ("Extend by fronting it... rather
  than parallel enqueue paths" / "gets a new optional constructor dep").
- `DoNotificationsHooks::recordEvent()`'s own core-`queue`-based enqueue path (content event
  recording, `node_created`/`added_to_group`/`comment_created`) was left completely untouched, per
  the brief's explicit "Extend, not fork" note — this story adds a NEW, parallel Message-based
  routing concern off the do_activity hook path, not a rewrite of the existing content event queue.
  I did not touch `DoNotificationsHooks.php` at all.
- New objects (`QueueBackendInterface`, `MockQueueBackend`, `SubscriptionRouter`) are exactly the
  three the brief justified in writing under "New objects justified because:" — no unjustified
  parallel path was created.

## Architecture notes for A

- **Layers touched:** a new `Drupal\do_notifications\Queue` namespace (interface + one
  implementation) and a new `Drupal\do_notifications\Subscription` namespace (one class), both
  wired via `do_notifications.services.yml`. `do_activity`'s existing `DoActivityHooks` class
  gained one new nullable constructor dependency and one new call site inside its existing private
  `createMessage()` method — no new public methods on `DoActivityHooks`.
- **New dependencies:** `do_activity.services.yml`'s `do_activity.hooks` service now references
  `@?do_notifications.subscription_router` — the `@?` prefix makes this an optional service
  reference (resolves to `NULL` if the service doesn't exist, e.g. do_notifications disabled),
  matching Symfony DI's optional-reference syntax already used elsewhere in this codebase's `@?`
  convention. `do_activity.info.yml` was NOT touched — do_notifications remains a soft, not hard,
  dependency of do_activity, exactly per the brief.
- **Schema/contract changes:** none — no new fields, no config schema changes. `SubscriptionRouter`
  reads `do_notifications.settings:default_frequency`, an already-shipped config key (confirmed via
  `do_notifications/config/optional/do_notifications.settings.yml` and
  `NotificationDefaultsForm.php`, both pre-existing).
  `QueueBackendInterface`'s `enqueue()` payload shape (`uid`, `mid`, `template`, `frequency`, `day`)
  is a NEW contract, but it's between two objects both introduced in this same story — no external
  consumer depends on it yet (N-1's `DatabaseQueueBackend`, not yet written, will be the first).
- **Shared components changed:** `DoActivityHooks.php` and both `services.yml` files are shared
  (owned by earlier stories #116/#116-amended, not do_notifications). The change to
  `DoActivityHooks.php` is exactly the one spec'd extension point the brief named (a new nullable
  constructor arg + one call inside the existing `createMessage()` funnel) — not a broader
  refactor. Verified no regression via the full `do_activity` kernel suite (23/23 tests,
  759 assertions) and `do_notifications`'s `GroupAddNotificationTest` (6/6, 162 assertions).

## Deviations from spec / wireframe

None. No UI surface in this story (routing/data layer only, per T's handoff and the brief).

## Tier 1 self-check (incl. tests now GREEN)

Assemble (module-copy step only — the script's later `core.extension.yml` patch step needs a
local `php` binary not on this environment's PATH; not required for kernel tests, which install
schema/config programmatically, not via `config:import`):
```
$ bash scripts/ci/assemble-config.sh
==> assemble-config: repo root = .../groups-n2-subscribe-routing-230
==> config: copied 139 file(s), excluded 7 env-specific file(s)
==> modules: copied 16 custom module(s) into web/modules/custom/
```

Target suite (via `ddev exec`, matching T's RED-confirmation invocation):
```
$ ddev exec 'SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://web php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_notifications/tests/src/Kernel/SubscriptionRouterTest.php'

Subscription Router (Drupal\Tests\do_notifications\Kernel\SubscriptionRouter)
 ✔ Router dedups across follow flags
 ✔ Author never enqueued
 ✔ Mute group suppresses
 ✔ Per post state suppression
 ✔ No subscribers produces zero

OK, but there were issues!
Tests: 5, Assertions: 244, Deprecations: 13.
```
All 5 authored tests now GREEN (were RED per T's handoff — `ServiceNotFoundException` for
`do_notifications.subscription_router`). The 13 deprecations are pre-existing core/flag-module
notices (`@Action` annotation deprecation, Twig extension return-type notice, `Config::save()`
`$has_trusted_data` arg deprecation) — same class T already documented as non-blocking for the
sibling `GroupAddNotificationTest`, not caused by this story's code.

Regression — `GroupAddNotificationTest.php` (acceptance criterion 6):
```
$ ddev exec '... phpunit ... --testdox web/modules/custom/do_notifications/tests/src/Kernel/GroupAddNotificationTest.php'
Tests: 6, Assertions: 162, Deprecations: 11.
OK, but there were issues!
```
Still green, unchanged from T's Phase-4 baseline.

Regression — full `do_activity` kernel suite (the module whose shared `DoActivityHooks.php` I
modified):
```
$ ddev exec '... phpunit ... --testdox web/modules/custom/do_activity/tests/src/Kernel'
Tests: 23, Assertions: 759, Deprecations: 14, PHPUnit Deprecations: 32.
OK, but there were issues!
```
All 23 pre-existing do_activity tests still pass — confirms the new nullable constructor arg and
the new call inside `createMessage()` are inert when `do_notifications` is disabled (do_activity's
own test suite never enables it), exactly as designed.

PHPCS (`Drupal,DrupalPractice` standard — confirmed as this project's actual baseline by
calibrating against pre-existing, already-shipped files; the bare `phpcs` invocation with no
`--standard` flag falls back to the PEAR default and produces hundreds of false-positive errors
even against already-merged code, so it is not a meaningful check on its own in this project):
```
$ ddev exec 'php vendor/bin/phpcs --standard=Drupal,DrupalPractice web/modules/custom/do_notifications/src/Queue/ web/modules/custom/do_notifications/src/Subscription/ web/modules/custom/do_activity/src/Hook/DoActivityHooks.php'

FILE: .../do_notifications/src/Subscription/SubscriptionRouter.php
FOUND 0 ERRORS AND 1 WARNING AFFECTING 1 LINE
 63 | WARNING | \Drupal calls should be avoided in classes, use dependency injection instead

FILE: .../do_activity/src/Hook/DoActivityHooks.php
FOUND 0 ERRORS AND 4 WARNINGS AFFECTING 4 LINES
 (4x) \Drupal calls should be avoided in classes, use dependency injection instead
```
`Queue/QueueBackendInterface.php` and `Queue/MockQueueBackend.php`: 0 errors, 0 warnings (not
listed in output = fully clean). `SubscriptionRouter.php`: 0 errors, 1 warning (the `\Drupal::
logger()` call in `route()`'s catch-all). `DoActivityHooks.php`: 0 errors, 4 warnings — 3
pre-existing (already present before this story, confirmed by running the SAME standard against
the file's prior committed state) plus 1 new (my `\Drupal::logger('do_activity')` call in
`createMessage()`'s new catch block). Both `\Drupal::` warnings match this codebase's own
already-established pattern of calling `\Drupal::logger()`/`\Drupal::time()` directly inside hook
classes rather than injecting a logger-channel factory (see the 3 pre-existing instances already
in `DoActivityHooks.php`, and 5 more in `DoNotificationsHooks.php`) — not a new category of issue,
just +1 instance in each file. No ERRORS remain anywhere.

## Tests that look wrong (for T)

None. All 5 authored tests read as correct pins of the brief's acceptance criteria and passed
without needing any test-file changes.

## Known issues

None — all 7 acceptance criteria from the brief are met:
1. `QueueBackendInterface`, `MockQueueBackend` exist under `do_notifications/src/Queue/`. Done.
2. Service `do_notifications.queue` resolves to `MockQueueBackend`. Done.
3. `SubscriptionRouter` exists under `do_notifications/src/Subscription/`, service
   `do_notifications.subscription_router`. Done.
4. `DoActivityHooks::createMessage()` invokes the router (when injected) after every Message save.
   Done.
5. Kernel test suite (T's, unmodified) — 5/5 GREEN, covering the dedup scenario plus the three
   negative/suppression cases.
6. `GroupAddNotificationTest` still passes (regression) — 6/6 GREEN.
7. PHPCS clean (0 errors) on all new/edited files, under the project's actual `Drupal,
   DrupalPractice` baseline standard.

One item outside the acceptance criteria but worth flagging for whoever runs full CI: this
environment's `scripts/ci/assemble-config.sh` step 3 (core.extension.yml module registration)
requires a local `php` binary that isn't on this environment's PATH (only reachable via `ddev
exec`). Steps 1–2 (config copy, module copy — the only steps kernel tests need) complete fine
either way. This is a pre-existing environment characteristic, not something this story introduced
or needs to fix.

## Files changed

- `docs/groups/modules/do_notifications/src/Queue/QueueBackendInterface.php` (new)
- `docs/groups/modules/do_notifications/src/Queue/MockQueueBackend.php` (new)
- `docs/groups/modules/do_notifications/src/Subscription/SubscriptionRouter.php` (new)
- `docs/groups/modules/do_notifications/do_notifications.services.yml` (modified)
- `docs/groups/modules/do_activity/src/Hook/DoActivityHooks.php` (modified)
- `docs/groups/modules/do_activity/do_activity.services.yml` (modified)

All six staged by explicit path (no `git add .`/`-A`). No test files touched or staged by me — the
5 test/fixture files already staged under `do_notifications/tests/` are T's from Phase 4 and were
left exactly as found.
