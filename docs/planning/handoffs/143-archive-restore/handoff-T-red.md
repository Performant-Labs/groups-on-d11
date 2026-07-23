# Handoff-T-red: Phase 4 - #143 MC-5 Group archiving RESTORE action

**Date:** 2026-07-22
**Branch:** 143-archive-restore (worktree `_worktrees/groups-archive-restore`)
**Brief / wireframe reviewed:** `docs/planning/handoffs/143-archive-restore/brief.md`, `docs/planning/handoffs/143-archive-restore/wireframe.md`, `docs/planning/handoffs/143-archive-restore/handoff-A-plan.md`, `docs/planning/handoffs/143-archive-restore/handoff-A-plan-r2.md`

## A precondition

Confirmed: A returned **PASS** on the plan at Phase 3 round 2 (`handoff-A-plan-r2.md`) after the
one-line perm-string fix (`'edit group'` for the group-scope check) was folded into the brief.
Proceeding to author tests against the amended (post-fix) design.

## Environment setup performed (not production code)

This worktree had no running DDEV instance and no `vendor/`/`node_modules/` yet, so before any
test could run:
- Renamed `.ddev/config.yaml`'s project name from `pl-groups-on-d11` (collides with the main repo
  checkout's already-running project) to `gm143-groups-on-d11`, then `ddev start`.
- `ddev composer install` (vendor was missing).
- `npm ci` (node_modules/`@playwright/test` was missing).
- `bash scripts/ci/assemble-config.sh` run via `ddev exec` (no host PHP; the repo-root script
  shells out to `php` directly, which only exists inside the DDEV web container).
- Kernel/Functional runs require `SIMPLETEST_DB` (not exported by default in `ddev exec`); used
  `mysql://db:db@db/db` (DDEV's own DB container/credentials), matching the `SIMPLETEST_DB`
  convention `.github/workflows/test.yml` uses for CI, adapted to DDEV's service host name.

## Tests authored

### Kernel — `docs/groups/modules/do_group_extras/tests/src/Kernel/GroupRestoreTest.php`

Namespace `Drupal\Tests\do_group_extras\Kernel`, extends `GroupsKernelTestBase` (do_tests), same
fixture shape as `GroupExtrasBehaviorTest` (real `group_type` taxonomy vocabulary, "Archive" +
"Working group" terms, `field_group_type` entity-reference field on `community_group`).

- `testArchivedGroupPreconditions` — AC-9 before-state: `field_group_type` resolves to "Archive",
  `preprocess_group` emits `group--archived`, `node_access('create')` is forbidden. Kernel tier
  (pure hook + entity state, no HTTP). **Does not depend on `RestoreGroupForm` existing** — it
  pins the pre-existing enforcement surface the restore action must flip, so it can (and does)
  pass before F writes any code; it is the fixture's own precondition proof, not a RED for the
  new feature.
- `testSubmitRestoresArchivedGroup` — AC-5/AC-9: constructs `RestoreGroupForm` from the real
  container, calls `buildForm()`/`submitForm()` with a rigged `FormState` selecting "Working
  group", then asserts `field_group_type` is reassigned, `group--archived` disappears, and
  `node_access('create')` returns NEUTRAL (not forbidden). Kernel tier — cheapest sufficient tier
  to observe the field-reassignment + hook-visible-effect chain without a full HTTP round trip.
- `testSubmitIsNoOpWhenGroupNoLongerArchived` — race guard (wireframe Surface 2 / A r1 WARN-3):
  pre-set the group to non-Archive, invoke submit, assert no field change + a warning messenger
  entry. Kernel tier — pure state/messenger assertion.
- `testBuildFormRefusesWhenNoNonArchiveTermExists` — empty-vocab guard (wireframe Surface 3):
  delete the non-Archive term so only "Archive" remains, assert `buildForm()` returns a
  `#markup` block with no `group_type` select. Kernel tier.

### Functional — `docs/groups/modules/do_group_extras/tests/src/Functional/GroupRestoreAccessTest.php`

Extends `BrowserTestBase` (self-installs), modules `group, gnode, options, node, field, taxonomy,
do_group_extras, do_chrome`. Fixture: `community_group` group type, Organizer/member/
Groups-Moderate group roles (mirrors `ManageMembersRouteAccessTest`'s shape), `group_type`
vocabulary + Archive/Working-group terms, an archived test group and a non-archived test group.

- `testAnonymousGetsAccessDenied` — AC-3, anonymous → 403 on the archived group's restore route.
- `testUnprivilegedAuthenticatedUserGetsAccessDenied` — AC-3, authenticated non-privileged → 403.
- `testOrganizerCanRestore` — AC-1: Organizer → 200, submits the form, asserts redirect to
  canonical, success message, and the group is no longer Archive-typed.
- `testGroupsModerateCanAccessRestore` — AC-2: synchronized `groups_moderate` role → 200.
- `testSiteAdminCanAccessRestore` — site-admin (`administer group`) escape hatch → 200.
- `testOrganizerGetsAccessDeniedOnNonArchivedGroup` / `testSiteAdminGetsAccessDeniedOnNonArchivedGroup`
  — AC-3 amendment: any privilege level gets 403 on a non-archived group's restore route (single
  denial path, not 404).
- `testConfirmFormRendersRealSubmitButton` — AC-4: `button[type="submit"]` exists; a bare GET
  does not itself mutate the group.
- `testConfirmButtonAriaDescribedbyPointsToExistingId` — AC-6: the submit button's
  `aria-describedby` value resolves to a real `id` in the rendered DOM.
- `testCancelLinkGoesToGroupCanonical` — Cancel link href = group canonical.

All Functional tests hit the route over real HTTP — this is the tier that proves the route/access
wiring end to end, complementing the Kernel suite's isolated field/hook assertions.

### E2E — `tests/e2e/group-restore.spec.ts`

One `test.describe` with one full round-trip test, following AC-8's explicit 5-step sequence
against the real seeded site (Legacy Infrastructure, tagged Archive by `step_720`, untouched by
this story). Self-contained login helper (site-admin persona, matching `manage-members.spec.ts`'s
own precedent), a `findLegacyInfrastructureGid()` helper that resolves the group id via
`/all-groups` search rather than hard-coding a gid. Asserts the badge/tab visibility and
node-create gate before, the redirect/flash/badge-gone/tab-gone/node-create-allowed after restore,
and the badge/tab return after re-archiving via the existing group-edit Group Type widget.

## RED confirmation

**Kernel** (assembled layout, via DDEV):
```
ddev exec 'SIMPLETEST_DB="mysql://db:db@db/db" php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_group_extras/tests/src/Kernel/GroupRestoreTest.php'
```
```
Group Restore (Drupal\Tests\do_group_extras\Kernel\GroupRestore)
 ✔ Archived group preconditions
 ✘ Submit restores archived group
   │ Error: Class "Drupal\do_group_extras\Form\RestoreGroupForm" not found
 ✘ Submit is no op when group no longer archived
   │ Error: Class "Drupal\do_group_extras\Form\RestoreGroupForm" not found
 ✘ Build form refuses when no non archive term exists
   │ Error: Class "Drupal\do_group_extras\Form\RestoreGroupForm" not found

Tests: 4, Assertions: 136, Errors: 3.
```
The precondition test passes (proves the fixture/enforcement-surface baseline is real, not a
tautology); the three tests exercising the not-yet-written form fail on **class not found** —
the exact expected RED signal (missing `RestoreGroupForm`, not a setup/import/env error).

**Functional** (assembled layout, via DDEV):
```
ddev exec 'SIMPLETEST_DB="mysql://db:db@db/db" SIMPLETEST_BASE_URL="http://gm143-groups-on-d11.ddev.site" php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_group_extras/tests/src/Functional/GroupRestoreAccessTest.php'
```
```
 ✘ Anonymous gets access denied                              — 404, expected 403
 ✘ Unprivileged authenticated user gets access denied         — 404, expected 403
 ✘ Organizer can restore                                      — 404, expected 200
 ✘ Groups moderate can access restore                         — 404, expected 200
 ✘ Site admin can access restore                               — 404, expected 200
 ✘ Organizer gets access denied on non archived group          — 404, expected 403
 ✘ Site admin gets access denied on non archived group          — 404, expected 403
 ✘ Confirm form renders real submit button                    — button[type=submit] not found
 ✘ Confirm button aria describedby points to existing id       — button[type=submit] not found
 ✘ Cancel link goes to group canonical                        — no link containing href /group/1

Tests: 10, Assertions: 52, Failures: 10.
```
Every failure traces to the same root cause: route `do_group_extras.restore` does not exist yet,
so every request 404s (including the ones expecting 403/200 — this is the documented valid RED
signal per the brief: "404 on `/group/{gid}/restore` (route doesn't exist yet)"). No setup/env/
base-class error present. (3 unrelated core/contrib deprecation notices logged — Twig sandbox
policy signature, `RunTestsInSeparateProcesses` attribute, `getOriginal()` — pre-existing framework
noise, not caused by these tests, not blocking.)

**E2E** (syntax/list check only, per instructions — no live site to run against yet):
```
npx playwright test tests/e2e/group-restore.spec.ts --list
```
```
Listing tests:
  [chromium] › group-restore.spec.ts:65:7 › Group archiving RESTORE action (#143 MC-5) › archive -> restore -> archive round-trip on the seeded Legacy Infrastructure group
Total: 1 test in 1 file
```
Parses cleanly, lists the one test case — the RED marker for this tier (authored, not yet
runnable against a live site). Actual execution happens in T-green after F ships.

## Ready for F

Confirmed RED is valid at all three tiers:
- **Kernel:** missing-class RED (`RestoreGroupForm` not found) on the tests that exercise it; the
  precondition test passing on the real fixture proves the fixture itself is sound, not the source
  of the RED.
- **Functional:** route-not-registered RED (uniform 404 across every persona/behavior assertion).
- **E2E:** syntax-valid, not-yet-run (deferred to T-green).

F may implement against these tests: `RestoreGroupForm`, `RestoreGroupAccess`,
`do_group_extras.routing.yml`, `do_group_extras.links.task.yml`.
