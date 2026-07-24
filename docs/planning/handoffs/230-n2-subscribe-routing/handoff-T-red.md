# Handoff-T-red: Phase 4 - N-2 Subscribe Event Routing (#230)

**Date:** 2026-07-24
**Branch:** 230-n2-subscribe-routing
**Brief / wireframe reviewed:** `docs/planning/handoffs/230-n2-subscribe-routing/brief.md` (no
wireframe — this story is a routing/data layer, no UI surface per the brief).

## A precondition

`none` (POC lean pipeline per the brief's "Review rigor" section) — no A gate for this story.
Proceeding directly from the approved brief.

## Tests authored

New file: `docs/groups/modules/do_notifications/tests/src/Kernel/SubscriptionRouterTest.php`
(kernel tier — the router touches real entity storage, flag queries, config, and State; a unit
test would need to fake too much of that to be meaningful, and there's no UI/HTTP surface to
justify functional/e2e).

1. **`testRouterDedupsAcrossFollowFlags()`** — the brief's central acceptance case: 3
   `activity_post_created` Messages (one per node/author), 5 subscribers total, where User A
   double-subscribes to the same Message via both `follow_content` and `follow_term` (overlap).
   Pins: subscriber-set resolution across all three follow flags, AND the dedup collapsing an
   overlapping subscription to one queue entry (not two). Asserts the queue drains to exactly 5
   items, the exact 5 uids, and the payload shape (`uid`, `mid`, `template`, `frequency`, `day`).

2. **`testAuthorNeverEnqueued()`** — a Message's own author, even when they follow the referenced
   node, is never enqueued. Pins the "never notify yourself" filter.

3. **`testMuteGroupSuppresses()`** — a subscriber who both follows the referenced node AND flags
   the Message's `field_group_id` group with `mute_group_notifications` gets 0 entries. Pins the
   group-mute suppression filter.

4. **`testPerPostStateSuppression()`** — `\Drupal::state()->set('do_notifications_suppress_<nid>',
   TRUE)` on the referenced node suppresses ALL routing for it, even for an otherwise-eligible
   `follow_content` subscriber. Pins the per-post State suppression filter.

5. **`testNoSubscribersProducesZero()`** — a Message referencing a node nobody follows returns 0
   and leaves the queue empty. Pins the "no candidates" baseline/negative case.

Each test asserts against `route(): int`'s return value AND the drained/counted `do_notifications.
queue` service contents — behavior (what got enqueued, in what shape), not `SubscriptionRouter`'s
internal implementation.

## RED confirmation

Run (from the worktree, via ddev — `SIMPLETEST_DB`/`SIMPLETEST_BASE_URL` required for kernel
tests outside `run-tests.sh`, see `scripts/dev/run-kernel.sh`):

```
bash scripts/ci/assemble-config.sh
ddev exec 'SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://web php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_notifications/tests/src/Kernel/SubscriptionRouterTest.php'
```

Output (all 5 fail for the same, correct reason — the service does not exist yet):

```
Subscription Router (Drupal\Tests\do_notifications\Kernel\SubscriptionRouter)
 ✘ Router dedups across follow flags
   │
   │ Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException: You have requested a non-existent service "do_notifications.subscription_router".
   │
   │ .../SubscriptionRouterTest.php:285
 ✘ Author never enqueued
   │ Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException: You have requested a non-existent service "do_notifications.subscription_router".
   │ .../SubscriptionRouterTest.php:338
 ✘ Mute group suppresses
   │ Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException: You have requested a non-existent service "do_notifications.subscription_router".
   │ .../SubscriptionRouterTest.php:373
 ✘ Per post state suppression
   │ Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException: You have requested a non-existent service "do_notifications.subscription_router".
   │ .../SubscriptionRouterTest.php:408
 ✘ No subscribers produces zero
   │ Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException: You have requested a non-existent service "do_notifications.subscription_router".

Tests: 5, Assertions: 0, Errors: 5.
```

This is a **valid RED**: every test reaches the router call (all fixture/setup machinery —
flags, node/term/group creation, the `activity_post_created` Message, the queue service lookup
attempt — runs cleanly first) and fails ONLY because `do_notifications.subscription_router` and
`do_notifications.queue` are not yet registered services (`SubscriptionRouter`,
`QueueBackendInterface`/`MockQueueBackend` don't exist yet per the brief). Not an import/typo/setup
error.

**Regression check (existing suite, unaffected by this test file):**
`GroupAddNotificationTest.php` — 6 tests, 162 assertions, **still green** (`OK, but there were
issues!` refers only to pre-existing unrelated core deprecation notices, zero failures/errors).

## Setup notes / pre-existing gotchas discovered (not blocking, informational for F)

- **`flagTypeConfig.access_author` schema gap:** `do_notifications`'s shipped
  `flag.flag.follow_content.yml` / `follow_term.yml` / `follow_user.yml` each carry a
  `flagTypeConfig.access_author` key with no matching entry in the `flag` contrib module's
  `flag.schema.yml` (`flag.flag_type.plugin.entity:*` mapping). This trips strict config-schema
  checking under `KernelTestBase`. This is a **pre-existing gap in the shipped config**, unrelated
  to this story — `do_streams`' `StreamsScopeTest`/`FollowingFeedTest` already carry the identical
  workaround for the SAME shipped flags. This test's fixtures (module-local, at
  `do_notifications/tests/fixtures/config/`, byte-identical copies of the shipped
  `config/optional/*.yml`) are installed via `FileStorage::read()` + manual
  `unset($values['flagTypeConfig']['access_author'])` + `->create()->save()`, not
  `installConfig(['do_notifications'])`.
- **`installConfig()`'s cross-module optional-config side effect:** `installConfig(['do_activity'])`
  (needed for do_activity's own Message templates) uses `DefaultConfigMode::All` by default, which
  also runs `ConfigInstaller::createSiteOptionalConfig()` — this scans **every already-enabled
  module's** `config/optional/` directory (not just `do_activity`'s), so it can silently
  auto-install `do_notifications`'s `mute_group_notifications` flag (the one of the four with no
  `access_author` key, so it alone passes schema validation) before the test's own fixture-install
  loop runs. The loop is therefore **load-or-create** (skips any flag id already present), not a
  blind create — otherwise it throws `EntityStorageException: 'flag' entity ... already exists.`

Both notes are informational context for F/S, not blockers — the test suite handles both
correctly and is now a valid, stable RED.

## Ready for F

Confirmed RED is valid. F may implement `QueueBackendInterface`, `MockQueueBackend`,
`SubscriptionRouter`, the two service registrations, and the `DoActivityHooks::createMessage()` /
`do_activity.services.yml` wiring against this suite.
