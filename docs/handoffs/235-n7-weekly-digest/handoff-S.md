# Handoff-S: Phase 6 — #235 N-7 Weekly digest worker (spec audit)

**Date:** 2026-07-26
**Branch:** 235-n7-weekly-digest
**Issue:** #235
**Handoff-A reviewed:** `docs/handoffs/235-n7-weekly-digest/handoff-A.md`
**Handoff-T reviewed:** `docs/handoffs/235-n7-weekly-digest/handoff-T.md`
**Handoff-F reviewed:** `docs/handoffs/235-n7-weekly-digest/handoff-F.md`
**Decisions reviewed:** `docs/handoffs/235-n7-weekly-digest/decisions.md`
**Operator-facing report:** N/A (no visual surface — drush command + kernel-tested service)

## Preconditions

- **A precondition:** Confirmed — A returned PASS on the plan (Phase 3).
- **T precondition:** Confirmed — T RED valid (5 tests fail for the right reason, "undefined method"); T GREEN reports zero failures/errors across the full 50-test `do_notifications` kernel suite (`Tests: 50, Assertions: 1589, Deprecations: 22`).
- **Visual-diff tool:** N/A (no UI).

## AC coverage matrix

| AC | Requirement | Test that proves it | Status |
|---|---|---|---|
| AC1 | `drush do_notifications:digest-weekly` registered/callable | Every test in `WeeklyDigestCommandTest` invokes `\Drupal::service('do_notifications.digest_command')->digestWeekly()`; the `#[Command(name: 'do_notifications:digest-weekly')]` attribute on the method is present in `DigestCommands.php:167`. Service resolution + method invocation is proof of registration at the kernel level. | PASS |
| AC2 | 12 weekly items × 4 users older than 7d → 3 digest rows all `window='weekly'` | `testDigestWeeklyAggregatesPerUserAndConsumesSourceRows` (line 195) — 5+4+3=12, 3 digest rows, per-row `window='weekly'` asserted. | PASS (see note) |
| AC3 | 12 consumed source rows deleted | Same test, lines 284–300 (6 surviving rows by mid). | PASS |
| AC4 | Each user's subject reflects that user's fragment count | Same test, lines 268–281 (asserts user A's subject contains "5 updates"). | PASS |
| AC5 | Weekly items within 7d window NOT consumed | Same test, lines 228–232 fixture + line 300 survivor assertion; also independently proved in `testWindowSecondsStateOverrideNarrowsWindow`. | PASS |
| AC6 | `daily`/`immediately` rows older than 7d NOT consumed | Same test, lines 234–244 fixture + line 300 survivor assertion. Also proved at the queue tier by `testClaimWeeklyFiltersOnFrequencyAndThreshold`. | PASS |
| AC7 | User with zero in-window items → zero digest rows | `testUserWithNoInWindowItemsProducesNoDigestRow` (line 390) — dedicated isolated test. | PASS |
| AC8 | Returns `['users_digested','items_consumed','digests_enqueued']` | Integrated test, lines 253–257. | PASS |
| AC9 | `state.set('do_notifications.digest_weekly.window_seconds', N)` narrows window | `testWindowSecondsStateOverrideNarrowsWindow` (line 308). | PASS |
| AC10 | Orphan `mid` dropped from digest AND its own row deleted, without blocking valid messages | `testOrphanedMidIsDroppedAndDeletedWithoutBlockingOtherMessages` (line 342). | PASS |

**AC2 note (spec-wording drift, non-blocking):** The brief's AC2 says "12 weekly items across **4** users → exactly **4** rows." The implementation and test correctly aggregate 12 items across only **3** producing users (5/4/3), with the 4th user having zero items and producing zero digest rows — because AC7's "skip empty digests" rule overrides the naive "one row per user" reading. The test asserts 3 digest rows, which is the semantically correct behavior. The brief's AC2 phrasing was ambiguous (a "user with zero items" is one of the 4 users but produces no row). T made the right call resolving the ambiguity in favor of AC7. Recommend O optionally amend the brief's AC2 text post-hoc for the record; no code change.

## Reuse & duplication audit (A-dup surrogate)

Because the POC lean pipeline dropped A-dup, S runs the parallel-class / parallel-table / forked-interface check here:

- **No new command class.** `digestWeekly()` is a second `#[Command]`-attributed method on the existing `DigestCommands` — brief's explicit anti-pattern (`WeeklyDigestCommands`) was NOT committed.
- **No new queue backend.** `DatabaseQueueBackend::claimWeekly()` added inline; no `WeeklyDatabaseQueueBackend` fork.
- **No new digest queue table.** `DigestQueueBackendInterface` / `DatabaseDigestQueueBackend` / `do_notifications_digest_queue` reused verbatim (`window` column already accepted 'weekly' per N-6's own docblock). Confirmed by absence of edits in F's file list.
- **No forked interface.** `QueueBackendInterface::claimWeekly()` added as a sibling declaration alongside `claimDaily()`. No `WeeklyQueueBackendInterface` fork.
- **Intentional structural duplication of `digestUser` → `digestUserWeekly`.** ~70 lines of near-verbatim copy. F's handoff documents the decision: brief's Reuse map explicitly named `digestUserWeekly()` as the authorized shape; a shared `digestUserForWindow(string $window)` refactor would be un-spec'd. **S accepts this** — a parameterized merge is a legitimate future refactor once >2 windows exist (e.g. `monthly`), but forcing it now to eliminate one sibling would be premature abstraction. The duplicated helpers are two ~5-line inline comments away from being identical; a future refactor is cheap.

Verdict on duplication: no anti-pattern committed. The intentional structural duplication in `digestUser*()` is proportionate to a 2-window feature and documented.

## Quality audit

| Area | Result | Notes |
|------|--------|-------|
| API consistency | PASS | `digestWeekly()` return shape identical to `digestDaily()`; `claimWeekly()` signature/return identical to `claimDaily()`. |
| Error handling | PASS | Orphan-mid case handled (returns rows for GC without producing a digest row); null recipient case handled (rows still consumed). Same as N-6. |
| UI/UX match to spec | N/A | No UI. |
| Accessibility | N/A | No UI. |
| Architecture gate | PASS | A returned PASS; no new drift introduced by F. |
| Code organization | PASS | Sibling methods co-located; docblocks cross-reference. |
| Security | N/A | No new inputs, no new authz surface. |
| Performance | PASS | Uses same claim-then-delete pattern; `claimWeekly` uses indexed conditions on `frequency` + `created`. |
| Visual regression | N/A | No UI. |
| Naming consistency | PASS | `claimWeekly` / `digestWeekly` / `DEFAULT_WEEKLY_WINDOW_SECONDS` / `do_notifications:digest-weekly` / `do_notifications.digest_weekly.window_seconds` — every symbol mirrors the daily equivalent. |
| Test quality (`testing/test-quality.md` §7) | PASS | 5 tests, each pins one behavior at cheapest sufficient tier; AC7 correctly extracted to its own isolated test rather than only being incidental in the integrated scenario; no assertion-free/tautological/mock-shaped smells; test count proportionate to a mirror-of-N-6 feature. |

## Spec drift / scope check

- **No under-delivery.** All 10 ACs covered.
- **No over-delivery / scope creep.** Files changed are exactly the 4 the brief's Reuse map authorized. `DigestQueueBackendInterface`, `DatabaseDigestQueueBackend`, `DigestRenderer`, install file, services YAMLs untouched — as brief required.
- **Docblock drift (minor, non-blocking):** `QueueBackendInterface::claimDaily()`'s docblock still contains a forward-looking sentence: *"a future N-7 weekly digest worker will add its own `claimWeekly()` in the same idiom"* (line 127). That sibling now exists directly below in the same file. F's handoff claims docblocks were updated to reflect reality; this specific one was missed. Not blocking — it reads as historical rationale, not misleading — but worth a one-line cleanup in a future pass.

## phpcs environment gap

F flagged that bare `phpcs docs/groups/modules/do_notifications/{src,tests}` (no `--standard=`) exits 2 with ~5700 errors because the project has no root `phpcs.xml`/`phpcs.xml.dist`, so PHPCS falls back to its bundled PEAR default rather than the `drupal/coder`-installed `Drupal` standard the codebase actually follows. `phpcs --standard=Drupal` scoped to the 4 files this story touched: **exit 0, 0 errors, 0 warnings.**

S judgment: **genuine environment gap, correctly out of scope for N-7.** N-7's Reuse map authorized 4 file edits (2 queue methods + 1 command method + 1 helper); adding a project-root PHPCS ruleset would touch neither the notifications module nor any authorized file, and would risk surfacing 33+ pre-existing style errors in unrelated files (`NotificationSettingsController.php`, `DoNotificationsHooks.php`, etc.) that F would then have to either fix (unrelated scope) or waive (a separate policy decision). Correct call: flag it here, file a follow-up.

## Recommended follow-up issues (do NOT scope-crept into this PR)

1. **"Add project-root `phpcs.xml.dist` pinning the `Drupal` standard"** — small infra story. Scope: create `phpcs.xml.dist` at repo root referencing `Drupal`+`DrupalPractice`, exclude the ~33 pre-existing violations via a baseline or per-file rulesets, wire a `composer.json` script (`composer phpcs`), document in the workflow docs. Prevents every future story's "bare `phpcs` fails but that's not my code" recurrence.
2. **"Cleanup: refresh N-6 forward-looking docblock in `QueueBackendInterface::claimDaily()`"** — trivial one-line docs edit, batch with the next N-* story that touches the file. **Optional** — remembering the per-memory rule ("POC — no follow-ups for merged-story latent debt"), this is arguably not worth its own issue. Recommend leaving it un-filed and letting the next story that opens the file fix it opportunistically.

Recommendation #1 is worth filing (it will save time on every future story). Recommendation #2 per the memory rule, drop.

## Verdict

**PASS** — all 10 acceptance criteria are covered by tests (via the 4 `WeeklyDigestCommandTest` scenarios + `testClaimWeeklyFiltersOnFrequencyAndThreshold`), the full 50-test module suite is green, no anti-pattern (parallel class/table/interface) was committed, and the intentional structural duplication in `digestUserWeekly()` is documented and proportionate. Ready for O to open PR.

Non-blocking items for O's awareness (do NOT gate merge):
- AC2 wording ambiguity (brief says "4 rows"; implementation correctly produces 3 because AC7 overrides). Optionally amend brief post-hoc.
- One stale forward-looking sentence in `QueueBackendInterface::claimDaily()` docblock. Per POC memory, leave for opportunistic future cleanup.
- File follow-up issue for missing project-root `phpcs.xml.dist` (recurring papercut across every story).
