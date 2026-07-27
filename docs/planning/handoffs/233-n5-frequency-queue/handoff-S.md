# Handoff-S: Phase 9 — N-5 Frequency Queue Logic (#233)

**Date:** 2026-07-26
**Branch:** 233-n5-frequency-queue
**Issue:** #233 (part of epic #237)
**Handoff-A reviewed:** `docs/planning/handoffs/233-n5-frequency-queue/handoff-A.md`
**Handoff-T-red reviewed:** `docs/planning/handoffs/233-n5-frequency-queue/handoff-T-red.md`
**Handoff-F reviewed:** `docs/planning/handoffs/233-n5-frequency-queue/handoff-F.md`
**Handoff-T-green reviewed:** `docs/planning/handoffs/233-n5-frequency-queue/handoff-T-green.md`

## Verdict

**PASS** — proceed to rebase + PR.

## Preconditions

- A precondition: PASS on the plan; 2 warn-level nudges both addressed by F (silent fail-soft, `send_at` NOT in dedup tuple documented in `QueueBackendInterface`).
- T-green precondition: zero blocking issues, 5/5 N-5 kernel tests green, 18/18 do_notifications regression green, 23/23 do_activity regression green, PHPCS clean on all edited files.
- Non-visual audit — no browser / visual-diff preconditions apply.

## Spec conformance (issue #233 + brief)

Reviewed the production source verbatim against the brief's design section and the 7 acceptance criteria. Implementation matches the brief exactly in structure (constructor shape, service registration, per-uid loop body, payload key set). The one algebraic simplification F applied to the weekly boundary formula ("this week's Sunday minus dow*86400, roll forward if not strictly after now" vs the brief's "add days_until_sunday days") produces the same instant — independently confirmed by the test suite's own `expectedSendAt()` helper (which uses the brief's literal framing) passing against F's implementation across all five tests, including the exact-boundary edge cases.

Immediate/daily/weekly UTC boundary math (`gmdate` + `strtotime … UTC`) is timezone-safe — no reliance on PHP's `date.timezone` INI. Boundary discipline (`>`, not `>=`) is present and asserted by `assertGreaterThan($now, $item['send_at'])` in both daily and weekly tests.

## Contract for downstream stories

- **N-1 (#229, schema):** brief's DB contract is docblock-only in this story (correctly deferred). `QueueBackendInterface` docblock explicitly instructs N-1 that `send_at` MUST be an indexed column and MUST NOT be part of any UNIQUE key. Contract is unambiguous.
- **N-3 (#231, delivery worker):** `frequency='immediately' AND send_at <= NOW()` claim query is directly supported — every immediate payload carries `send_at === request time` (verified by `testImmediateFrequencyStampsRequestTimeSendAt`).
- **N-6 (#234, daily digest) / N-7 (#235, weekly digest):** `send_at` semantics (strictly after now, at 02:00 UTC / Sun 19:00 UTC) match the digest workers' claim queries; the strictly-greater-than boundary guarantees a digest worker running exactly at boundary time will not pick up an entry stamped by an enqueue that landed at the same instant.
- Dedup tuple `(uid, mid, frequency, day)` unchanged — a user changing frequency mid-day gets a new entry (documented, acceptable per brief).

## Test quality (test-quality.md §7)

- **Per test:** each of the 5 tests names one behavior, is independent (own recipient / node / message per test), asserts on observable drained-payload shape rather than probing internals, and would fail for the right reason if the SUT contract broke (confirmed by the RED run's assertion messages matching the failure predicted by pre-#233 code).
- **Suite:** 5 tests for 5 named acceptance sub-criteria (4a–4e) — proportionate, no fan-out, no re-proof of `SubscriptionRouterTest`'s dedup/suppression/author-exclusion coverage.
- **Boundary conditions:** daily/weekly tests independently recompute the expected boundary in `expectedSendAt()` (pinning the CONTRACT, not the SUT's arithmetic) AND additionally assert `send_at > now`. Combined, they pin both the exact instant and the strict-inequality invariant.
- No "delete or merge" findings. No mock-shaped or tautological tests.

## Fail-soft correctness

`FrequencyResolver::resolveFrequency()` collapses three failure modes into `siteDefaultFrequency()`:
1. `\Throwable` on user storage load → catch → default.
2. `NULL` user (uid does not resolve) → default.
3. User has no `field_notification_frequency` OR field is empty → default.

`computeSendAt()`'s `match` uses `default => $now`, so any unrecognized frequency string (including a site default misconfigured to something other than immediately/daily/weekly) is treated as immediate — never throws. `testMissingFrequencyFieldFallsBackToSiteDefault` pins branch #3. Branches #1 and #2 are covered by inspection only (no test), which is acceptable at the POC-lean tier — the fallback is literal code duplication of #3's path.

## Backward compatibility

- `MockQueueBackend` unchanged; stores payloads verbatim, `send_at` flows through with zero code changes.
- `do_activity`'s `DoActivityHooks::__construct(?SubscriptionRouter $router = NULL)` still nullable; `do_activity.services.yml` still uses `@?do_notifications.subscription_router` (optional reference). Router's constructor arity change (7→6 args, dropping `ConfigFactoryInterface`, adding `FrequencyResolver`) is fully absorbed by the DI container — confirmed by do_activity's 23/23 kernel regression pass.
- No config schema change; `do_notifications.settings:default_frequency` semantics unchanged (still the fallback, now read by resolver instead of router).

## Code smells / advisory

None. Class docblocks cross-reference the brief. `computeSendAt`'s `default => $now` intentionally covers 'immediately' — F documented this decision in handoff-F. Constants `DAILY_SEND_HOUR` / `WEEKLY_SEND_HOUR` / `SECONDS_PER_DAY` are named and located sensibly.

## PHPCS

Spot-checked T-green's whole-module PHPCS report. 0 new errors, 0 new warnings on the four files this story touches. Pre-existing debt in unrelated files (`NotificationSettingsController.php`, `DoNotificationsHooks.php`, `GroupAddNotificationTest.php`, `EmailRendererTest.php`) is out of scope per POC lean rules.

## Acceptance criteria pass/fail

| # | Criterion | Result | Notes |
|---|---|---|---|
| 1 | `FrequencyResolver` under `src/Frequency/`, service registered | PASS | File + `do_notifications.frequency_resolver` in services.yml confirmed. |
| 2 | Router injects/uses resolver per recipient; no behavior change for all-default sites | PASS | Constructor 6 args (drops config.factory, adds resolver); per-uid loop confirmed; `testMissingFrequencyFieldFallsBackToSiteDefault` confirms default-only site behavior. |
| 3 | `send_at` populated on every enqueue | PASS | All 5 tests assert on non-null specific `send_at` value. |
| 4a | Immediate → `send_at` = request time | PASS | `testImmediateFrequencyStampsRequestTimeSendAt`. |
| 4b | Daily → next 2 AM UTC strictly after now | PASS | `testDailyFrequencyStampsNext2amUtc` + strict-inequality assertion. |
| 4c | Weekly → next Sun 19:00 UTC strictly after now | PASS | `testWeeklyFrequencyStampsNextSundayEveningUtc` + strict-inequality assertion. |
| 4d | Missing field → site default | PASS | `testMissingFrequencyFieldFallsBackToSiteDefault`. |
| 4e | Three recipients → three distinct payloads | PASS | `testMixedRecipientsProduceDistinctPayloads`. |
| 5 | `QueueBackendInterface` docblock documents `send_at` | PASS | Lines 51–59; includes dedup-tuple exclusion note. |
| 6 | `SubscriptionRouterTest`, `GroupAddNotificationTest` regression | PASS | 18/18 do_notifications + 23/23 do_activity kernel green. |
| 7 | PHPCS clean on new/edited files | PASS | 0 new findings; 1 pre-existing warning on `SubscriptionRouter.php:69` is documented and predates #233. |

## Ready for rebase + PR

No rework required. Proceed to rebase against `origin/main` and open PR with body `Closes #233. Part of epic #237.` per brief.
