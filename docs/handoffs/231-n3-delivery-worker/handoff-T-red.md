# Handoff-T-red: Phase 4 - #231 N-3 Notification delivery worker

**Date:** 2026-07-26
**Branch:** 231-n3-delivery-worker
**Brief / wireframe reviewed:** `docs/handoffs/231-n3-delivery-worker/brief.md`, `docs/handoffs/231-n3-delivery-worker/decisions.md`, `docs/handoffs/231-n3-delivery-worker/handoff-A.md` (no wireframe — CLI-only feature, no UI surface).

## A precondition

Confirmed: A returned **PASS** on the plan (Phase 3, `handoff-A.md`) — no findings blocked the plan; three `warn`-severity notes (drain() docblock, digest mid-collapse lossiness, no local drush precedent) were advisory only. Proceeding to author RED.

## Tests authored

All in `docs/groups/modules/do_notifications/tests/src/Kernel/DeliveryWorkerTest.php`, extending `GroupsKernelTestBase` (mirrors `EmailRendererTest`/`DigestRendererTest`'s module list — `do_activity` hard-depends on `group`/`gnode`/`node`/`comment`, so those had to be included even though this story's own logic only touches queue -> renderer -> LogMailer).

| Test | Criterion pinned | Tier | Why this tier |
|---|---|---|---|
| `testDeliverImmediatelyTypeDrainsOnlyImmediatelyEntries` | AC #6 golden path: `--type=immediately` delivers only immediately entries (3 file-sink lines + 3 watchdog rows) and leaves the 2 daily entries queued. | Kernel | Exercises the real DB-backed queue, real Message/User entities, and the real `log_mailer` plugin manager end-to-end — no lower tier can pin the full pipeline wiring. |
| `testDeliverAllTypeDrainsEverythingIncludingDigests` | AC #2/#3: default (`all`) type drains every frequency; immediately entries deliver individually, daily/weekly entries collapse into ONE digest delivery per recipient per window; queue ends empty. | Kernel | Same reasoning; also asserts the digest template naming (`digest_daily`/`digest_weekly`) end-to-end. |
| `testDeliverIsIdempotentOnEmptyQueue` | AC #4: two runs against an empty queue both exit cleanly and write nothing new. | Kernel | Requires a real queue table to prove "empty" and real watchdog/file-sink state across two invocations — not mockable at a cheaper tier without losing the real-DB guarantee. |
| `testDeliverSkipsMissingMessageWithWarning` | AC #5: a drained entry whose Message no longer exists is skipped (no delivery), still drained (queue count 0), and logs exactly one `do_notifications` watchdog WARNING (severity 4). | Kernel | Needs the real queue + real watchdog table to distinguish "skipped safely" from "silently stuck" or "fatally errored." |
| `testDeliverGroupsDailyDigestByRecipient` | AC #3 grouping: N daily entries per recipient collapse to exactly one LogMailer call per recipient, using `mid` = the FIRST message id enqueued for that recipient. | Kernel | Grouping-by-recipient is a cross-cutting behavior across the queue's drain shape and the digest renderer's aggregation — only observable by running the whole worker against real enqueued rows. |

No test duplicates another: each pins a distinct acceptance criterion (frequency filter / full drain+digest / idempotency / missing-record skip / grouping-by-recipient). No unit-tier substitute exists because the worker's entire job is wiring four real subsystems together — testing it with mocks would pin the mock wiring, not the real contract.

## RED confirmation

Assembled first (source `docs/groups/modules/*` -> `web/modules/custom/*`, source of truth for CI parity):

```
ddev exec bash scripts/ci/assemble-config.sh
```

Ran against the assembled layout with `SIMPLETEST_DB` set (DDEV's default DB creds — CI sets the equivalent via its `mysql` service):

```
ddev exec 'SIMPLETEST_DB="mysql://db:db@db:3306/db" php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_notifications/tests/src/Kernel/DeliveryWorkerTest.php'
```

Result: **5 tests, 5 errors** (RED), each failing with the SAME right-reason error — the worker's not-yet-created service:

```
Delivery Worker (Drupal\Tests\do_notifications\Kernel\DeliveryWorker)
 ✘ Deliver immediately type drains only immediately entries
   │
   │ Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException: You have requested a non-existent service "do_notifications.deliver_commands".
   │
   │ .../DeliveryWorkerTest.php:131 (commands())
   │ .../DeliveryWorkerTest.php:144 (deliver())
   │ .../DeliveryWorkerTest.php:262 (test body)

 ✘ Deliver all type drains everything including digests
   │ (same ServiceNotFoundException, do_notifications.deliver_commands)

 ✘ Deliver is idempotent on empty queue
   │ (same ServiceNotFoundException, do_notifications.deliver_commands)

 ✘ Deliver skips missing message with warning
   │ (same ServiceNotFoundException, do_notifications.deliver_commands)

 ✘ Deliver groups daily digest by recipient
   │ (same ServiceNotFoundException, do_notifications.deliver_commands)

Tests: 5, Assertions: 152, Errors: 5, Deprecations: 13.
```

**Why this is a valid RED, not a setup bug:** every `setUp()` step (installing `do_notifications_queue`/`watchdog` schemas, `installConfig(['do_activity'])`, creating the `comment_activity` bundle, `GroupsKernelTestBase`'s own group-type/relationship-type reconstruction) completes without error in all 5 tests — the container boots, entities save, and the queue accepts real enqueue() calls (152 assertions ran before the container lookup failure, confirming fixture setup executes). The failure occurs at the ONE call each test makes into not-yet-implemented code: `\Drupal::service('do_notifications.deliver_commands')`, called from the test's `commands()`/`deliver()` helpers. This is exactly the seam F is implementing (`DoNotificationsCommands` + `drush.services.yml`) — a missing-service error at that exact call site, not an import/typo/schema error, is the correct RED signature for "the feature under test does not exist yet."

The 13 deprecation notices (Flag's Twig extension, `@EntityType`/`@Action` annotation discovery, `ConfigEntityBase::trustData()`) are pre-existing framework/contrib noise from `flag`/core, identical in kind to what other suites in this module already trigger (see `SubscriptionRouterTest`'s neighbors) — unrelated to this story and already tolerated by CI's `SYMFONY_DEPRECATIONS_HELPER=disabled`.

## Ready for F

**Confirmed RED is valid.** F may implement against this suite:
- `Drupal\do_notifications\Drush\Commands\DoNotificationsCommands` with a public `deliver(array $options = ['type' => 'all'])` method (Drush 13 option-array convention), registered as service id `do_notifications.deliver_commands` in a new `do_notifications/drush.services.yml`.
- `QueueBackendInterface::drain(?string $frequency = null): array` extended on both `DatabaseQueueBackend` and `MockQueueBackend` (BC-preserving; per A's finding #1, also update the interface docblock to describe the optional filter).
- The worker must: filter-drain by `--type`, deliver `immediately` entries individually via `EmailRenderer::render()` + `log_mailer`, group `daily`/`weekly` entries by recipient and deliver via `DigestRenderer::render()` + `log_mailer` (one call per recipient, `mid` = first message id, `template` = `digest_daily`/`digest_weekly`), skip-with-WARNING-watchdog any entry whose Message or recipient no longer loads, and be a clean no-op on an empty queue.

Staged file (by explicit path, not `git add .`):
```
docs/groups/modules/do_notifications/tests/src/Kernel/DeliveryWorkerTest.php
```
