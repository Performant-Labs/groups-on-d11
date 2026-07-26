# Handoff-T-red: Phase 4 - N-5 Frequency Queue Logic (#233)

**Date:** 2026-07-26
**Branch:** 233-n5-frequency-queue
**Brief / wireframe reviewed:** `docs/planning/handoffs/233-n5-frequency-queue/brief.md`,
`docs/planning/handoffs/233-n5-frequency-queue/survey.md`,
`docs/planning/handoffs/233-n5-frequency-queue/handoff-A.md` (no wireframe — no UI surface).

## A precondition

Confirmed: A returned PASS on the plan (Phase 3). `handoff-A.md` verdict: PASS, with two
warn-level (non-blocking) documentation-precision notes for F (silent fail-soft on bad user load;
`send_at` NOT part of the dedup tuple, documented in `QueueBackendInterface`'s docblock).

## Tests authored

New file: `docs/groups/modules/do_notifications/tests/src/Kernel/FrequencyRoutingTest.php`
(kernel tier — the SUT is `FrequencyResolver` + `SubscriptionRouter` integration against a real
flag/message/field stack; nothing here is mockable without losing the actual routing + field-load
behavior, so kernel is the cheapest sufficient tier).

1. **testImmediateFrequencyStampsRequestTimeSendAt** — pins AC 4a: a recipient whose
   `field_notification_frequency = 'immediately'` gets a payload with `frequency='immediately'`
   and `send_at === $time->getRequestTime()`.
2. **testDailyFrequencyStampsNext2amUtc** — pins AC 4b: a `daily` recipient gets
   `frequency='daily'` and `send_at` = the next 02:00 UTC strictly after the request time. Expected
   value is computed independently in the test (`expectedSendAt()` helper, reimplementing the
   "next 2 AM UTC" boundary math from the brief) rather than delegated to `FrequencyResolver`, so
   the test pins the *contract*, not the SUT's own arithmetic. Also asserts `send_at > now`
   (boundary discipline per the brief: a worker at exactly 02:00 must not be triggered by
   yesterday's 02:00 enqueue).
3. **testWeeklyFrequencyStampsNextSundayEveningUtc** — pins AC 4c: same pattern for `weekly` →
   next Sunday 19:00 UTC strictly after now.
4. **testMissingFrequencyFieldFallsBackToSiteDefault** — pins AC 4d: a recipient with no value set
   on `field_notification_frequency` falls back to the site-wide `do_notifications.settings:
   default_frequency` (itself defaulting to `immediately`), producing the identical payload shape
   as an explicit `immediately` recipient.
5. **testMixedRecipientsProduceDistinctPayloads** — pins AC 4e: three recipients (one per
   frequency) following the same node, routed by a single `route()` call, produce exactly three
   payloads, each carrying its OWN `(frequency, send_at)` pair keyed by uid — proving routing is
   per-recipient, not a single site-wide value stamped on every entry (today's bug).

Each test is independent (own recipient/node/message), asserts on the drained queue payload
shape, and does not duplicate `SubscriptionRouterTest`'s dedup/suppression/author-exclusion
coverage — this suite is scoped purely to the frequency/send_at contract added by #233.

## RED confirmation

Command (from the assembled `web/` tree, inside DDEV — `gm233-freq` namespace):

```
ddev exec 'SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://web php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_notifications/tests/src/Kernel/FrequencyRoutingTest.php'
```

(Preceded by `ddev exec bash scripts/ci/assemble-config.sh` to sync `docs/groups/` sources into
`web/modules/custom/`.)

Result — **5 failures, 0 errors**, each failing on the specific behavioral assertion the feature
must satisfy (never on setup/import/autoload):

```
Frequency Routing (Drupal\Tests\do_notifications\Kernel\FrequencyRouting)
 ✘ Immediate frequency stamps request time send at
   │ send_at equals the request time for an immediate recipient.
   │ Failed asserting that null is identical to 1785108507.
   │ .../FrequencyRoutingTest.php:276

 ✘ Daily frequency stamps next 2am utc
   │ The daily recipient is stamped with frequency=daily.
   │ Failed asserting that two strings are identical.
   │ --- Expected
   │ +++ Actual
   │ @@ @@
   │ -'daily'
   │ +'immediately'

 ✘ Weekly frequency stamps next sunday evening utc
   │ The weekly recipient is stamped with frequency=weekly.
   │ Failed asserting that two strings are identical.
   │ --- Expected
   │ +++ Actual
   │ @@ @@
   │ -'weekly'
   │ +'immediately'

 ✘ Missing frequency field falls back to site default
   │ The fallback default (immediately) stamps send_at with the request time.
   │ Failed asserting that null is identical to 1785108523.
   │ .../FrequencyRoutingTest.php:371

 ✘ Mixed recipients produce distinct payloads
   │ uid 3's payload carries its own send_at.
   │ Failed asserting that null is identical to 1785108527.
   │ .../FrequencyRoutingTest.php:431

FAILURES!
Tests: 5, Assertions: 222, Failures: 5, Warnings: 3, Deprecations: 14.
```

This is the correct RED signal for the pre-implementation state: `SubscriptionRouter::doRoute()`
today reads one site-wide `default_frequency` for every recipient (`frequency` is always
`'immediately'`, matching the `daily`/`weekly` test failures above) and never sets a `send_at` key
at all (`null` in the `immediately`/missing-field/mixed-recipients failures). `FrequencyResolver`
does not exist yet — once F wires it in per the brief's design, these same assertions become the
GREEN contract.

The 14 deprecation notices (attribute-discovery / `trustData()` / `getOriginal()`) are pre-existing
Drupal-core-vs-`flag`-contrib-module noise, **not** introduced by this suite — cross-checked by
running the existing `SubscriptionRouterTest` (same module list, same fixtures) in the same
environment, which surfaces the identical 13-deprecation baseline while passing cleanly (`OK,
Tests: 5, Assertions: 244, Deprecations: 13`). The one extra deprecation here is
`getOriginal()`-related, attributable to `$user->set(...)->save()` reload cycles used by
`createUserWithFrequency()`; non-blocking, does not affect assertions.

**PHPCS:** clean — `ddev exec php vendor/bin/phpcs --standard=Drupal,DrupalPractice
web/modules/custom/do_notifications/tests/src/Kernel/FrequencyRoutingTest.php` exits 0, no errors
or warnings.

## Ready for F

Confirmed RED is valid; F may implement against this suite. F needs to:
1. Create `Drupal\do_notifications\Frequency\FrequencyResolver` (constructor:
   `EntityTypeManagerInterface`, `TimeInterface`, `ConfigFactoryInterface`; method
   `resolve(int $uid): array` returning `['frequency' => string, 'send_at' => int]`).
2. Register `do_notifications.frequency_resolver` in `do_notifications.services.yml` and inject it
   as `SubscriptionRouter`'s 7th constructor argument.
3. Replace `SubscriptionRouter::doRoute()`'s single-shot `$frequency` read with a per-uid call to
   `$this->frequencyResolver->resolve($uid)`, adding `send_at` to the enqueued payload.
4. Update `QueueBackendInterface`'s docblock per handoff-A's note 2 (`send_at` is NOT part of the
   dedup tuple).

## Setup surprises worth noting for F

- **`field_notification_frequency` fixture install pattern.** The shipped field YAMLs
  (`docs/groups/config/field.storage.user.field_notification_frequency.yml` +
  `field.field.user.user.field_notification_frequency.yml`) live outside any module's
  `config/install`, so `installConfig()` never picks them up. Per the flag-fixture pattern already
  established in `SubscriptionRouterTest`, this suite copies both YAMLs into
  `docs/groups/modules/do_notifications/tests/fixtures/config/` and installs them in `setUp()` via
  `FieldStorageConfig::create()` / `FieldConfig::create()` (load-or-create, mirroring the existing
  flag-loading loop).
- **`allowed_values` shape mismatch — load-bearing gotcha.** `FieldStorageConfig::create()`
  expects `settings.allowed_values` in the RUNTIME (simplified) shape
  `['immediately' => 'Immediately', 'daily' => 'Daily digest', ...]`, **not** the on-disk
  config-export shape `[['value' => 'immediately', 'label' => 'Immediately'], ...]` that the
  shipped YAML (and `config/sync`) actually uses. `Drupal\options\Plugin\Field\FieldType\
  ListItemBase::storageSettingsFromConfigData()` / `::storageSettingsToConfigData()` normally
  round-trip between these two shapes during real config import/export; a raw `::create()` call in
  a kernel test bypasses that round-trip and, if given the export shape directly, corrupts it via
  a double-`simplifyAllowedValues()`-style transform (each `{value, label}` row gets treated as its
  own value→label map and re-expanded into two malformed rows) — surfaces as
  `InvalidArgumentException: The configuration property settings.allowed_values.0.label.0 doesn't
  exist` on `->save()`. Fixed in this test's `setUp()` by converting the fixture YAML's structured
  `allowed_values` into the simplified map before calling `FieldStorageConfig::create()`. F does
  not need to touch this (it's test-only setup), but should NOT copy this test's fixture-loading
  pattern verbatim into any production seed/install code — production config import (`drush cim`)
  uses the correct on-disk shape natively; this conversion is only needed when constructing the
  entity directly via PHP in a kernel test.
- **DDEV project naming.** This worktree's `.ddev/config.yaml` had a stale project name
  (`gm139-multilang-rtl`, inherited from a prior worktree reuse) plus a leftover
  `.ddev/config.gm139.yaml` override and a stray `.ddev/traefik/config/gm139-multilang-rtl.yaml`
  that kept re-asserting the old name on `ddev start`. Renamed to `gm233-freq` and removed both
  leftovers so the container namespace matches the brief's `gm233-freq` directive. F should not
  need to repeat this (already fixed on this worktree), but should verify `ddev describe` still
  shows `gm233-freq` before running commands.
- **Kernel tests need `SIMPLETEST_DB` / `SIMPLETEST_BASE_URL` env vars** when invoked via
  `ddev exec` directly (not just `-c web/core/phpunit.xml.dist`) — see
  `scripts/dev/run-kernel.sh`'s `run_one()` for the canonical invocation:
  `ddev exec 'SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://web php vendor/bin/phpunit ...'`.
  Without these, PHPUnit errors before reaching any assertion ("There is no database connection").
