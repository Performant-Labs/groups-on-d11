# Handoff-T-green: Phase 6 - #143 MC-5 Group archiving RESTORE action

**Date:** 2026-07-22
**Branch:** 143-archive-restore (worktree `_worktrees/groups-archive-restore`)
**Issue:** #143
**Handoff-F reviewed:** `docs/planning/handoffs/143-archive-restore/handoff-F.md`
**Handoff-T-red:** `docs/planning/handoffs/143-archive-restore/handoff-T-red.md`

## Round 2 — O adjudication applied

Round 1 of this phase reported BLOCKED: the e2e suite's AC-8 Step 1 asserted
`GET /group/{gid}/node/create` returns 403 for an archived group, but got 200. Root cause was
traced (not guessed) to a pre-existing, out-of-#143-scope gap: `drupal/group`'s
`_group_relationship_create_any_entity_access` access check never invokes `hook_node_access()`,
so `DoGroupExtrasHooks::nodeAccess()`'s Archive-branch denial — correct and Kernel-tested in
isolation — is unreachable from the real "Add new content" route. F touched none of the
implicated files (verified via `git log`, baseline-only).

O relayed the operator's ruling to this round: **(c) test tweak** — swap AC-8's precondition
assertion from the unenforced node-create-403 check to archived-state observables (badge + Restore
tab visibility) that ARE actually enforced end-to-end. No F code touched. No follow-up issue filed
(POC posture — this project does not spin up tracking issues for out-of-scope findings at this
tier).

**Spec change applied:** `tests/e2e/group-restore.spec.ts` Step 1 — removed the
`page.goto('/group/{gid}/node/create')` + `expect(status).toBe(403)` assertion pair; replaced with
`expect(page.locator('span.group__archived-badge')).toBeVisible()` (in addition to the existing
text-based `/Archived/i` assertion) alongside the existing Restore-tab-visible assertion. The
`span.group__archived-badge` selector is confirmed against
`docs/groups/modules/do_chrome/src/Hook/ArchivePinHooks.php:60-71` (`preprocessGroup()` renders
exactly this class on the real badge element). Step 5 (post-re-archive mirror) already asserted
only text + tab (no node-create check existed there); added the same `span.group__archived-badge`
locator assertion for symmetry with Step 1. Round-trip semantics preserved: archived observable
present -> restore removes it -> re-archive brings it back. A code comment marks the swap site and
explains the pre-existing gap for future readers (see file, Step 1).

## GREEN confirmation (PHPUnit tiers) — re-confirmed post-swap

**Kernel** (`GroupRestoreTest.php`):
```
ddev exec 'SIMPLETEST_DB="mysql://db:db@db/db" php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_group_extras/tests/src/Kernel/GroupRestoreTest.php'
```
```
 ✔ Archived group preconditions
 ⚠ Submit restores archived group   (pre-existing getOriginal() core deprecation, not a failure)
 ✔ Submit is no op when group no longer archived
 ✔ Build form refuses when no non archive term exists

Tests: 4, Assertions: 146, Deprecations: 2.
```
4/4 pass, zero failures.

**Functional** (`GroupRestoreAccessTest.php`):
```
ddev exec 'SIMPLETEST_DB="mysql://db:db@db/db" SIMPLETEST_BASE_URL="http://web" BROWSERTEST_OUTPUT_DIRECTORY="/tmp" php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_group_extras/tests/src/Functional/GroupRestoreAccessTest.php'
```
```
Tests: 10, Assertions: 59, Deprecations: 7, PHPUnit Deprecations: 11.
```
10/10 pass, zero failures (all `⚠` are pre-existing deprecation-only noise — Twig sandbox
signature, `RunTestsInSeparateProcesses`, `getOriginal()`; deprecation count differs slightly run
to run due to core deprecation-emission ordering, failure count is what matters and stays zero).

**Pre-existing suite, no-regression check** (`GroupExtrasBehaviorTest.php`, AC-7) — unaffected by
this round's spec-only change, previously confirmed 8/8 pass, zero failures. Not re-run this round
since neither this file nor any production code changed; the spec swap only touches
`tests/e2e/group-restore.spec.ts`.

Both PHPUnit tiers are unaffected by the e2e spec swap (different files entirely) — re-run here
purely as a paranoia check per the task instructions, confirming no regression crept in between
sessions. Matches prior round's counts exactly.

## E2E result — GREEN (after the observable-swap)

```
BASE_URL="https://gm143-groups-on-d11.ddev.site" npx playwright test tests/e2e/group-restore.spec.ts --reporter=list
```
```
Running 1 test using 1 worker

  ok 1 [chromium] › tests\e2e\group-restore.spec.ts:72:7 › Group archiving RESTORE action (#143 MC-5) › archive -> restore -> archive round-trip on the seeded Legacy Infrastructure group (8.6s)

  1 passed (9.4s)
```

Ran against the DDEV instance (`gm143-groups-on-d11`) left running + seeded from the prior session;
re-verified Legacy Infrastructure (gid=8, `status=0`) was still present via a direct SQL query
before running, so this is the same seeded fixture as round 1, not a fresh install.

**Spot-check — swapped assertions still pin real behavior:** the badge/tab assertions are not
vacuous. `ArchivePinHooks::preprocessGroup()` only attaches the `group__archived-badge` span and
the "Restore group" local task is only visible while `field_group_type` resolves to the "Archive"
term (confirmed by reading `ArchivePinHooks::isArchived()` and the pre-existing local-task access
callback) — so this assertion fails if the archived state (or F's restore/re-archive wiring) is
removed, exactly as the original node-create check would have, just through an enforced code path
instead of an unenforced one.

## Tier 2 results

- **phpstan:** no `phpstan.neon`/`phpstan.neon.dist` at repo root — skipped per instructions (not
  configured for this project).
- **phpcs** (`--standard=Drupal,DrupalPractice`): unaffected by this round's spec-only TS change;
  previously verified zero errors/zero warnings against F's two production files, unchanged since.
- **Test quality (this round's specific change):** the swap trades an assertion against an
  unenforced code path for one against an enforced code path — this is a strict quality
  improvement, not just a workaround. No test was deleted; the round-trip's 5-step structure and
  assertion count are materially unchanged (one assertion pair swapped, one assertion added for
  Step-5 symmetry). The suite remains proportionate: one e2e test covering the one full-stack
  round-trip AC-8 specifies, no duplication with Kernel/Functional tiers.
- **Test coverage vs. acceptance criteria:** unchanged from round 1 except AC-8 (see table below).

## Acceptance criteria status

| AC | Status | Backing test |
|----|--------|--------------|
| AC-1 (Organizer can restore) | PASS | `GroupRestoreAccessTest::testOrganizerCanRestore` |
| AC-2 (Groups-Moderate can restore) | PASS | `GroupRestoreAccessTest::testGroupsModerateCanAccessRestore` |
| AC-3 (site admin can restore) | PASS | `GroupRestoreAccessTest::testSiteAdminCanAccessRestore` |
| AC-4 (real `<button type=submit>`) | PASS | `GroupRestoreAccessTest::testConfirmFormRendersRealSubmitButton` + live-render spot-check (round 1) |
| AC-5 (403 non-privileged / non-archived) | PASS | `testUnprivilegedAuthenticatedUserGetsAccessDenied`, `testAnonymousGetsAccessDenied`, `testOrganizerGetsAccessDeniedOnNonArchivedGroup`, `testSiteAdminGetsAccessDeniedOnNonArchivedGroup` |
| AC-6 (`aria-describedby` wiring) | PASS | `testConfirmButtonAriaDescribedbyPointsToExistingId` + live-render spot-check (round 1) |
| AC-7 (no regression to existing archive enforcement) | PASS | `GroupExtrasBehaviorTest` 8/8 (round 1; unaffected by this round) |
| AC-8 (e2e round-trip) | **PASS (spec swap applied per O ruling c)** | `group-restore.spec.ts` — badge + Restore-tab visibility asserted pre-restore, absence asserted post-restore, return asserted post-re-archive |
| AC-9 (Kernel: field reassignment, badge, node_access neutral) | PASS (at the Kernel/hook-object layer) | `GroupRestoreTest::testSubmitRestoresArchivedGroup` |
| AC-10 (Functional persona matrix + redirect + message) | PASS | `GroupRestoreAccessTest` (10/10) |
| AC-11 (coordinate, don't edit seed scripts) | PASS | Confirmed no diff to either seed script this round either |

## Blocking issues

None.

## Advisory notes

- The Kernel/Functional suites' 4/4 + 10/10 pass counts match round 1 and F's self-reported
  combined run exactly — no regression introduced by the spec-only e2e change.
- `assemble-config.sh` should be documented (or patched) to be Windows/host-PHP-safe — it currently
  silently assumes a host `php` binary exists, which is not the case on this workstation. Not
  blocking (workaround via `ddev exec` is one-line), carried forward from round 1.

## Out-of-scope observations (suitable for verbatim PR-body inclusion)

While authoring and verifying #143's end-to-end test, this round's testing surfaced a real,
pre-existing gap unrelated to this feature: **archived groups in this site do not actually block
users from adding new content to them.** The "Archived" badge and the ability to restore a group
both work correctly and are fully tested. However, the separate mechanism that is supposed to stop
people from creating new content (documentation pages, events, etc.) inside an archived group does
not work on the actual page users would use to add that content — visiting an archived group and
clicking "Add new content" currently offers the same options as any normal group.

This is not something #143 introduced or touched — it predates this feature entirely and lives
inside the `drupal/group` contributed module's access-checking plumbing, which does not consult
this project's own archive-aware permission logic on that particular page. It is a pre-existing,
site-wide gap affecting every archived group, not specific to the group used in testing.

Per the project's proof-of-concept posture, no follow-up tracking issue was filed for this round;
it is recorded here for visibility so a human can decide whether/when it's worth addressing.

## Overall verdict: GREEN

Kernel (4/4), Functional (10/10), pre-existing AC-7 suite (8/8, round 1), and E2E (1/1, this round,
after the O-directed observable swap) are all green with zero regressions and zero blocking
issues. Tier 2 structural checks are clean. All 11 acceptance criteria PASS. Ready for U (UI
surface exists — the badge, Restore tab, and restore confirmation form are all interactive UI
elements a human should walk through live).
