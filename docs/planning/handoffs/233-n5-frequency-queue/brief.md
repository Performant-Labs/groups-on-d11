# Brief — N-5 Frequency Queue Logic (#233)

Part of Notifications epic #237. N-2 (#230, SubscriptionRouter + MockQueueBackend) has landed.
N-1 (#229, DatabaseQueueBackend) is WIP on a local branch, not on origin; no coordination race
yet.

## Objective

In `SubscriptionRouter`, branch enqueue payload per **recipient** by their
`field_notification_frequency`:

- `immediately` → `frequency='immediately'`, `send_at = <request time>`
- `daily` → `frequency='daily'`, `send_at = <next 2 AM UTC>`
- `weekly` → `frequency='weekly'`, `send_at = <next Sunday 7 PM UTC>`

Missing/empty user field → site-wide `do_notifications.settings:default_frequency` (which is
itself `immediately`). Bad user load → same fallback (never fail routing on a bad user).

All times UTC. Times are `int` unix timestamps (matches Drupal `getRequestTime()` convention).

## Design — FrequencyResolver

New class `Drupal\do_notifications\Frequency\FrequencyResolver`:

- Constructor: `EntityTypeManagerInterface`, `TimeInterface`, `ConfigFactoryInterface`.
- Public method: `resolve(int $uid): array` returning `['frequency' => string, 'send_at' => int]`.
- Algorithm:
  1. Load user by uid. Fail-soft: any exception → use site-wide default.
  2. Read `field_notification_frequency` value if the field exists and is non-empty. Else use
     site-wide default (`do_notifications.settings:default_frequency`, itself defaulting to
     `immediately` on missing config).
  3. Compute `send_at`:
     - `immediately` → `$time->getRequestTime()`.
     - `daily` → next 2 AM UTC strictly after now. If it's currently 01:59:59 UTC, that's today
       at 02:00; if 02:00:01 UTC, that's tomorrow at 02:00.
     - `weekly` → next Sunday 19:00 UTC strictly after now.
  4. Return `['frequency' => $frequency, 'send_at' => $send_at]`.
- Boundary discipline: `send_at` is **strictly greater than now** for `daily`/`weekly` (a digest
  worker running exactly at 2 AM must NOT be triggered by yesterday's enqueue landing at 2 AM;
  we want it to pick up the batch on today's run).
- Wall-clock isolation: uses injected `TimeInterface` only — no `time()` or `new \DateTime()`
  calls without the injected clock.

## Design — SubscriptionRouter change

`SubscriptionRouter`:

- Add constructor arg: `FrequencyResolver $frequencyResolver`.
- Remove the single-shot `$frequency` read from the settings config in `doRoute()`.
- Replace the per-uid enqueue loop with:
  ```php
  foreach (array_keys($candidate_uids) as $uid) {
    $resolved = $this->frequencyResolver->resolve($uid);
    $this->queue->enqueue([
      'uid' => $uid,
      'mid' => $mid,
      'template' => $template,
      'frequency' => $resolved['frequency'],
      'day' => $day,
      'send_at' => $resolved['send_at'],
    ]);
    $count++;
  }
  ```
- `$day` remains today's `Y-m-d` (still part of the dedup key — a `daily` recipient's overlapping
  subscription within the same UTC day still collapses to one entry).

## Design — payload extension

`QueueBackendInterface` docblock adds:

- `send_at` (int, optional): unix timestamp at which the delivery/digest worker should include
  this entry. `MockQueueBackend` stores it verbatim. `DatabaseQueueBackend` (N-1) MUST persist
  it as an indexed column so digest workers can query `WHERE frequency = 'daily' AND send_at <= NOW()`.

**Contract for N-1:** the DB queue schema must include:
- `frequency VARCHAR(16) NOT NULL DEFAULT 'immediately'`
- `send_at INT UNSIGNED NULL` (nullable — future backfill safety; N-5 always sets it)
- Index on `(frequency, send_at)` for digest-worker query performance

If N-1 lands first without these, N-5 rebases and adds them in a follow-on `hook_update_N` in
`do_notifications.install`. If N-5 lands first, N-1 inherits (schema not yet built).

## Wiring

`do_notifications.services.yml`:

```yaml
  do_notifications.frequency_resolver:
    class: Drupal\do_notifications\Frequency\FrequencyResolver
    arguments:
      - '@entity_type.manager'
      - '@datetime.time'
      - '@config.factory'
```

Router service gains `- '@do_notifications.frequency_resolver'` as its seventh argument.

## Reuse & Analogous-Feature map

- **Extend:** `SubscriptionRouter` (add one dep, replace a 2-line loop body).
- **New service:** `FrequencyResolver` — justified because (a) the frequency policy is a distinct
  concern (per-user preference + boundary time math) that will be reused by digest workers'
  next-run scheduling (N-6/N-7 may want the same "next 2 AM UTC" helper); (b) it's cleanly
  unit-testable in isolation without a router, message, or queue setup.
- **Preexisting field:** `field_notification_frequency` on user, already installed via
  `docs/groups/config/`. Do NOT create a second field.
- **Preexisting fallback:** `do_notifications.settings:default_frequency`, already read by
  today's router. Keep as fallback path.

## Forward-compat check (downstream)

- **N-3 delivery worker** (#231): claims `WHERE frequency='immediately' AND send_at <= NOW()`. ✓
- **N-6 daily digest** (#234): claims `WHERE frequency='daily' AND send_at <= NOW()`, then
  aggregates. ✓
- **N-7 weekly digest** (#235): claims `WHERE frequency='weekly' AND send_at <= NOW()`. ✓
- Interface's dedup key `(uid, mid, frequency, day)` is UNCHANGED. A user who changes frequency
  mid-day will get a **new** entry (different `frequency` in the key). Acceptable — worst case
  a small duplication for the exact Message hit twice; ordinary users don't oscillate.

## Acceptance criteria

1. `Drupal\do_notifications\Frequency\FrequencyResolver` exists under
   `docs/groups/modules/do_notifications/src/Frequency/`, service
   `do_notifications.frequency_resolver` registered.
2. `SubscriptionRouter` injects and uses `FrequencyResolver` per recipient. No behavior change
   for a site where every user has the default `immediately`.
3. Payload's `send_at` key is populated on every enqueue.
4. Kernel test `FrequencyRoutingTest` (new file) covers:
   a. Immediate user → `send_at` = request time; `frequency='immediately'`.
   b. Daily user → `send_at` = next 2 AM UTC strictly after request time.
   c. Weekly user → `send_at` = next Sun 19:00 UTC strictly after request time.
   d. User with missing field → falls back to `immediately` (site default).
   e. Three recipients with three different frequencies → three distinct payloads on one route.
5. `QueueBackendInterface` docblock documents the `send_at` optional key.
6. Existing `SubscriptionRouterTest` and `GroupAddNotificationTest` still pass (regression).
7. PHPCS clean on all new/edited files.

## Handoff locations

- Handoffs: `docs/planning/handoffs/233-n5-frequency-queue/`
- Decision journal: same dir, `decisions.md`

## Review rigor

`none` (POC lean pipeline — issue directive).

## Pipeline

O → A → T(RED) → F → T(GREEN) → diff-gate → S → rebase → PR → CI-green → self-merge.
NO D, NO U (no UI surface). Foreground CI polling. Widened autonomy — self-merge on green.

## Branch / worktree / container

- Branch: `233-n5-frequency-queue` (tracks `origin/main`, base `9bca6e4`)
- Worktree: `C:/Users/aange/Projects/_worktrees/groups-n5-frequency-queue-233`
- DDEV namespace: `gm233-freq`

## PR body

`Closes #233. Part of epic #237.`
