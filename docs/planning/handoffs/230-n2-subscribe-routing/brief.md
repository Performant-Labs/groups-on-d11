# Brief — N-2 Subscribe Event Routing (#230)

Part of Notifications epic #237. Sibling N-1 (#229, real DatabaseQueueBackend) is blocked on user
decisions until Monday. This story ships an isolated routing layer + a mock queue backend so it can
land independently; the real backend swaps in later via `services.yml`.

## Objective

Route every activity Message (the six do_activity events) to interested subscribers based on
per-user flag subscriptions (`follow_content`, `follow_user`, `follow_term`), deduplicated so a
single user with overlapping subscriptions to the same Message on the same frequency-day gets
exactly one queue entry.

## Design — queue abstraction

New namespace `Drupal\do_notifications\Queue`:

- `QueueBackendInterface` — `enqueue(array $item): void`, `drain(): array` (test helper),
  `count(): int` (test helper). Payload shape:
  `['uid' => int, 'mid' => int, 'template' => string, 'frequency' => string, 'day' => string]`
  (`day` = `date('Y-m-d')` of enqueue time; used only for dedup keying).
- `MockQueueBackend` — in-memory array. Enforces dedup on `(uid, mid, frequency, day)` at
  `enqueue()` time (drop duplicates silently, return void). Ships for tests + local dev.
- `DatabaseQueueBackend` — NOT written here; N-1 lands it. This brief only ensures the interface
  fits the intended DB row (uid, mid, template, frequency, created).
- Service `do_notifications.queue` → `MockQueueBackend` in `do_notifications.services.yml`. A
  comment in the yml notes N-1 will swap the class.

## Design — routing orchestrator

New class `Drupal\do_notifications\Subscription\SubscriptionRouter`:

- Constructor: `EntityTypeManagerInterface`, `FlagServiceInterface` (via `@flag`),
  `QueueBackendInterface` (via `@do_notifications.queue`), `TimeInterface`, `StateInterface`,
  `ConfigFactoryInterface` (to read `do_notifications.settings:default_frequency`).
- Public method: `route(MessageInterface $message): int` — returns the number of queue entries
  written (0 or more). Never throws on missing flags/entities; logs + returns 0.
- Algorithm:
  1. Resolve the referenced entity via `field_referenced_entity_type` + `field_referenced_entity_id`.
     If not loadable → return 0.
  2. Build the candidate-uid set (union):
     - **follow_content**: users flagging the referenced entity IF referenced entity type is one
       that `follow_content` targets (`node`). For `comment` refs, use the commented entity.
     - **follow_user**: users flagging the Message's author (`$message->getOwnerId()`) IF that
       author uid > 0. Also, for `activity_membership_created`, users flagging the new member
       (the referenced user).
     - **follow_term**: users flagging any taxonomy term referenced by the entity's tag fields
       (best-effort: iterate node fields of type `entity_reference` to `taxonomy_term`; empty when
       no such field).
  3. Filter out the Message's own author (never notify yourself).
  4. Filter out any user with `mute_group_notifications` flag on the Message's group (from
     `field_group_id`), when the group is present.
  5. Filter out users where `\Drupal::state()->get('do_notifications_suppress_' . $node_id)` is
     TRUE (per-post opt-out from `DoNotificationsHooks::nodeFormSubmit`) — only when the
     referenced entity is a node.
  6. For each remaining uid, call `queue->enqueue([...])` with frequency from settings
     (`default_frequency`), day = today. The backend enforces per-user dedup.

## Wiring into do_activity

`DoActivityHooks::createMessage()` gets a new optional constructor dep
`?SubscriptionRouter $router = null` (typed nullable, resolved via services.yml). After
`$message->save()`, if `$router` is non-null, call `$router->route($message)`. All 6 event points
go through `createMessage()`, so this single call covers them all.

`do_activity.services.yml` adds `- '@?do_notifications.subscription_router'` as third arg (nullable
so do_activity retains its ability to run without do_notifications enabled).

## Reuse & Analogous-Feature map

- **Analogous feature (extend candidate):** `DoNotificationsHooks::recordEvent()` — already
  enqueues to the core `queue` factory. Extend by fronting it (and the routing) with a swappable
  backend, rather than parallel enqueue paths.
- **Analogous fixture:** `GroupAddNotificationTest::drainQueue()` — mirrors the interface's
  `drain()` shape. Reuse the same "drain-and-assert" idiom in the new kernel test.
- **New objects justified because:**
  - `QueueBackendInterface` — no existing abstraction; Drupal's `QueueInterface` is a poor fit
    (its `claimItem`/`releaseItem` semantics don't match the dedup + per-user broadcast pattern).
  - `SubscriptionRouter` — new concern (subscriber resolution + dedup); does not overlap with
    `DoNotificationsHooks` (which records raw events, not per-user routing).
  - `MockQueueBackend` — needed as the shippable default until N-1.
- **Extend, not fork:** existing `recordEvent()` and `groupRelationshipInsert()` keep writing to
  the core queue unchanged (out of scope for this story per issue framing — this story adds
  Message-based routing off the do_activity hook path, not a rewrite of the existing content
  event queue).

## Forward-compat check

N-1 (#229) will write `DatabaseQueueBackend implements QueueBackendInterface`. The interface's
three methods map cleanly to a DB implementation: `enqueue` = INSERT with
`ON DUPLICATE KEY (uid, mid, frequency, day) DO NOTHING`; `drain`/`count` = SELECT for tests. No
conflict.

## Acceptance criteria

1. `QueueBackendInterface`, `MockQueueBackend` exist under `do_notifications/src/Queue/`.
2. Service `do_notifications.queue` resolves to `MockQueueBackend`.
3. `SubscriptionRouter` exists under `do_notifications/src/Subscription/`, service
   `do_notifications.subscription_router`.
4. `DoActivityHooks::createMessage()` invokes the router (when injected) after every Message save.
5. Kernel test (new file
   `do_notifications/tests/src/Kernel/SubscriptionRouterTest.php`) covering the
   3-Messages × 5-subscribers → 5-entries dedup scenario **plus**:
   - Message author is never enqueued for their own Message.
   - `mute_group_notifications` on the Message's group suppresses that user.
   - Per-post suppression state key suppresses all routing for that node.
6. Existing `GroupAddNotificationTest` still passes (regression).
7. PHPCS clean on all new/edited files.

## Handoff locations

- Handoffs: `docs/planning/handoffs/230-n2-subscribe-routing/`
- Decision journal: same dir, `decisions.md`

## Review rigor

`none` (POC lean pipeline).

## Branch

`230-n2-subscribe-routing` (tracking `origin/main`), worktree at
`C:/Users/aange/Projects/_worktrees/groups-n2-subscribe-routing-230`.
