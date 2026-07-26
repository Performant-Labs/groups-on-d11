# Survey — N-5 Frequency Queue Logic (#233)

## Existing objects

- **`SubscriptionRouter`** (`docs/groups/modules/do_notifications/src/Subscription/SubscriptionRouter.php`):
  the routing orchestrator N-2 built. Today it reads a single site-wide `default_frequency` from
  `do_notifications.settings` and stamps every enqueued item with it. **Extension point for N-5.**
- **`QueueBackendInterface`** (`Queue/QueueBackendInterface.php`): already carries `frequency` and
  `day` in the payload shape. Dedup key is already `(uid, mid, frequency, day)`. No schema change
  needed to the interface.
- **`MockQueueBackend`**: honors the payload as-is; no changes needed for storage.
- **`field_notification_frequency`** on user entity (`docs/groups/config/field.storage.user.*.yml`
  + `field.field.user.user.*.yml`): **already exists** with allowed values
  `immediately | daily | weekly` and default `immediately`. No new field YAML needed.
- **N-1 `DatabaseQueueBackend`**: WIP on local branch `229-n1-queue-foundation`, not on origin, no
  PR. Not blocking. When it lands, N-5 rebases if its schema needs new columns; but N-1's schema
  already needs to cover `frequency` (per interface) — the only N-5-specific addition is `send_at`.

## Reuse & Analogous-Feature map

- **Extend, don't fork:** `SubscriptionRouter::doRoute()` step 7 currently reads a single frequency
  string. Extend by injecting a new `FrequencyResolver` service that, given a recipient uid, returns
  `['frequency' => string, 'send_at' => int|null]`. Fold the resolver call into the per-uid loop.
- **Payload extension:** the payload gains an optional `send_at` key. Backends that don't need it
  (MockQueueBackend) ignore it. N-1's DatabaseQueueBackend will read it (this brief's contract).
- **No new interface / no new abstraction layer.** Just one new service (`FrequencyResolver`) and
  one new payload key.
- **Field is preexisting** — do NOT create a second frequency field. Read the existing
  `field_notification_frequency` on the user, falling back to `do_notifications.settings:default_frequency`
  when the user is unloadable or the field is empty (matches the field's default anyway).

## New objects (justified)

1. `Drupal\do_notifications\Frequency\FrequencyResolver` — resolves `(frequency, send_at)` for a
   given recipient uid. Not existing anywhere. Single responsibility, easily unit-testable, keeps
   `SubscriptionRouter` from growing a second concern.

## Forward-compat check

- **N-6 daily digest** (#234) will claim entries where `frequency = 'daily'` and
  `send_at <= NOW()`. This story's contract (`send_at = next 2 AM UTC`) is the exact query key
  N-6 needs. Confirmed.
- **N-7 weekly digest** (#235) same pattern with `frequency = 'weekly'` and `send_at = next Sunday
  7 PM UTC`. Confirmed.
- **N-3 delivery worker** (#231) will claim entries where `frequency = 'immediately'` and
  `send_at <= NOW()`. `send_at = current request time` satisfies the "send now" case.
- **N-1 schema** (#229): must include `frequency VARCHAR` and `send_at INT UNSIGNED NULL` columns.
  N-5 will pre-declare this expectation in the brief; when N-1 lands, either they inherit it, or
  the second-to-merge rebases + adds the column. Both are trivial.

## Test approach

- **RED kernel test** extends the existing `SubscriptionRouterTest` pattern (module list, setUp,
  drainQueue idiom). New test method(s) verify:
  1. Recipient with `frequency = immediately` → payload has `frequency='immediately'` and
     `send_at` = request time.
  2. Recipient with `frequency = daily` → `frequency='daily'`, `send_at` = next 2 AM UTC.
  3. Recipient with `frequency = weekly` → `frequency='weekly'`, `send_at` = next Sun 7 PM UTC.
  4. Recipient with empty/missing field → falls back to site-wide default (`immediately`).
  5. Three recipients with three different frequencies from one Message → three distinct payloads.
- **Unit test** (optional): FrequencyResolver in isolation, testing the boundary math (e.g. "at
  02:00 UTC exactly, the next 2AM is 24h later"; "on Sunday 19:00 UTC exactly, next Sun 7PM is
  a week later").

## Test time-freeze

`TimeInterface::getRequestTime()` is already injected into `SubscriptionRouter`. The kernel test
can inject a mock/stub Time service in the container to freeze "now" — but since kernel tests
allow setting a fixed request time via `TestTime` (or by container override), the simpler pattern
is to compute expected `send_at` values *from the actual request time the test sees*, using the
same helper `FrequencyResolver` uses. Avoids brittle wall-clock coupling.

## Files to touch

- NEW: `docs/groups/modules/do_notifications/src/Frequency/FrequencyResolver.php`
- MODIFY: `docs/groups/modules/do_notifications/src/Subscription/SubscriptionRouter.php`
  (inject FrequencyResolver; call per uid; add `send_at` to payload)
- MODIFY: `docs/groups/modules/do_notifications/src/Queue/QueueBackendInterface.php`
  (payload docblock: add `send_at` optional key)
- MODIFY: `docs/groups/modules/do_notifications/do_notifications.services.yml`
  (register `do_notifications.frequency_resolver`; add to router args)
- NEW: `docs/groups/modules/do_notifications/tests/src/Kernel/FrequencyRoutingTest.php`
  (or extend `SubscriptionRouterTest`; separate file keeps N-2 test focused on subscribe logic)
- OPTIONAL: `docs/groups/modules/do_notifications/tests/src/Unit/FrequencyResolverTest.php`

## Out of scope

- Schema for `DatabaseQueueBackend` (N-1's territory).
- Actual digest aggregation logic (N-6/N-7).
- Delivery worker (N-3).
- User-facing UI to change frequency (already exists — read-only display in
  `NotificationSettingsController`).
