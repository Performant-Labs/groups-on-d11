# Handoff-T-green: Phase 6 - N-5 Frequency Queue Logic (#233)

**Date:** 2026-07-26
**Branch:** 233-n5-frequency-queue
**Issue:** #233
**Handoff-F reviewed:** `docs/planning/handoffs/233-n5-frequency-queue/handoff-F.md`
**Handoff-T-red:** `docs/planning/handoffs/233-n5-frequency-queue/handoff-T-red.md`

## GREEN confirmation

Config re-assembled fresh (not reused from F's run):

```
$ ddev exec bash scripts/ci/assemble-config.sh
==> assemble-config: repo root = /var/www/html
==> config: copied 139 file(s), excluded 7 env-specific file(s)
==> modules: copied 16 custom module(s) into web/modules/custom/
==> core.extension: registered custom do_* modules + flag/geofield/language/message/message_notify as enabled
==> assemble-config: done
```

N-5 suite re-run from scratch — **5/5 GREEN**, matches F's reported counts exactly:

```
$ ddev exec 'SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://web php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_notifications/tests/src/Kernel/FrequencyRoutingTest.php'

Frequency Routing (Drupal\Tests\do_notifications\Kernel\FrequencyRouting)
 - Immediate frequency stamps request time send at
 - Daily frequency stamps next 2am utc
 - Weekly frequency stamps next sunday evening utc
 - Missing frequency field falls back to site default
 - Mixed recipients produce distinct payloads

OK, but there were issues!
Tests: 5, Assertions: 232, Deprecations: 14.
```

0 Failures, 0 Errors. 232 assertions / 14 deprecations — identical to handoff-F's reported figures.
The 14 deprecations are the same pre-existing Drupal-core-vs-`flag`-contrib noise cross-checked in
handoff-T-red (attribute discovery, `trustData()`, `getOriginal()`, etc.) — none introduced by this
story.

**Spot-check: tests still fail if behavior is removed.** Read `FrequencyRoutingTest.php` in full.
Each test asserts on the real, drained `QueueBackendInterface` payload (`item['frequency']`,
`item['send_at']`) produced by a genuine `SubscriptionRouter::route()` call — not a mock of
`FrequencyResolver`. The daily/weekly tests independently recompute the expected boundary
(`expectedSendAt()` helper, reimplementing "next 2 AM UTC" / "next Sunday 19:00 UTC" from the
brief's prose, not by calling the SUT) — so a regression in `FrequencyResolver`'s arithmetic, or a
router that reverts to stamping one site-wide frequency on every payload, fails these tests for the
right reason. Confirmed by the RED run in `handoff-T-red.md`: before F's change, the identical
assertions failed with `'daily' !== 'immediately'` (wrong value) and `null !== <timestamp>`
(missing key) — proving these tests pin the behavior, not an implementation detail.

## Tier 1 results

| Check | Command | Expected | Actual | Result |
|---|---|---|---|---|
| Config assemble | `ddev exec bash scripts/ci/assemble-config.sh` | clean copy, no errors | 139 config files, 16 modules copied, no errors | PASS |
| N-5 suite (fresh) | `phpunit ... FrequencyRoutingTest.php` | 5/5 pass | 5/5 pass, 232 assertions | PASS |
| do_notifications full Kernel regression | `phpunit ... do_notifications/tests/src/Kernel/` | all pass | 18/18 pass, 788 assertions, 0 Failures/Errors | PASS |
| do_activity Kernel regression (router constructor-signature blast radius) | `phpunit ... do_activity/tests/src/Kernel/` | all pass | 23/23 pass, 759 assertions, 0 Failures/Errors | PASS |

## Tier 2 results

**Contract-drift spot check — `SubscriptionRouter.php`:**
- Constructor arg count: **6** — `EntityTypeManagerInterface`, `FlagServiceInterface`,
  `QueueBackendInterface`, `TimeInterface`, `StateInterface`, `FrequencyResolver`. Confirmed by
  direct read of the file (lines 43-50). Matches the brief's design (drops `ConfigFactoryInterface`,
  adds `FrequencyResolver`).
- `configFactory` is **not present** anywhere in `SubscriptionRouter.php` — confirmed via full file
  read (no `ConfigFactoryInterface` import, no constructor param, no property, no usage).
- **Services YAML arg count matches**: `do_notifications.subscription_router` in
  `do_notifications.services.yml` lists exactly 6 arguments in the same order as the constructor
  (`@entity_type.manager`, `@flag`, `@do_notifications.queue`, `@datetime.time`, `@state`,
  `@do_notifications.frequency_resolver`). PASS.
- `FrequencyResolver`'s own constructor legitimately keeps `ConfigFactoryInterface` (3 args:
  `EntityTypeManagerInterface`, `TimeInterface`, `ConfigFactoryInterface`) — per the brief's design,
  the resolver now owns the site-default-frequency config read that the router used to do directly.
  `do_notifications.frequency_resolver` in the services YAML matches (`@entity_type.manager`,
  `@datetime.time`, `@config.factory`). PASS.

**`FrequencyResolver.php` class docblock:** confirmed it documents the resolver's role (per-recipient
`(frequency, send_at)` resolution, fallback chain, fail-soft contract) and explicitly states the
"strictly greater than now" boundary rule is enforced by the two boundary-computation methods
(`nextDailyBoundary()` / `nextWeeklyBoundary()` docblocks state "strictly after `$now`" and the class
docblock cross-references the brief's boundary-discipline note). PASS.

**Test coverage vs acceptance criteria:** all 5 sub-criteria (4a-4e) have a 1:1 named test; AC 1-3,
5-7 are verified structurally below (no additional tests needed — they are either static contract
facts or already covered by the regression suites). No coverage gaps found.

**Test quality (test-quality.md §7):** each of the 5 tests names one behavior (immediate/daily/
weekly/fallback/multi-recipient), is independent (own recipient/node/message per test, no shared
mutable fixture state), asserts on observable payload shape rather than calling into
`FrequencyResolver` directly (so it fails if the SUT's contract breaks, not just its internals), and
sits at kernel tier justified by needing the real field/flag/queue stack. No redundant tests found
against the existing `SubscriptionRouterTest` (dedup/suppression/author-exclusion, untouched) or
`GroupAddNotificationTest`/`EmailRendererTest` (unrelated concerns). Suite size (5 tests) is
proportionate to the 5 acceptance sub-criteria — no ceiling violation.

**Type safety:** `FrequencyResolver.php` and the modified `SubscriptionRouter.php` are both
`declare(strict_types=1)`, fully typed constructor promotion, typed return signatures
(`resolve(int $uid): array`, `computeSendAt(string $frequency): int`, etc.). No `any`-equivalent
(mixed/untyped) leakage found in the diff.

**Error handling:** `FrequencyResolver::resolveFrequency()` fail-soft on (a) `\Throwable` during
user load, (b) `NULL` user, (c) missing/empty field — all three collapse to
`siteDefaultFrequency()`. `computeSendAt()` treats any unrecognized frequency string as
`immediately` via the `match` default arm (no throw). Matches the brief's "never fail routing on a
bad user" contract. Confirmed by `testMissingFrequencyFieldFallsBackToSiteDefault`.

**Data integrity:** dedup tuple `(uid, mid, frequency, day)` is unchanged; `send_at` is explicitly
documented as excluded from it in `QueueBackendInterface`'s docblock (handoff-A finding #2,
addressed). `MockQueueBackend` requires no change (stores `$item` verbatim). No schema migration in
this story (DB queue backend is N-1, not yet landed).

**API contract:** `QueueBackendInterface::enqueue()`'s docblock now documents `send_at` (optional
int key) with its semantics per frequency and the "NOT part of the dedup tuple" exclusion, co-located
in one bullet as F's handoff describes. Read in full — matches brief and A's finding.

**Security:** no new user input surface (uid is caller-supplied, already validated upstream by
`SubscriptionRouter`'s existing candidate-uid resolution; no direct request input reaches
`FrequencyResolver`). No auth-check surface change (service-layer only, no new route/controller/
form).

**Migration safety:** N/A — no schema change in this story (brief documents the DB contract for N-1
to inherit, not built here).

**Playwright:** N/A — no UI surface (brief and issue explicitly state NO D, NO U).

## Cross-check against handoff-F's reported results

F reported: N-5 suite 5/5, 232 assertions, 14 deprecations; full do_notifications regression 18/18,
788 assertions, 19 deprecations; PHPCS 0 errors/1 pre-existing warning on
`SubscriptionRouter.php:69`. My independent re-run (fresh assemble, not reusing F's environment
state) reproduces **all figures exactly** — no discrepancy.

Additionally ran do_activity's Kernel suite (not covered by F's own report) as the Tier-2 blast-radius
check for the constructor-signature change: **23/23 pass, 759 assertions, 0 Failures/Errors** — no
regression from the router losing its `ConfigFactoryInterface` dependency and gaining
`FrequencyResolver`.

## PHPCS status

Ran PHPCS on the whole `do_notifications` module (not just F's touched files), per instruction, to
catch accidental leaks:

```
$ ddev exec php vendor/bin/phpcs --standard=Drupal,DrupalPractice web/modules/custom/do_notifications/
```

Findings, by file:
- `SubscriptionRouter.php` — 0 errors, 1 warning (line 69, `\Drupal::logger()` in the catch-all).
  **Pre-existing** — confirmed via `git diff origin/main` showing this file IS in this story's diff,
  but the specific warning is the same one F documented as predating #233 (from N-2/#230); the line
  number shift (63→69) is only from added docblock lines, not new code triggering it.
- `FrequencyResolver.php` — 0 errors, 0 warnings (not listed in phpcs output = clean). Confirmed with
  a targeted re-run isolating just this file: no output, exit reflects only the other files' findings.
- `QueueBackendInterface.php` — 0 errors, 0 warnings (not listed = clean).
- `FrequencyRoutingTest.php` (new test file) — 0 errors, 0 warnings (not listed = clean).
- `NotificationSettingsController.php`, `DoNotificationsHooks.php`, `GroupAddNotificationTest.php`,
  `EmailRendererTest.php` — findings present (3 errors + 7 warnings, 1 error + 14 warnings, 4 errors
  + 7 warnings, 0 errors + 1 warning respectively), but **all four files have zero diff against
  `origin/main`** (confirmed via `git diff origin/main -- <files>` returning empty) — these are
  pre-existing findings from earlier stories (#232, #230), entirely outside #233's scope. No new
  issue introduced by this story.

**No NEW PHPCS errors or warnings anywhere in the files this story touched or added.**

PHPStan: no `phpstan.neon` (or any `phpstan*` config) exists at the repo root or nearby directories
— only the vendor package is installed, unconfigured. Skipped per instruction (no config to run
against).

## Acceptance criteria status

1. **`FrequencyResolver` exists, service registered** — PASS. File at
   `docs/groups/modules/do_notifications/src/Frequency/FrequencyResolver.php`;
   `do_notifications.frequency_resolver` registered in `do_notifications.services.yml`. Verified by
   direct read of both files.
2. **`SubscriptionRouter` injects/uses `FrequencyResolver` per recipient; no behavior change for
   all-default sites** — PASS, backed by `testMissingFrequencyFieldFallsBackToSiteDefault` (a
   default-frequency recipient gets the identical payload shape as before) and the `immediately` arm
   of `testMixedRecipientsProduceDistinctPayloads`.
3. **`send_at` populated on every enqueue** — PASS, backed by all 5 `FrequencyRoutingTest` tests
   (each asserts a non-null, specific `send_at` value).
4. **`FrequencyRoutingTest` covers 4a-4e** — PASS, all 5 tests present and green (see GREEN
   confirmation above).
5. **`QueueBackendInterface` docblock documents `send_at`** — PASS, verified by direct read (lines
   51-59), includes the dedup-tuple-exclusion note per handoff-A finding #2.
6. **`SubscriptionRouterTest` and `GroupAddNotificationTest` still pass** — PASS, confirmed in the
   full do_notifications regression run (18/18, includes both test classes plus `EmailRendererTest`).
7. **PHPCS clean on all new/edited files** — PASS, confirmed above (0 new errors/warnings; the one
   pre-existing warning on `SubscriptionRouter.php:69` predates this story).

## Blocking issues

None.

## Advisory notes

- F's weekly-boundary formula ("this week's Sunday minus day-of-week offset, roll forward if not
  strictly after now") is algebraically different from the brief's literal "add days-until-Sunday"
  framing but was independently verified equivalent by F via an empirical script (192 weekly checks,
  0 mismatches) — I did not re-run F's verification script myself, but the test suite's own
  independently-written `expectedSendAt()` helper (using the brief's literal framing) passing against
  F's implementation is itself an independent confirmation of equivalence across every case the test
  suite exercises, including the exact-boundary edge cases (18:59:59/19:00:00/19:00:01 UTC on a
  Sunday, per handoff-F). No action needed.
- The whole-module PHPCS run surfaced pre-existing debt (10 errors + 30 warnings across 4 files) from
  earlier stories (#230, #232) that is out of scope for #233 but worth a future cleanup pass —
  flagging for visibility only, not blocking this story.
